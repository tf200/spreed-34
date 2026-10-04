<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Recording;

use OCA\Talk\Model\RecordingAiOperation;
use OCA\Talk\Model\RecordingAiOperationMapper;
use OCA\Talk\Service\RecordingService;
use OCA\Talk\Service\RecordingSummaryTemplateService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class RecordingAiProcessor {
	private const MAX_TRANSITIONS = 4;
	private const UPLOAD_LEASE_SECONDS = 4500;
	private const STAGE_LEASE_SECONDS = 300;
	// A run starts new chunk requests during the budget, and a request can
	// take up to the timeout of GeminiTranscribeClient after that.
	private const TRACKS_LEASE_SECONDS = 600;
	private const TRACKS_BUDGET_SECONDS = 240;

	public function __construct(
		private readonly RecordingAiOperationMapper $mapper,
		private readonly IRootFolder $rootFolder,
		private readonly GoogleCloudStorageClient $storage,
		private readonly GoogleSpeechClient $speech,
		private readonly ITimeFactory $timeFactory,
		private readonly RecordingAiTranscriptService $transcriptService,
		private readonly GoogleGeminiClient $gemini,
		private readonly RecordingService $recordingService,
		private readonly IConfig $serverConfig,
		private readonly RecordingSummaryTemplateService $recordingSummaryTemplateService,
		private readonly MultitrackTranscriptService $multitrack,
		private readonly LoggerInterface $logger,
	) {
	}

	public function process(int $operationId): void {
		for ($transition = 0; $transition < self::MAX_TRANSITIONS; $transition++) {
			try {
				$operation = $this->mapper->findById($operationId);
			} catch (DoesNotExistException) {
				return;
			}

			$claimToken = $this->claim($operation);
			if ($claimToken === null) {
				return;
			}

			try {
				$operation = $this->mapper->findById($operationId);
			} catch (DoesNotExistException) {
				return;
			}

			if ($operation->getDeadlineAt() <= $this->timeFactory->getDateTime()) {
				$this->fail($operation, $claimToken, 'deadline_exceeded', 'Recording AI processing deadline exceeded');
				return;
			}

			$continue = match ($operation->getState()) {
				RecordingAiOperation::STATE_UPLOADING => $this->submit($operation, $claimToken),
				RecordingAiOperation::STATE_SUBMITTED => $this->resumeSubmitted($operation, $claimToken),
				RecordingAiOperation::STATE_TRANSCRIBING => $this->poll($operation, $claimToken),
				RecordingAiOperation::STATE_TRANSCRIBING_TRACKS => $this->transcribeTracks($operation, $claimToken),
				RecordingAiOperation::STATE_MERGING => $this->merge($operation, $claimToken),
				RecordingAiOperation::STATE_MAPPING => $this->map($operation, $claimToken),
				RecordingAiOperation::STATE_CLEANING => $this->clean($operation, $claimToken),
				RecordingAiOperation::STATE_SUMMARIZING => $this->summarize($operation, $claimToken),
				default => $this->invalidState($operation, $claimToken),
			};
			if (!$continue) {
				return;
			}
		}
	}

	private function submit(RecordingAiOperation $operation, string $claimToken): bool {
		if ($operation->getGcsObject() === null && $this->startTracks($operation)) {
			$operation->setState(RecordingAiOperation::STATE_TRANSCRIBING_TRACKS);
			$this->resetErrors($operation);
			$this->schedule($operation, 0);
			return $this->persist($operation, $claimToken);
		}

		try {
			$nodes = $this->rootFolder->getUserFolder($operation->getOwnerId())->getById($operation->getRecordingFileId());
			$file = array_pop($nodes);
			if (!$file instanceof File) {
				$this->fail($operation, $claimToken, 'recording_missing', 'Recording file is no longer available');
				return false;
			}

			$object = $operation->getGcsObject();
			if ($object === null) {
				$object = $this->storage->upload($file, (int)$operation->getId());
				$operation->setGcsObject($object);
				if (!$this->mapper->updateUploadCheckpoint(
					(int)$operation->getId(),
					$claimToken,
					$object,
					$this->timeFactory->getDateTime(),
				)) {
					return false;
				}
			}
			$operation->setSpeechOperation($this->speech->submit($object, $file->getMimeType()));
			$operation->setState(RecordingAiOperation::STATE_TRANSCRIBING);
			$this->resetErrors($operation);
			$this->schedule($operation, 60);
			$this->persist($operation, $claimToken);
		} catch (\Throwable $e) {
			$operation->setState($operation->getSpeechOperation() === null
				? RecordingAiOperation::STATE_QUEUED
				: RecordingAiOperation::STATE_TRANSCRIBING);
			$this->retryOrFail($operation, $claimToken, 'submission_failed', $this->getErrorMessage('Recording AI submission failed', $e));
		}
		return false;
	}

	/**
	 * Prepares the transcription of the participant tracks, if available.
	 *
	 * @return bool false if the mixed recording has to be transcribed instead
	 */
	private function startTracks(RecordingAiOperation $operation): bool {
		try {
			if (!$this->multitrack->isAvailable($operation)) {
				return false;
			}
			$this->multitrack->prepare($operation);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('Participant tracks can not be transcribed, using the mixed recording', ['exception' => $e]);
			try {
				$this->multitrack->cleanup($operation);
			} catch (\Throwable $e) {
				// The cleanup job deletes the tracks later.
				$this->logger->warning('Participant tracks could not be deleted', ['exception' => $e]);
			}
			return false;
		}
	}

	private function transcribeTracks(RecordingAiOperation $operation, string $claimToken): bool {
		try {
			$result = $this->multitrack->transcribePending($operation, self::TRACKS_BUDGET_SECONDS);
		} catch (\Throwable $e) {
			$this->retryOrFail($operation, $claimToken, 'track_transcription_failed', $this->getErrorMessage('Track transcription failed', $e));
			return false;
		}

		if ($result['failed'] > 0) {
			// A transcript with the words of a participant missing would be
			// misleading, so the mixed recording is transcribed instead.
			$this->logger->warning('Transcription of participant tracks failed, using the mixed recording', ['operationId' => $operation->getId()]);
			$this->multitrack->cleanup($operation);
			$operation->setState(RecordingAiOperation::STATE_QUEUED);
			$this->resetErrors($operation);
			$this->schedule($operation, 0);
			$this->persist($operation, $claimToken);
			return false;
		}

		if ($result['pending'] > 0) {
			$this->schedule($operation, $result['retrying'] > 0 ? 120 : 0);
			$operation->setLastErrorCode($result['retrying'] > 0 ? 'track_transcription_retrying' : null);
			$this->persist($operation, $claimToken);
			return false;
		}

		$operation->setState(RecordingAiOperation::STATE_MERGING);
		$this->resetErrors($operation);
		$this->schedule($operation, 0);
		return $this->persist($operation, $claimToken);
	}

	private function merge(RecordingAiOperation $operation, string $claimToken): bool {
		try {
			$result = $this->multitrack->merge($operation);
			if ($result['markdown'] === '') {
				$this->fail($operation, $claimToken, 'empty_transcript', 'No speech was transcribed');
				return false;
			}
			$this->multitrack->storeDocument($operation, $result['document']);
		} catch (\Throwable $e) {
			$this->retryOrFail($operation, $claimToken, 'merging_failed', $this->getErrorMessage('Merging the track transcripts failed', $e));
			return false;
		}

		$operation->setTranscript($result['markdown']);
		$operation->setState(RecordingAiOperation::STATE_CLEANING);
		$this->resetErrors($operation);
		$this->schedule($operation, 0);
		$persisted = $this->persist($operation, $claimToken);
		if ($persisted) {
			$this->multitrack->cleanup($operation);
		}
		return $persisted;
	}

	private function resumeSubmitted(RecordingAiOperation $operation, string $claimToken): bool {
		if ($operation->getSpeechOperation() === null) {
			$this->fail($operation, $claimToken, 'invalid_state', 'Submitted operation has no Speech operation');
			return false;
		}

		$operation->setState(RecordingAiOperation::STATE_TRANSCRIBING);
		$this->resetErrors($operation);
		$this->schedule($operation, 0);
		return $this->persist($operation, $claimToken);
	}

	private function poll(RecordingAiOperation $operation, string $claimToken): bool {
		if ($operation->getSpeechOperation() === null) {
			$this->fail($operation, $claimToken, 'invalid_state', 'Transcribing operation has no Speech operation');
			return false;
		}

		try {
			$result = $this->speech->poll($operation->getSpeechOperation());
			if (!$result['done']) {
				$this->resetErrors($operation);
				$this->schedule($operation, 120);
				$this->persist($operation, $claimToken);
				return false;
			}
			if (isset($result['error'])) {
				$this->fail($operation, $claimToken, 'speech_failed', 'Speech recognition failed');
				return false;
			}

			$operation->setSpeechResponse(json_encode($result['response'], JSON_THROW_ON_ERROR));
			$operation->setState(RecordingAiOperation::STATE_MAPPING);
			$operation->setSpeechOperation(null);
			$this->resetErrors($operation);
			$this->schedule($operation, 0);
			$persisted = $this->persist($operation, $claimToken);
			if ($persisted) {
				$this->cleanup($operation);
			}
			return $persisted;
		} catch (\Throwable $e) {
			$this->retryOrFail($operation, $claimToken, 'poll_failed', $this->getErrorMessage('Speech recognition polling failed', $e));
			return false;
		}
	}

	private function map(RecordingAiOperation $operation, string $claimToken): bool {
		if ($operation->getSpeechResponse() === null) {
			$this->fail($operation, $claimToken, 'invalid_state', 'Mapping operation has no Speech response');
			return false;
		}

		try {
			$transcript = $this->transcriptService->normalize($operation);
			$operation->setSpeechResponse(null);
			$operation->setTranscript($transcript);
			$operation->setState(RecordingAiOperation::STATE_CLEANING);
			$this->resetErrors($operation);
			$this->schedule($operation, 0);
			return $this->persist($operation, $claimToken);
		} catch (\Throwable $e) {
			$this->retryOrFail($operation, $claimToken, 'mapping_failed', $this->getErrorMessage('Transcript normalization failed', $e));
		}
		return false;
	}

	private function clean(RecordingAiOperation $operation, string $claimToken): bool {
		if ($operation->getTranscript() === null) {
			$this->fail($operation, $claimToken, 'invalid_state', 'Cleaning operation has no transcript');
			return false;
		}

		try {
			$transcript = $this->gemini->standardizeTranscript(
				$operation->getTranscript(),
				$this->multitrack->hasDocument($operation),
			);
			$this->transcriptService->store($operation, $transcript);
			$this->resetErrors($operation);
			if ($this->serverConfig->getAppValue('spreed', 'call_recording_summary', 'yes') === 'yes') {
				$operation->setTranscript($transcript);
				$operation->setState(RecordingAiOperation::STATE_SUMMARIZING);
				$this->schedule($operation, 0);
				return $this->persist($operation, $claimToken);
			}

			$operation->setTranscript(null);
			$operation->setState(RecordingAiOperation::STATE_COMPLETED);
			$this->persist($operation, $claimToken);
		} catch (GoogleApiException $e) {
			$this->retryOrFail($operation, $claimToken, 'transcript_cleanup_failed', $this->getErrorMessage('Transcript cleanup failed', $e));
		} catch (\Throwable $e) {
			$this->retryOrFail($operation, $claimToken, 'transcript_store_failed', $this->getErrorMessage('Cleaned transcript storage failed', $e));
		}
		return false;
	}

	private function summarize(RecordingAiOperation $operation, string $claimToken): bool {
		if ($operation->getTranscript() === null) {
			$this->fail($operation, $claimToken, 'invalid_state', 'Summarizing operation has no transcript');
			return false;
		}

		try {
			$snapshot = $this->recordingSummaryTemplateService->findSnapshot($operation->getRecordingFileId());
			$summary = $this->gemini->summarize($operation->getTranscript(), $snapshot['instructions']);
			$this->recordingService->storeTranscript(
				$operation->getOwnerId(),
				$operation->getRoomToken(),
				$operation->getRecordingFileId(),
				$summary,
				'summary',
				false,
			);
			$operation->setTranscript(null);
			$operation->setState(RecordingAiOperation::STATE_COMPLETED);
			$this->resetErrors($operation);
			$this->persist($operation, $claimToken);
		} catch (GoogleApiException $e) {
			$this->retryOrFail($operation, $claimToken, 'summary_failed', $this->getErrorMessage('Gemini summary request failed', $e));
		} catch (\Throwable $e) {
			$this->retryOrFail($operation, $claimToken, 'summary_store_failed', $this->getErrorMessage('Summary storage failed', $e));
		}
		return false;
	}

	private function retryOrFail(RecordingAiOperation $operation, string $claimToken, string $code, string $message): void {
		$attempts = $operation->getAttempts() + 1;
		$operation->setAttempts($attempts);
		$operation->setLastErrorCode($code);
		$operation->setLastErrorMessage($message);
		if ($attempts >= 8 || $operation->getDeadlineAt() <= $this->timeFactory->getDateTime()) {
			$this->fail($operation, $claimToken, $code, $message);
			return;
		}
		$this->schedule($operation, min(1800, 60 * (2 ** $attempts)));
		$this->persist($operation, $claimToken);
	}

	private function fail(RecordingAiOperation $operation, string $claimToken, string $code, string $message): void {
		$failedTask = $operation->getState() === RecordingAiOperation::STATE_SUMMARIZING || str_starts_with($code, 'summary_')
			? 'summary'
			: 'transcript';
		$operation->setState(RecordingAiOperation::STATE_FAILED);
		$operation->setLastErrorCode($code);
		$operation->setLastErrorMessage($message);
		$operation->setSpeechOperation(null);
		$operation->setSpeechResponse(null);
		$operation->setTranscript(null);
		if (!$this->persist($operation, $claimToken)) {
			return;
		}
		$this->cleanup($operation);
		try {
			$this->multitrack->cleanup($operation);
		} catch (\Throwable) {
			// The audio is removed with the recording at the latest.
		}
		try {
			$this->recordingService->notifyAboutFailedTranscript(
				$operation->getOwnerId(),
				$operation->getRoomToken(),
				$operation->getRecordingFileId(),
				$failedTask,
			);
		} catch (\Throwable) {
			// The persisted terminal state prevents duplicate processing.
		}
	}

	private function cleanup(RecordingAiOperation $operation): void {
		$gcsObject = $operation->getGcsObject();
		if ($gcsObject === null) {
			return;
		}
		try {
			$this->storage->delete($gcsObject);
			if ($this->mapper->clearGcsObject((int)$operation->getId(), $gcsObject)) {
				$operation->setGcsObject(null);
			}
		} catch (\Throwable) {
			// Bucket lifecycle remains the cleanup safety net.
		}
	}

	private function persist(RecordingAiOperation $operation, string $claimToken): bool {
		$operation->setUpdatedAt($this->timeFactory->getDateTime());
		return $this->mapper->updateClaimed($operation, $claimToken);
	}

	private function claim(RecordingAiOperation $operation): ?string {
		$now = $this->timeFactory->getDateTime();
		$claimUntil = clone $now;
		$claimToken = bin2hex(random_bytes(16));
		if (in_array($operation->getState(), [RecordingAiOperation::STATE_QUEUED, RecordingAiOperation::STATE_UPLOADING], true)) {
			$claimUntil->modify('+' . self::UPLOAD_LEASE_SECONDS . ' seconds');
			$claimed = $this->mapper->claimForUpload((int)$operation->getId(), $claimToken, $now, $claimUntil);
		} elseif ($operation->getState() === RecordingAiOperation::STATE_TRANSCRIBING_TRACKS) {
			$claimUntil->modify('+' . self::TRACKS_LEASE_SECONDS . ' seconds');
			$claimed = $this->mapper->claimForStage((int)$operation->getId(), $operation->getState(), $claimToken, $now, $claimUntil);
		} elseif (in_array($operation->getState(), [
			RecordingAiOperation::STATE_SUBMITTED,
			RecordingAiOperation::STATE_MERGING,
			RecordingAiOperation::STATE_TRANSCRIBING,
			RecordingAiOperation::STATE_MAPPING,
			RecordingAiOperation::STATE_CLEANING,
			RecordingAiOperation::STATE_SUMMARIZING,
		], true)) {
			$claimUntil->modify('+' . self::STAGE_LEASE_SECONDS . ' seconds');
			$claimed = $this->mapper->claimForStage((int)$operation->getId(), $operation->getState(), $claimToken, $now, $claimUntil);
		} else {
			return null;
		}

		return $claimed ? $claimToken : null;
	}

	private function invalidState(RecordingAiOperation $operation, string $claimToken): bool {
		$this->fail($operation, $claimToken, 'invalid_state', 'Recording AI operation has an invalid state');
		return false;
	}

	private function resetErrors(RecordingAiOperation $operation): void {
		$operation->setAttempts(0);
		$operation->setLastErrorCode(null);
		$operation->setLastErrorMessage(null);
	}

	private function later(int $seconds): \DateTime {
		$date = clone $this->timeFactory->getDateTime();
		$date->modify('+' . $seconds . ' seconds');
		return $date;
	}

	private function schedule(RecordingAiOperation $operation, int $seconds): void {
		$nextAttemptAt = $this->later($seconds);
		if ($nextAttemptAt > $operation->getDeadlineAt()) {
			$nextAttemptAt = clone $operation->getDeadlineAt();
		}
		$operation->setNextAttemptAt($nextAttemptAt);
	}

	private function getErrorMessage(string $prefix, \Throwable $exception): string {
		$message = preg_replace('/\s+/', ' ', $exception->getMessage()) ?? '';
		return substr($prefix . ($message !== '' ? ': ' . $message : ''), 0, 500);
	}
}
