<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\BackgroundJob;

use OCA\Talk\Model\RecordingAiOperation;
use OCA\Talk\Model\RecordingAiOperationMapper;
use OCA\Talk\Recording\RecordingAiProcessor;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Override;

class ProcessRecordingAi extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private readonly RecordingAiOperationMapper $mapper,
		private readonly RecordingAiProcessor $processor,
	) {
		parent::__construct($time);
		$this->setInterval(60);
	}

	#[Override]
	protected function run($argument): void {
		foreach ($this->mapper->findDue($this->time->getDateTime()) as $operation) {
			if (in_array($operation->getState(), [RecordingAiOperation::STATE_QUEUED, RecordingAiOperation::STATE_UPLOADING], true)) {
				$this->processor->submit((int)$operation->getId());
			} elseif ($operation->getState() === RecordingAiOperation::STATE_MAPPING) {
				$this->processor->map($operation);
			} elseif ($operation->getState() === RecordingAiOperation::STATE_SUMMARIZING) {
				$this->processor->summarize($operation);
			} else {
				$this->processor->poll($operation);
			}
		}
	}
}
