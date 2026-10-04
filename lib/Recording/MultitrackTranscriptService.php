<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Recording;

use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\RecordingAiChunk;
use OCA\Talk\Model\RecordingAiChunkMapper;
use OCA\Talk\Model\RecordingAiOperation;
use OCA\Talk\Service\ParticipantService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Transcribes the participant tracks of a recording and merges them in a
 * transcript.
 *
 * The transcript is stored as a JSON document (the source of truth, with word
 * times) and rendered as Markdown for the cleanup, the summary and the drafts.
 */
class MultitrackTranscriptService {
	public const MAX_CHUNK_ATTEMPTS = 5;

	public function __construct(
		private readonly ParticipantTracksStore $tracksStore,
		private readonly RecordingAiChunkMapper $chunkMapper,
		private readonly TrackTranscriptionProvider $provider,
		private readonly MultitrackTranscriptMerger $merger,
		private readonly GoogleAiConfig $config,
		private readonly Manager $manager,
		private readonly ParticipantService $participantService,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Whether the recording can be transcribed from its participant tracks.
	 */
	public function isAvailable(RecordingAiOperation $operation): bool {
		return $this->config->isMultitrackEnabled() && $this->tracksStore->has($operation->getRecordingFileId());
	}

	/**
	 * Creates the pending chunks of the operation, if not created yet.
	 */
	public function prepare(RecordingAiOperation $operation): void {
		$existing = array_map(static fn (RecordingAiChunk $chunk): string => $chunk->getChunkId(), $this->chunkMapper->findByOperation((int)$operation->getId()));
		$manifest = $this->tracksStore->getManifest($operation->getRecordingFileId());
		foreach ($manifest['chunks'] as $chunkInfo) {
			if (in_array($chunkInfo['id'], $existing, true)) {
				continue;
			}
			$chunk = new RecordingAiChunk();
			$chunk->setOperationId((int)$operation->getId());
			$chunk->setChunkId($chunkInfo['id']);
			$chunk->setState(RecordingAiChunk::STATE_PENDING);
			$chunk->setUpdatedAt($this->timeFactory->getDateTime());
			$this->chunkMapper->insert($chunk);
		}
	}

	/**
	 * Transcribes pending chunks until the time budget is used.
	 *
	 * A new chunk is only started while there is time left, so a run takes at
	 * most the budget plus the duration of one request.
	 *
	 * @return array{pending: int, failed: int, retrying: int} retrying are the
	 *                                                         pending chunks whose last attempt failed
	 */
	public function transcribePending(RecordingAiOperation $operation, int $budgetSeconds): array {
		$startedAt = $this->timeFactory->getTime();
		$manifest = $this->tracksStore->getManifest($operation->getRecordingFileId());
		$chunkInfos = array_column($manifest['chunks'], null, 'id');

		$pending = 0;
		$failed = 0;
		$retrying = 0;
		foreach ($this->chunkMapper->findByOperation((int)$operation->getId()) as $chunk) {
			if ($chunk->getState() === RecordingAiChunk::STATE_FAILED) {
				$failed++;
				continue;
			}
			if ($chunk->getState() !== RecordingAiChunk::STATE_PENDING) {
				continue;
			}
			$chunkInfo = $chunkInfos[$chunk->getChunkId()] ?? null;
			if ($chunkInfo === null) {
				$this->markFailed($chunk, 'Chunk missing in the manifest');
				$failed++;
				continue;
			}
			if ($this->timeFactory->getTime() - $startedAt >= $budgetSeconds) {
				$pending++;
				continue;
			}

			try {
				$words = $this->provider->transcribe(
					$this->tracksStore->getChunkContent($operation->getRecordingFileId(), $chunkInfo['file']),
					$chunkInfo['mimeType'],
				);
				$chunk->setWords(json_encode($words, JSON_THROW_ON_ERROR));
				$chunk->setState(RecordingAiChunk::STATE_DONE);
				$chunk->setLastErrorMessage(null);
				$chunk->setUpdatedAt($this->timeFactory->getDateTime());
				$this->chunkMapper->update($chunk);
			} catch (\Throwable $e) {
				$chunk->setAttempts($chunk->getAttempts() + 1);
				$message = substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 500);
				$this->logger->warning('Transcription of recording chunk failed', [
					'operationId' => $operation->getId(),
					'chunkId' => $chunk->getChunkId(),
					'attempt' => $chunk->getAttempts(),
					'exception' => $e,
				]);
				if ($chunk->getAttempts() >= self::MAX_CHUNK_ATTEMPTS) {
					$this->markFailed($chunk, $message);
					$failed++;
				} else {
					$chunk->setLastErrorMessage($message);
					$chunk->setUpdatedAt($this->timeFactory->getDateTime());
					$this->chunkMapper->update($chunk);
					$pending++;
					$retrying++;
				}
			}
		}

		return ['pending' => $pending, 'failed' => $failed, 'retrying' => $retrying];
	}

	private function markFailed(RecordingAiChunk $chunk, string $message): void {
		$chunk->setState(RecordingAiChunk::STATE_FAILED);
		$chunk->setLastErrorMessage($message);
		$chunk->setUpdatedAt($this->timeFactory->getDateTime());
		$this->chunkMapper->update($chunk);
	}

