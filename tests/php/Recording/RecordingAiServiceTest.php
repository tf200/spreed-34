<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\Recording;

use OCA\Talk\Model\RecordingAiOperation;
use OCA\Talk\Model\RecordingAiOperationMapper;
use OCA\Talk\Recording\GoogleAiConfig;
use OCA\Talk\Recording\RecordingAiService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use Test\TestCase;

class RecordingAiServiceTest extends TestCase {
	public function testDisabledDoesNotCreateOperation(): void {
		$mapper = $this->createMock(RecordingAiOperationMapper::class);
		$mapper->expects($this->never())->method('insert');
		$config = $this->createMock(GoogleAiConfig::class);
		$config->method('isEnabled')->willReturn(false);
		$service = new RecordingAiService($mapper, $config, $this->createMock(ITimeFactory::class), $this->createMock(IJobList::class));

		$this->assertNull($service->enqueue(42, 'alice', 'room-token'));
	}

	public function testCreatesDurableOperation(): void {
		$mapper = $this->createMock(RecordingAiOperationMapper::class);
		$mapper->method('findByRecordingFileId')->willThrowException(new DoesNotExistException('missing'));
		$mapper->expects($this->once())->method('insert')->willReturnCallback(function (RecordingAiOperation $operation): RecordingAiOperation {
			$this->assertSame(42, $operation->getRecordingFileId());
			$this->assertSame('alice', $operation->getOwnerId());
			$this->assertSame('room-token', $operation->getRoomToken());
			$this->assertSame(RecordingAiOperation::STATE_QUEUED, $operation->getState());
			$this->assertArrayHasKey('state', $operation->getUpdatedFields());
			$this->assertSame(1783886400, $operation->getDeadlineAt()->getTimestamp());
			return $operation;
		});
		$config = $this->createMock(GoogleAiConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$config->expects($this->once())->method('getValidated');
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-07-11T20:00:00+00:00'));
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('add');
		$service = new RecordingAiService($mapper, $config, $timeFactory, $jobList);

		$this->assertInstanceOf(RecordingAiOperation::class, $service->enqueue(42, 'alice', 'room-token'));
	}
}
