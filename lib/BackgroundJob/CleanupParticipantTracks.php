<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\BackgroundJob;

use OCA\Talk\Recording\ParticipantTracksStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Override;
use Psr\Log\LoggerInterface;

/**
 * Deletes the audio of participant tracks that was not processed in time.
 */
class CleanupParticipantTracks extends TimedJob {
	// Longer than the deadline of the transcription.
	public const RETENTION_SECONDS = 2 * 24 * 3600;

	public function __construct(
		ITimeFactory $time,
		private readonly ParticipantTracksStore $tracksStore,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600 * 6);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	#[Override]
	protected function run($argument): void {
		$deleted = $this->tracksStore->deleteStoredBefore($this->time->getTime() - self::RETENTION_SECONDS);
		if ($deleted > 0) {
			$this->logger->info('Deleted the unprocessed participant tracks of ' . $deleted . ' recordings');
		}
	}
}
