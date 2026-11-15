<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\BackgroundJob;

use OCA\Talk\Model\RecordingAiOperation;
use OCA\Talk\Model\RecordingAiOperationMapper;
use OCA\Talk\Recording\GoogleAiConfig;
use OCA\Talk\Recording\ParticipantTracksStore;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\Files\IRootFolder;
use Override;
use Psr\Log\LoggerInterface;

/**
 * Deletes the audio of participant tracks once it is no longer needed.
 *
 * The tracks are kept after the transcription, so a recording can be
 * transcribed again from them: for some days after it succeeded, for more
 * days after it failed, and as long as it is in progress. They are deleted
 * right away when the recording itself was deleted, and after some days when
 * no transcription was started for them at all.
 */
class CleanupParticipantTracks extends TimedJob {
	/** Tracks without a transcription, for example with the AI processing disabled. */
	public const UNUSED_RETENTION_SECONDS = 2 * 24 * 3600;

	public function __construct(
		ITimeFactory $time,
		private readonly ParticipantTracksStore $tracksStore,
		private readonly RecordingAiOperationMapper $operationMapper,
		private readonly GoogleAiConfig $config,
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600 * 6);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	#[Override]
	protected function run($argument): void {
		$rejected = $this->tracksStore->getRejectedUploads();
		// The folders of the tracks can not be listed, so the candidates are
		// the marked tracks and the recordings that were (or are) transcribed.
		$candidates = array_unique(array_merge(
			$this->tracksStore->getMarkedTracks(),
			array_keys($rejected),
			$this->operationMapper->findAllRecordingFileIds(),
		));

		$deleted = 0;
		foreach ($candidates as $recordingFileId) {
			try {
				$tracksStoredAt = $this->tracksStore->getStoredAt($recordingFileId);
				if ($tracksStoredAt === null && !isset($rejected[$recordingFileId])) {
					continue;
				}
				$storedAt = max($tracksStoredAt ?? 0, $rejected[$recordingFileId] ?? 0);
				if (!$this->isExpired($recordingFileId, $storedAt)) {
					continue;
				}
				$this->tracksStore->delete($recordingFileId);
				$this->tracksStore->deleteRejectedUpload($recordingFileId);
				$deleted++;
			} catch (\Throwable $e) {
				$this->logger->warning('Participant tracks of recording ' . $recordingFileId . ' could not be cleaned up', ['exception' => $e]);
			}
		}

		if ($deleted > 0) {
			$this->logger->info('Deleted the participant tracks of ' . $deleted . ' recordings');
		}
	}

	private function isExpired(int $recordingFileId, int $storedAt): bool {
		$now = $this->time->getTime();
		try {
			$operation = $this->operationMapper->findByRecordingFileId($recordingFileId);
		} catch (DoesNotExistException) {
			return $storedAt < $now - self::UNUSED_RETENTION_SECONDS;
		}

		if (!$this->recordingExists($operation)) {
			return true;
		}

		$days = match ($operation->getState()) {
			RecordingAiOperation::STATE_COMPLETED => $this->config->getTracksRetentionCompletedDays(),
			RecordingAiOperation::STATE_FAILED,
			RecordingAiOperation::STATE_CANCELLED => $this->config->getTracksRetentionFailedDays(),
			// Still in progress
			default => null,
		};
		if ($days === null) {
			return false;
		}
		// Tracks imported again after the end of the transcription are kept
		// from then on too.
		$since = max($operation->getUpdatedAt()->getTimestamp(), $storedAt);
		return $since < $now - $days * 24 * 3600;
	}

	private function recordingExists(RecordingAiOperation $operation): bool {
		try {
			return $this->rootFolder->getUserFolder($operation->getOwnerId())->getFirstNodeById($operation->getRecordingFileId()) !== null;
		} catch (\Throwable) {
			// The owner was deleted
			return false;
		}
	}
}
