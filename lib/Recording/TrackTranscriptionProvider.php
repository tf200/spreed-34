<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Recording;

/**
 * Transcribes the audio of a single participant with word timestamps.
 */
interface TrackTranscriptionProvider {
	public function getName(): string;

	public function getModel(): string;

	/**
	 * @param string $audio Audio content of a chunk with a single speaker
	 * @return list<array{text: string, start: float, end: float}> the words,
	 *                                                             with times in seconds since the start of the chunk
	 * @throws GoogleApiException
	 */
	public function transcribe(string $audio, string $mimeType): array;
}
