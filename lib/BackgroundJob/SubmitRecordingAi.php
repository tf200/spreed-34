<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\BackgroundJob;

use OCA\Talk\Recording\RecordingAiProcessor;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Override;

class SubmitRecordingAi extends QueuedJob {
	public function __construct(ITimeFactory $time, private readonly RecordingAiProcessor $processor) {
		parent::__construct($time);
	}

	#[Override]
	protected function run($argument): void {
		$this->processor->submit((int)($argument['operationId'] ?? 0));
	}
}
