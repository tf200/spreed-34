<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\BackgroundJob;

use OCA\Talk\BackgroundJob\ProcessRecordingAi;
use OCA\Talk\BackgroundJob\SubmitRecordingAi;
use OCA\Talk\Model\RecordingAiOperationMapper;
use OCA\Talk\Recording\RecordingAiProcessor;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class RecordingAiJobsTest extends TestCase {
	public function testSubmitJobUsesUnifiedProcessor(): void {
		$timeFactory = $this->createMock(ITimeFactory::class);
		$processor = $this->createMock(RecordingAiProcessor::class);
		$processor->expects($this->once())->method('process')->with(42);
		$job = new SubmitRecordingAi($timeFactory, $processor);

		self::invokePrivate($job, 'run', [['operationId' => 42]]);
	}

	public function testTimedJobProcessesDueOperationIds(): void {
		$now = new \DateTime('2026-07-15T10:00:00+00:00');
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1000);
		$timeFactory->method('getDateTime')->willReturn($now);
		$mapper = $this->createMock(RecordingAiOperationMapper::class);
		$mapper->expects($this->once())->method('findDueIds')->with($now)->willReturn([11, 12, 13]);
		$processor = $this->createMock(RecordingAiProcessor::class);
		$processed = [];
		$processor->expects($this->exactly(3))->method('process')
			->willReturnCallback(function (int $operationId) use (&$processed): void {
				$processed[] = $operationId;
			});
		$job = new ProcessRecordingAi($timeFactory, $mapper, $processor, $this->createMock(LoggerInterface::class));

		self::invokePrivate($job, 'run', [null]);

		$this->assertSame([11, 12, 13], $processed);
		$this->assertFalse($job->getAllowParallelRuns());
	}

	public function testTimedJobStopsStartingWorkAfterRuntimeBudget(): void {
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->expects($this->exactly(2))->method('getTime')->willReturnOnConsecutiveCalls(1000, 1300);
		$timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-07-15T10:00:00+00:00'));
		$mapper = $this->createMock(RecordingAiOperationMapper::class);
		$mapper->method('findDueIds')->willReturn([11, 12]);
		$processor = $this->createMock(RecordingAiProcessor::class);
		$processor->expects($this->once())->method('process')->with(11);
		$job = new ProcessRecordingAi($timeFactory, $mapper, $processor, $this->createMock(LoggerInterface::class));

		self::invokePrivate($job, 'run', [null]);
	}

	public function testTimedJobContinuesAfterUnexpectedOperationFailure(): void {
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1000);
		$timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-07-15T10:00:00+00:00'));
		$mapper = $this->createMock(RecordingAiOperationMapper::class);
		$mapper->method('findDueIds')->willReturn([11, 12]);
		$processor = $this->createMock(RecordingAiProcessor::class);
		$processor->expects($this->exactly(2))->method('process')
			->willReturnCallback(function (int $operationId): void {
				if ($operationId === 11) {
					throw new \RuntimeException('unexpected');
				}
			});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with('Unexpected error while processing recording AI operation', $this->callback(
				fn (array $context): bool => $context['operationId'] === 11
					&& $context['exception'] instanceof \RuntimeException
			));
		$job = new ProcessRecordingAi($timeFactory, $mapper, $processor, $logger);

		self::invokePrivate($job, 'run', [null]);
	}
}
