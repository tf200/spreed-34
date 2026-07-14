<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\Recording;

use OCA\Talk\Model\RecordingAiOperationMapper;
use OCA\Talk\Recording\GoogleCloudStorageClient;
use OCA\Talk\Recording\GoogleGeminiClient;
use OCA\Talk\Recording\GoogleSpeechClient;
use OCA\Talk\Recording\RecordingAiProcessor;
use OCA\Talk\Recording\RecordingAiTranscriptService;
use OCA\Talk\Service\RecordingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use Test\TestCase;

class RecordingAiProcessorTest extends TestCase {
	public function testSubmitStopsWhenAnotherWorkerClaimedOperation(): void {
		$mapper = $this->createMock(RecordingAiOperationMapper::class);
		$mapper->expects($this->once())->method('claimForUpload')->willReturn(false);
		$mapper->expects($this->never())->method('findById');
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->never())->method('getUserFolder');
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-07-14T18:00:00+00:00'));
		$processor = new RecordingAiProcessor(
			$mapper,
			$rootFolder,
			$this->createMock(GoogleCloudStorageClient::class),
			$this->createMock(GoogleSpeechClient::class),
			$timeFactory,
			$this->createMock(RecordingAiTranscriptService::class),
			$this->createMock(GoogleGeminiClient::class),
			$this->createMock(RecordingService::class),
			$this->createMock(IConfig::class),
		);

		$processor->submit(42);
	}
}
