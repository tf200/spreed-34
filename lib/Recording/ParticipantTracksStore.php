<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Recording;

use InvalidArgumentException;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Stores the per participant speech chunks uploaded by the recording server.
 *
 * The recording server uploads a zip archive with a "manifest.json" and one
 * Ogg Opus file per chunk. The archive is validated and extracted to the app
 * data (outside the files of the users), in a folder per recording file, until
 * the chunks have been transcribed.
 */
class ParticipantTracksStore {
	private const FOLDER = 'recording-tracks';
	private const TRANSCRIPTS_FOLDER = 'recording-transcripts';
	private const MANIFEST = 'manifest.json';
	private const MAX_ARCHIVE_SIZE = 512 * 1024 * 1024;
	private const MAX_MANIFEST_SIZE = 4 * 1024 * 1024;
	private const MAX_CHUNK_SIZE = 64 * 1024 * 1024;
	private const MAX_ENTRIES = 2000;
	// Gemini Transcribe accepts up to 15 minutes per request with word timestamps.
	private const MAX_CHUNK_DURATION = 900;
	private const CHUNK_FILE_PATTERN = '/^chunk-([0-9]{1,10}-[0-9]{1,6})\.ogg$/';

	public function __construct(
		private readonly IAppData $appData,
	) {
	}

	/**
	 * Validates and stores the uploaded archive for the given recording.
	 *
	 * @param array $file Uploaded file as returned by IRequest::getUploadedFile()
	 * @throws InvalidArgumentException if the archive is not valid
	 */
	public function storeUploadedArchive(int $recordingFileId, array $file): void {
		if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
			|| !isset($file['tmp_name'])
			|| !is_uploaded_file($file['tmp_name'])) {
			throw new InvalidArgumentException('participant_tracks_invalid_file');
		}

