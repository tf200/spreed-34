<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Recording;

use OCA\Talk\Model\RecordingAiOperation;
use OCA\Talk\Model\RecordingAiOperationMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCA\Talk\Service\RecordingService;

class RecordingAiProcessor {
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
	) {
	}

	public function submit(int $operationId): void {
		$operation = $this->mapper->findById($operationId);
		if (!in_array($operation->getState(), [RecordingAiOperation::STATE_QUEUED, RecordingAiOperation::STATE_UPLOADING], true)) {
			return;
		}
		$nodes = $this->rootFolder->getUserFolder($operation->getOwnerId())->getById($operation->getRecordingFileId());
		$file = array_pop($nodes);
		if (!$file instanceof File) {
			$this->fail($operation, 'recording_missing', 'Recording file is no longer available');
			return;
		}

		$operation->setState(RecordingAiOperation::STATE_UPLOADING);
		$this->touch($operation);
		try {
			$object = $this->storage->upload($file, (int)$operation->getId());
			$operation->setGcsObject($object);
			$operation->setSpeechOperation($this->speech->submit($object));
			$operation->setState(RecordingAiOperation::STATE_TRANSCRIBING);
			$operation->setAttempts(0);
			$operation->setNextAttemptAt($this->later(60));
			$this->touch($operation);
		} catch (GoogleApiException) {
			$this->retryOrFail($operation, 'submission_failed');
		}
	}

	public function poll(RecordingAiOperation $operation): void {
		if ($operation->getState() !== RecordingAiOperation::STATE_TRANSCRIBING || $operation->getSpeechOperation() === null) {
			return;
		}
		try {
			$result = $this->speech->poll($operation->getSpeechOperation());
			if (!$result['done']) {
				$operation->setNextAttemptAt($this->later(120));
				$this->touch($operation);
				return;
			}
			if (isset($result['error'])) {
				$this->fail($operation, 'speech_failed', 'Speech recognition failed');
				$this->cleanup($operation);
				return;
			}
			$operation->setSpeechResponse(json_encode($result['response'], JSON_THROW_ON_ERROR));
			$operation->setState(RecordingAiOperation::STATE_MAPPING);
			$this->touch($operation);
			$this->cleanup($operation);
		} catch (GoogleApiException) {
			$this->retryOrFail($operation, 'poll_failed');
		}
	}

	public function map(RecordingAiOperation $operation): void {
		if ($operation->getState() !== RecordingAiOperation::STATE_MAPPING) {
			return;
		}
		try {
			$transcript = $this->transcriptService->store($operation);
			$operation->setSpeechResponse(null);
			if ($this->serverConfig->getAppValue('spreed', 'call_recording_summary', 'yes') === 'yes') {
				$operation->setTranscript($transcript);
				$operation->setState(RecordingAiOperation::STATE_SUMMARIZING);
				$operation->setNextAttemptAt($this->later(0));
			} else {
				$operation->setState(RecordingAiOperation::STATE_COMPLETED);
			}
			$this->touch($operation);
		} catch (\Throwable) {
			$this->fail($operation, 'mapping_failed', 'Transcript normalization or storage failed');
		}
	}

	public function summarize(RecordingAiOperation $operation): void {
		if ($operation->getState() !== RecordingAiOperation::STATE_SUMMARIZING || $operation->getTranscript() === null) {
			return;
		}
		try {
			$summary = $this->gemini->summarize($operation->getTranscript());
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
			$this->touch($operation);
		} catch (GoogleApiException) {
			$this->retryOrFail($operation, 'summary_failed');
		} catch (\Throwable) {
			$this->fail($operation, 'summary_store_failed', 'Summary storage failed');
		}
	}

	private function retryOrFail(RecordingAiOperation $operation, string $code): void {
		$attempts = $operation->getAttempts() + 1;
		$operation->setAttempts($attempts);
		if ($attempts >= 8 || $operation->getDeadlineAt() <= $this->timeFactory->getDateTime()) {
			$this->fail($operation, $code, 'Google processing retry limit exceeded');
			$this->cleanup($operation);
			return;
		}
		if ($operation->getState() !== RecordingAiOperation::STATE_SUMMARIZING) {
			$operation->setState($operation->getSpeechOperation() === null ? RecordingAiOperation::STATE_QUEUED : RecordingAiOperation::STATE_TRANSCRIBING);
		}
		$operation->setNextAttemptAt($this->later(min(1800, 60 * (2 ** $attempts))));
		$this->touch($operation);
	}

	private function fail(RecordingAiOperation $operation, string $code, string $message): void {
		$operation->setState(RecordingAiOperation::STATE_FAILED);
		$operation->setLastErrorCode($code);
		$operation->setLastErrorMessage($message);
		$this->touch($operation);
	}

	private function cleanup(RecordingAiOperation $operation): void {
		if ($operation->getGcsObject() !== null) {
			try {
				$this->storage->delete($operation->getGcsObject());
			} catch (GoogleApiException) {
				// Bucket lifecycle remains the cleanup safety net.
			}
		}
	}

	private function touch(RecordingAiOperation $operation): void {
		$operation->setUpdatedAt($this->timeFactory->getDateTime());
		$this->mapper->update($operation);
	}

	private function later(int $seconds): \DateTime {
		$date = $this->timeFactory->getDateTime();
		$date->modify('+' . $seconds . ' seconds');
		return $date;
	}
}
