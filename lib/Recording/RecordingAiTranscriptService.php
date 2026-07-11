<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Recording;

use OCA\Talk\Model\RecordingAiOperation;
use OCA\Talk\Service\RecordingService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

class RecordingAiTranscriptService {
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly GoogleTranscriptNormalizer $normalizer,
		private readonly RecordingService $recordingService,
	) {
	}

	public function store(RecordingAiOperation $operation): string {
		$response = json_decode((string)$operation->getSpeechResponse(), true, 64, JSON_THROW_ON_ERROR);
		$nodes = $this->rootFolder->getUserFolder($operation->getOwnerId())->getById($operation->getRecordingFileId());
		$recording = array_pop($nodes);
		if (!$recording instanceof File || !is_array($response)) {
			throw new GoogleApiException('Recording transcript input was invalid');
		}

		$timeline = null;
		try {
			$sidecar = $recording->getParent()->get($recording->getName() . '.speakers.json');
			if ($sidecar instanceof File) {
				$decoded = json_decode($sidecar->getContent(), true, 32, JSON_THROW_ON_ERROR);
				$timeline = is_array($decoded) ? $decoded : null;
			}
		} catch (NotFoundException|\JsonException) {
			// Diarized anonymous labels remain available without a valid sidecar.
		}

		$markdown = $this->normalizer->toMarkdown($response, $timeline);
		if ($markdown === '') {
			throw new GoogleApiException('Speech recognition returned an empty transcript');
		}
		$this->recordingService->storeTranscript(
			$operation->getOwnerId(),
			$operation->getRoomToken(),
			$operation->getRecordingFileId(),
			$markdown,
			'transcript',
			false,
		);
		return $markdown;
	}
}