		$this->storeArchive($recordingFileId, $file['tmp_name']);
	}

	/**
	 * @throws InvalidArgumentException if the archive is not valid
	 */
	public function storeArchive(int $recordingFileId, string $archivePath): void {
		if (!is_file($archivePath) || filesize($archivePath) > self::MAX_ARCHIVE_SIZE) {
			throw new InvalidArgumentException('participant_tracks_invalid_file');
		}

		$zip = new \ZipArchive();
		if ($zip->open($archivePath, \ZipArchive::RDONLY) !== true) {
			throw new InvalidArgumentException('participant_tracks_invalid_archive');
		}

		try {
			$chunkFiles = $this->validateEntries($zip);
			$manifestContent = $zip->getFromName(self::MANIFEST);
			if (!is_string($manifestContent)) {
				throw new InvalidArgumentException('participant_tracks_invalid_manifest');
			}
			$manifest = $this->validateManifest($manifestContent, $chunkFiles);

			$this->delete($recordingFileId);
			$folder = $this->getRootFolder()->newFolder((string)$recordingFileId);
			try {
				foreach ($manifest['chunks'] as $chunk) {
					$content = $zip->getFromName($chunk['file']);
					if (!is_string($content)) {
						throw new InvalidArgumentException('participant_tracks_invalid_archive');
					}
					$folder->newFile($chunk['file'], $content);
				}
				// The manifest is written last, so its presence means that all
				// the chunks were stored.
				$folder->newFile(self::MANIFEST, json_encode($manifest, JSON_THROW_ON_ERROR));
			} catch (\Throwable $e) {
				$folder->delete();
				throw $e;
			}
		} finally {
			$zip->close();
		}
	}

	public function has(int $recordingFileId): bool {
		try {
			return $this->getRootFolder()->getFolder((string)$recordingFileId)->fileExists(self::MANIFEST);
		} catch (NotFoundException) {
			return false;
		}
	}

	/**
	 * @return array{version: int, segments: list<array<string, mixed>>, chunks: list<array{id: string, segmentId: string, file: string, mimeType: string, duration: float, cutMap: list<array{chunkStart: float, trackStart: float, recordingStart: float, duration: float}>}>}
	 * @throws NotFoundException
	 */
	public function getManifest(int $recordingFileId): array {
		$content = $this->getRootFolder()->getFolder((string)$recordingFileId)->getFile(self::MANIFEST)->getContent();
		return json_decode($content, true, 16, JSON_THROW_ON_ERROR);
	}

	/**
	 * Sets the display names of the segments, for example the names of guests
	 * that are only known while they are in the conversation.
	 *
	 * @param array<string, string> $displayNames display names by segment id
	 * @throws NotFoundException
	 */
	public function setDisplayNames(int $recordingFileId, array $displayNames): void {
		$file = $this->getRootFolder()->getFolder((string)$recordingFileId)->getFile(self::MANIFEST);
		$manifest = json_decode($file->getContent(), true, 16, JSON_THROW_ON_ERROR);
		foreach ($manifest['segments'] as $index => $segment) {
			if (isset($displayNames[$segment['id']])) {
				$manifest['segments'][$index]['displayName'] = $displayNames[$segment['id']];
			}
		}
		$file->putContent(json_encode($manifest, JSON_THROW_ON_ERROR));
	}

	/**
	 * @throws NotFoundException
	 */
	public function getChunkContent(int $recordingFileId, string $chunkFile): string {
		if (preg_match(self::CHUNK_FILE_PATTERN, $chunkFile) !== 1) {
			throw new NotFoundException('Invalid chunk file name');
		}
		return $this->getRootFolder()->getFolder((string)$recordingFileId)->getFile($chunkFile)->getContent();
	}

	public function delete(int $recordingFileId): void {
		try {
			$this->getRootFolder()->getFolder((string)$recordingFileId)->delete();
		} catch (NotFoundException) {
			// Nothing stored
		}
	}

	/**
	 * Deletes the tracks stored before the given time.
	 *
	 * The tracks are deleted once processed, so only tracks of recordings that
	 * were never processed (or whose processing got stuck) are left.
	 *
	 * @return int the number of recordings whose tracks were deleted
	 */
	public function deleteStoredBefore(int $timestamp): int {
		$deleted = 0;
		foreach ($this->getRootFolder()->getDirectoryListing() as $folder) {
			if (!$folder instanceof ISimpleFolder) {
				continue;
			}
			try {
				$storedAt = $folder->getFile(self::MANIFEST)->getMTime();
			} catch (NotFoundException) {
				// An interrupted upload.
				$storedAt = 0;
			}
			if ($storedAt < $timestamp) {
				$folder->delete();
				$deleted++;
			}
		}
		return $deleted;
	}

	/**
	 * Stores the merged transcript (transcript.json v2) of the recording.
	 *
	 * It is kept after the audio is deleted, as it is the source of the
	 * rendered transcript.
	 *
	 * @param array<string, mixed> $document
	 */
	public function storeTranscriptDocument(int $recordingFileId, array $document): void {
		$folder = $this->getRootFolder(self::TRANSCRIPTS_FOLDER);
		$name = $recordingFileId . '.json';
		$content = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
		if ($folder->fileExists($name)) {
			$folder->getFile($name)->putContent($content);
		} else {
			$folder->newFile($name, $content);
		}
	}

	public function hasTranscriptDocument(int $recordingFileId): bool {
		return $this->getRootFolder(self::TRANSCRIPTS_FOLDER)->fileExists($recordingFileId . '.json');
	}

	public function deleteTranscriptDocument(int $recordingFileId): void {
		try {
			$this->getRootFolder(self::TRANSCRIPTS_FOLDER)->getFile($recordingFileId . '.json')->delete();
		} catch (NotFoundException) {
			// Nothing stored
		}
	}

	private function getRootFolder(string $name = self::FOLDER): ISimpleFolder {
		try {
			return $this->appData->getFolder($name);
		} catch (NotFoundException) {
			return $this->appData->newFolder($name);
		}
	}

	/**
	 * @return array<string, true> the chunk files in the archive
	 */
	private function validateEntries(\ZipArchive $zip): array {
		if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
			throw new InvalidArgumentException('participant_tracks_invalid_archive');
		}

		$chunkFiles = [];
		$hasManifest = false;
		for ($index = 0; $index < $zip->numFiles; $index++) {
			$stat = $zip->statIndex($index);
			if ($stat === false) {
				throw new InvalidArgumentException('participant_tracks_invalid_archive');
			}
			// The uncompressed size is checked before extracting anything.
			if ($stat['name'] === self::MANIFEST && $stat['size'] <= self::MAX_MANIFEST_SIZE) {
				$hasManifest = true;
			} elseif (preg_match(self::CHUNK_FILE_PATTERN, $stat['name']) === 1 && $stat['size'] <= self::MAX_CHUNK_SIZE) {
				$chunkFiles[$stat['name']] = true;
			} else {
				throw new InvalidArgumentException('participant_tracks_invalid_archive');
			}
		}

		if (!$hasManifest) {
			throw new InvalidArgumentException('participant_tracks_invalid_manifest');
		}
		return $chunkFiles;
	}

	/**
	 * Validates the manifest and keeps only the known fields.
	 *
	 * @param array<string, true> $chunkFiles
	 * @return array{version: int, segments: list<array<string, mixed>>, chunks: list<array<string, mixed>>}
	 */
	public function validateManifest(string $content, array $chunkFiles): array {
		try {
			$manifest = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new InvalidArgumentException('participant_tracks_invalid_manifest');
		}
		if (!is_array($manifest)
			|| ($manifest['version'] ?? null) !== 1
			|| !is_array($manifest['segments'] ?? null)
			|| !array_is_list($manifest['segments'])
			|| !is_array($manifest['chunks'] ?? null)
			|| !array_is_list($manifest['chunks'])) {
			throw new InvalidArgumentException('participant_tracks_invalid_manifest');
		}

		$segments = [];
		foreach ($manifest['segments'] as $segment) {
			$id = is_array($segment) ? ($segment['id'] ?? null) : null;
			if (!is_int($id) && !(is_string($id) && preg_match('/^[0-9]{1,10}$/', $id) === 1)) {
				throw new InvalidArgumentException('participant_tracks_invalid_manifest');
			}
			$id = (string)$id;
			$validated = ['id' => $id];
			foreach (['peerId', 'sessionId', 'actorType', 'actorId', 'userId', 'displayName'] as $field) {
				$value = $segment[$field] ?? null;
				if ($value !== null && (!is_string($value) || strlen($value) > ($field === 'sessionId' ? 1024 : 255))) {
					throw new InvalidArgumentException('participant_tracks_invalid_manifest');
				}
				$validated[$field] = $value === '' ? null : $value;
			}
			foreach (['recordingOffset'] as $field) {
				$validated[$field] = $this->isTime($segment[$field] ?? null) ? (float)$segment[$field] : null;
			}
			$validated['alignment'] = in_array($segment['alignment'] ?? null, ['xcorr', 'clock'], true) ? $segment['alignment'] : null;
			$segments[$id] = $validated;
		}

		$chunks = [];
		foreach ($manifest['chunks'] as $chunk) {
			if (!is_array($chunk)
				|| !is_string($chunk['id'] ?? null)
				|| ($chunk['file'] ?? null) !== 'chunk-' . $chunk['id'] . '.ogg'
				|| preg_match(self::CHUNK_FILE_PATTERN, $chunk['file']) !== 1
				|| !isset($chunkFiles[$chunk['file']])
				|| isset($chunks[$chunk['id']])
				|| !isset($segments[(string)($chunk['segmentId'] ?? '')])
				|| !$this->isTime($chunk['duration'] ?? null)
				|| (float)$chunk['duration'] > self::MAX_CHUNK_DURATION
				|| !is_array($chunk['cutMap'] ?? null)
				|| !array_is_list($chunk['cutMap'])
				|| $chunk['cutMap'] === []) {
				throw new InvalidArgumentException('participant_tracks_invalid_manifest');
			}

			$cutMap = [];
			$previousChunkStart = -1.0;
			foreach ($chunk['cutMap'] as $piece) {
				if (!is_array($piece)) {
					throw new InvalidArgumentException('participant_tracks_invalid_manifest');
				}
				foreach (['chunkStart', 'trackStart', 'recordingStart', 'duration'] as $field) {
					if (!$this->isTime($piece[$field] ?? null)) {
						throw new InvalidArgumentException('participant_tracks_invalid_manifest');
					}
				}
				if ((float)$piece['chunkStart'] <= $previousChunkStart) {
					throw new InvalidArgumentException('participant_tracks_invalid_manifest');
				}
				$previousChunkStart = (float)$piece['chunkStart'];
				$cutMap[] = [
					'chunkStart' => (float)$piece['chunkStart'],
					'trackStart' => (float)$piece['trackStart'],
					'recordingStart' => (float)$piece['recordingStart'],
					'duration' => (float)$piece['duration'],
				];
			}

			$chunks[$chunk['id']] = [
				'id' => $chunk['id'],
				'segmentId' => (string)$chunk['segmentId'],
				'file' => $chunk['file'],
				'mimeType' => 'audio/ogg',
				'duration' => (float)$chunk['duration'],
				'cutMap' => $cutMap,
			];
		}

		return [
			'version' => 1,
			'segments' => array_values($segments),
			'chunks' => array_values($chunks),
		];
	}

	private function isTime(mixed $value): bool {
		return (is_int($value) || is_float($value)) && is_finite((float)$value) && $value >= 0 && $value <= 7 * 24 * 3600;
	}
}