	/**
	 * Merges the transcribed chunks.
	 *
	 * @return array{markdown: string, document: array<string, mixed>}
	 */
	public function merge(RecordingAiOperation $operation): array {
		$manifest = $this->tracksStore->getManifest($operation->getRecordingFileId());
		$chunkInfos = array_column($manifest['chunks'], null, 'id');
		$speakers = $this->getSpeakers($operation, $manifest['segments']);
		$speakerBySegment = [];
		foreach ($manifest['segments'] as $segment) {
			$speakerBySegment[$segment['id']] = $this->getSpeakerId($segment);
		}

		$wordsBySpeaker = [];
		foreach ($this->chunkMapper->findByOperation((int)$operation->getId()) as $chunk) {
			$chunkInfo = $chunkInfos[$chunk->getChunkId()] ?? null;
			if ($chunk->getState() !== RecordingAiChunk::STATE_DONE || $chunkInfo === null) {
				continue;
			}
			$words = json_decode((string)$chunk->getWords(), true, 8, JSON_THROW_ON_ERROR);
			$speaker = $speakerBySegment[$chunkInfo['segmentId']];
			$wordsBySpeaker[$speaker] = array_merge(
				$wordsBySpeaker[$speaker] ?? [],
				$this->merger->toRecordingTime($words, $chunkInfo['cutMap']),
			);
		}

		$turns = $this->merger->merge($wordsBySpeaker);
		$usedSpeakers = array_flip(array_column($turns, 'speaker'));
		$document = [
			'version' => 2,
			'recordingFileId' => $operation->getRecordingFileId(),
			'language' => $this->config->getTranscriptionConfig()['language'],
			'provider' => ['name' => $this->provider->getName(), 'model' => $this->provider->getModel()],
			'speakers' => array_values(array_filter($speakers, static fn (array $speaker): bool => isset($usedSpeakers[$speaker['id']]))),
			'turns' => array_map(static fn (int $index, array $turn): array => ['id' => $index + 1] + $turn, array_keys($turns), $turns),
		];

		return ['markdown' => $this->renderMarkdown($document), 'document' => $document];
	}

	/**
	 * Whether the transcript of the operation was merged from participant
	 * tracks, so its speakers are exact.
	 */
	public function hasDocument(RecordingAiOperation $operation): bool {
		return $this->tracksStore->hasTranscriptDocument($operation->getRecordingFileId());
	}

	public function storeDocument(RecordingAiOperation $operation, array $document): void {
		$this->tracksStore->storeTranscriptDocument($operation->getRecordingFileId(), $document);
	}

	/**
	 * Deletes the audio and the chunk results once they are no longer needed.
	 */
	public function cleanup(RecordingAiOperation $operation): void {
		$this->tracksStore->delete($operation->getRecordingFileId());
		$this->chunkMapper->deleteByOperation((int)$operation->getId());
	}

	/**
	 * @param array<string, mixed> $document
	 */
	public function renderMarkdown(array $document): string {
		$names = array_column($document['speakers'], 'displayName', 'id');
		$long = $document['turns'] !== [] && max(array_column($document['turns'], 'start')) >= 3600;
		$blocks = [];
		foreach ($document['turns'] as $turn) {
			$seconds = (int)floor($turn['start']);
			$time = $long
				? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
				: sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
			$blocks[] = sprintf("**%s** · %s\n%s", $this->escape($names[$turn['speaker']] ?? $turn['speaker']), $time, $this->escape($turn['text']));
		}
		return implode("\n\n", $blocks);
	}

	/** @param array<string, mixed> $segment */
	private function getSpeakerId(array $segment): string {
		if (is_string($segment['actorType'] ?? null) && is_string($segment['actorId'] ?? null)) {
			return 'actor:' . $segment['actorType'] . ':' . $segment['actorId'];
		}
		if (is_string($segment['sessionId'] ?? null)) {
			return 'session:' . $segment['sessionId'];
		}
		if (is_string($segment['peerId'] ?? null)) {
			return 'peer:' . $segment['peerId'];
		}
		return 'segment:' . $segment['id'];
	}

	/**
	 * @param list<array<string, mixed>> $segments
	 * @return array<string, array{id: string, actorType: ?string, actorId: ?string, displayName: string}>
	 */
	private function getSpeakers(RecordingAiOperation $operation, array $segments): array {
		try {
			$room = $this->manager->getRoomByToken($operation->getRoomToken());
		} catch (RoomNotFoundException) {
			$room = null;
		}

		$speakers = [];
		$unknown = 0;
		foreach ($segments as $segment) {
			$id = $this->getSpeakerId($segment);
			$name = is_string($segment['displayName'] ?? null) ? trim($segment['displayName']) : '';
			if ($name === ($segment['actorId'] ?? null)) {
				// An id is not a name to show in the transcript.
				$name = '';
			}
			if (isset($speakers[$id]) && ($name === '' || $speakers[$id]['displayName'] !== '')) {
				continue;
			}

			$actorType = $segment['actorType'] ?? null;
			$actorId = $segment['actorId'] ?? null;
			if ($name === '' && $room !== null && is_string($actorType) && is_string($actorId)) {
				try {
					$name = trim($this->participantService->getParticipantByActor($room, $actorType, $actorId)->getAttendee()->getDisplayName());
				} catch (ParticipantNotFoundException) {
					// The participant left, a generic name is used below.
				}
			}

			$speakers[$id] = [
				'id' => $id,
				'actorType' => is_string($actorType) ? $actorType : null,
				'actorId' => is_string($actorId) ? $actorId : null,
				'displayName' => $name,
			];
		}

		foreach ($speakers as $id => $speaker) {
			if ($speaker['displayName'] === '') {
				$unknown++;
				$speakers[$id]['displayName'] = in_array($speaker['actorType'], [Attendee::ACTOR_GUESTS, Attendee::ACTOR_EMAILS], true)
					? $this->l->t('Guest %d', [$unknown])
					: $this->l->t('Participant %d', [$unknown]);
			}
		}

		return $speakers;
	}

	private function escape(string $value): string {
		return str_replace(['\\', '*', '_', '`'], ['\\\\', '\\*', '\\_', '\\`'], $value);
	}
}
