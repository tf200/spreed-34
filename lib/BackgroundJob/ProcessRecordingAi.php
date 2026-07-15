<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\BackgroundJob;

use OCA\Talk\Model\RecordingAiOperationMapper;
use OCA\Talk\Recording\RecordingAiProcessor;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Override;
use Psr\Log\LoggerInterface;

class ProcessRecordingAi extends TimedJob {
	private const MAX_RUNTIME_SECONDS = 300;

	public function __construct(
		ITimeFactory $time,
		private readonly RecordingAiOperationMapper $mapper,
		private readonly RecordingAiProcessor $processor,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(60);
		$this->setAllowParallelRuns(false);
	}

	#[Override]
	protected function run($argument): void {
		$startedAt = $this->time->getTime();
		$processed = 0;
		foreach ($this->mapper->findDueIds($this->time->getDateTime()) as $operationId) {
			if ($processed > 0 && $this->time->getTime() - $startedAt >= self::MAX_RUNTIME_SECONDS) {
				break;
			}
			try {
				$this->processor->process($operationId);
			} catch (\Throwable $e) {
				$this->logger->error('Unexpected error while processing recording AI operation', [
					'operationId' => $operationId,
					'exception' => $e,
				]);
			}
			$processed++;
		}
	}
}
