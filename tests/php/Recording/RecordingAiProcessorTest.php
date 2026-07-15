<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\Recording;

use OCA\Talk\Model\RecordingAiOperation;
use OCA\Talk\Model\RecordingAiOperationMapper;
use OCA\Talk\Recording\GoogleCloudStorageClient;
use OCA\Talk\Recording\GoogleGeminiClient;
use OCA\Talk\Recording\GoogleSpeechClient;
use OCA\Talk\Recording\RecordingAiProcessor;
use OCA\Talk\Recording\RecordingAiTranscriptService;
use OCA\Talk\Service\RecordingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class RecordingAiProcessorTest extends TestCase {
	private const NOW = '2026-07-15T10:00:00+00:00';

	private RecordingAiOperationMapper&MockObject $mapper;
	private IRootFolder&MockObject $rootFolder;
	private GoogleCloudStorageClient&MockObject $storage;
	private GoogleSpeechClient&MockObject $speech;
	private ITimeFactory&MockObject $timeFactory;
	private RecordingAiTranscriptService&MockObject $transcriptService;
	private GoogleGeminiClient&MockObject $gemini;
	private RecordingService&MockObject $recordingService;
	private IConfig&MockObject $serverConfig;
	private RecordingAiProcessor $processor;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(RecordingAiOperationMapper::class);
		$this->mapper->method('updateUploadCheckpoint')->willReturn(true);
		$this->mapper->method('clearGcsObject')->willReturn(true);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->storage = $this->createMock(GoogleCloudStorageClient::class);
		$this->speech = $this->createMock(GoogleSpeechClient::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getDateTime')->willReturnCallback(fn (): \DateTime => new \DateTime(self::NOW));
		$this->transcriptService = $this->createMock(RecordingAiTranscriptService::class);
		$this->gemini = $this->createMock(GoogleGeminiClient::class);
		$this->recordingService = $this->createMock(RecordingService::class);
		$this->serverConfig = $this->createMock(IConfig::class);
		$this->serverConfig->method('getAppValue')->willReturn('yes');
		$this->processor = new RecordingAiProcessor(
			$this->mapper,
			$this->rootFolder,
			$this->storage,
			$this->speech,
			$this->timeFactory,
			$this->transcriptService,
			$this->gemini,
			$this->recordingService,
			$this->serverConfig,
		);
	}

	public function testStopsWhenAnotherWorkerClaimedOperation(): void {
		$operation = $this->createOperation(RecordingAiOperation::STATE_QUEUED);
		$this->mapper->method('findById')->with(42)->willReturn($operation);
		$this->mapper->expects($this->once())->method('claimForUpload')->willReturn(false);
		$this->rootFolder->expects($this->never())->method('getUserFolder');

		$this->processor->process(42);
	}

	public function testSubmissionSchedulesPollingAndYields(): void {
		$operation = $this->createOperation(RecordingAiOperation::STATE_QUEUED);
		$this->mapper->method('findById')->with(42)->willReturn($operation);
		$this->mapper->expects($this->once())->method('claimForUpload')
			->willReturnCallback(function () use ($operation): bool {
				$operation->setState(RecordingAiOperation::STATE_UPLOADING);
				return true;
			});
		$file = $this->createConfiguredMock(File::class, ['getMimeType' => 'audio/webm']);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->with(123)->willReturn([$file]);
		$this->rootFolder->method('getUserFolder')->with('owner')->willReturn($folder);
		$this->storage->expects($this->once())->method('upload')->with($file, 42)->willReturn('recording-object');
		$this->speech->expects($this->once())->method('submit')->with('recording-object', 'audio/webm')->willReturn('speech-operation');
		$this->speech->expects($this->never())->method('poll');
		$this->mapper->expects($this->once())->method('updateClaimed')
			->with($this->callback(function (RecordingAiOperation $updated): bool {
				$this->assertSame(RecordingAiOperation::STATE_TRANSCRIBING, $updated->getState());
				$this->assertSame('speech-operation', $updated->getSpeechOperation());
				$this->assertSame(strtotime(self::NOW) + 60, $updated->getNextAttemptAt()->getTimestamp());
				return true;
			}), $this->isType('string'))
			->willReturn(true);

		$this->processor->process(42);
	}

	public function testSubmissionPersistenceFailurePreservesKnownSpeechOperation(): void {
		$operation = $this->createOperation(RecordingAiOperation::STATE_UPLOADING);
		$operation->setGcsObject('recording-object');
		$this->mapper->method('findById')->with(42)->willReturn($operation);
		$this->mapper->method('claimForUpload')->willReturn(true);
		$file = $this->createConfiguredMock(File::class, ['getMimeType' => 'audio/webm']);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn([$file]);
		$this->rootFolder->method('getUserFolder')->willReturn($folder);
		$this->storage->expects($this->never())->method('upload');
		$this->speech->expects($this->once())->method('submit')->willReturn('speech-operation');
		$update = 0;
		$this->mapper->expects($this->exactly(2))->method('updateClaimed')
			->willReturnCallback(function (RecordingAiOperation $updated) use (&$update): bool {
				$update++;
				if ($update === 1) {
					throw new \RuntimeException('database unavailable');
				}
				$this->assertSame(RecordingAiOperation::STATE_TRANSCRIBING, $updated->getState());
				$this->assertSame('speech-operation', $updated->getSpeechOperation());
				$this->assertSame('submission_failed', $updated->getLastErrorCode());
				return true;
			});

		$this->processor->process(42);
	}

	public function testPendingSpeechPollSchedulesNextPollAndYields(): void {
		$operation = $this->createOperation(RecordingAiOperation::STATE_TRANSCRIBING);
		$operation->setSpeechOperation('speech-operation');
		$operation->setAttempts(3);
		$operation->setLastErrorCode('poll_failed');
		$this->expectStageClaims($operation);
		$this->speech->expects($this->once())->method('poll')->with('speech-operation')->willReturn(['done' => false]);
		$this->transcriptService->expects($this->never())->method('store');
		$this->mapper->expects($this->once())->method('updateClaimed')
			->willReturnCallback(function (RecordingAiOperation $updated): bool {
				$this->assertSame(RecordingAiOperation::STATE_TRANSCRIBING, $updated->getState());
				$this->assertSame(0, $updated->getAttempts());
				$this->assertNull($updated->getLastErrorCode());
				$this->assertSame(strtotime(self::NOW) + 120, $updated->getNextAttemptAt()->getTimestamp());
				return true;
			});

		$this->processor->process(42);
	}

	public function testCompletedPollMapsAndSummarizesInSameInvocation(): void {
		$operation = $this->createOperation(RecordingAiOperation::STATE_TRANSCRIBING);
		$operation->setSpeechOperation('speech-operation');
		$operation->setGcsObject('recording-object');
		$this->expectStageClaims($operation, 3);
		$this->speech->expects($this->once())->method('poll')->willReturn([
			'done' => true,
			'response' => ['results' => ['recording-object' => ['transcript' => ['results' => []]]]],
		]);
		$this->storage->expects($this->once())->method('delete')->with('recording-object');
		$this->transcriptService->expects($this->once())->method('store')->with($operation)->willReturn('Meeting transcript');
		$this->gemini->expects($this->once())->method('summarize')->with('Meeting transcript')->willReturn('Meeting summary');
		$this->recordingService->expects($this->once())->method('storeTranscript')
			->with('owner', 'room', 123, 'Meeting summary', 'summary', false);
		$states = [];
		$this->mapper->expects($this->exactly(3))->method('updateClaimed')
			->willReturnCallback(function (RecordingAiOperation $updated) use (&$states): bool {
				$states[] = $updated->getState();
				return true;
			});

		$this->processor->process(42);

		$this->assertSame([
			RecordingAiOperation::STATE_MAPPING,
			RecordingAiOperation::STATE_SUMMARIZING,
			RecordingAiOperation::STATE_COMPLETED,
		], $states);
		$this->assertNull($operation->getSpeechResponse());
		$this->assertNull($operation->getTranscript());
		$this->assertNull($operation->getGcsObject());
	}

	public function testMappingCompletesWithoutGeminiWhenSummaryIsDisabled(): void {
		$operation = $this->createOperation(RecordingAiOperation::STATE_MAPPING);
		$operation->setSpeechResponse('{}');
		$this->expectStageClaims($operation);
		$this->serverConfig = $this->createMock(IConfig::class);
		$this->serverConfig->method('getAppValue')->willReturn('no');
		$this->processor = new RecordingAiProcessor(
			$this->mapper,
			$this->rootFolder,
			$this->storage,
			$this->speech,
			$this->timeFactory,
			$this->transcriptService,
			$this->gemini,
			$this->recordingService,
			$this->serverConfig,
		);
		$this->transcriptService->expects($this->once())->method('store')->willReturn('Meeting transcript');
		$this->gemini->expects($this->never())->method('summarize');
		$this->mapper->expects($this->once())->method('updateClaimed')
			->willReturnCallback(function (RecordingAiOperation $updated): bool {
				$this->assertSame(RecordingAiOperation::STATE_COMPLETED, $updated->getState());
				$this->assertNull($updated->getSpeechResponse());
				return true;
			});

		$this->processor->process(42);
	}

	public function testMappingRetryStopsBeforeSummary(): void {
		$operation = $this->createOperation(RecordingAiOperation::STATE_MAPPING);
		$operation->setSpeechResponse('{}');
		$this->expectStageClaims($operation);
		$this->transcriptService->method('store')->willThrowException(new \RuntimeException('storage unavailable'));
		$this->gemini->expects($this->never())->method('summarize');
		$this->mapper->expects($this->once())->method('updateClaimed')
			->willReturnCallback(function (RecordingAiOperation $updated): bool {
				$this->assertSame(RecordingAiOperation::STATE_MAPPING, $updated->getState());
				$this->assertSame(1, $updated->getAttempts());
				$this->assertSame('mapping_failed', $updated->getLastErrorCode());
				$this->assertSame(strtotime(self::NOW) + 120, $updated->getNextAttemptAt()->getTimestamp());
				return true;
			});

		$this->processor->process(42);
	}

	public function testStalePollWorkerDoesNotContinueToMapping(): void {
		$operation = $this->createOperation(RecordingAiOperation::STATE_TRANSCRIBING);
		$operation->setSpeechOperation('speech-operation');
		$operation->setGcsObject('recording-object');
		$this->expectStageClaims($operation);
		$this->speech->method('poll')->willReturn(['done' => true, 'response' => ['results' => []]]);
		$this->mapper->expects($this->once())->method('updateClaimed')->willReturn(false);
		$this->storage->expects($this->never())->method('delete');
		$this->transcriptService->expects($this->never())->method('store');

		$this->processor->process(42);
	}

	public function testTerminalSpeechFailureIsPersistedAndNotified(): void {
		$operation = $this->createOperation(RecordingAiOperation::STATE_TRANSCRIBING);
		$operation->setSpeechOperation('speech-operation');
		$this->expectStageClaims($operation);
		$this->speech->method('poll')->willReturn(['done' => true, 'error' => ['code' => 13]]);
		$this->mapper->expects($this->once())->method('updateClaimed')
			->willReturnCallback(function (RecordingAiOperation $updated): bool {
				$this->assertSame(RecordingAiOperation::STATE_FAILED, $updated->getState());
				$this->assertSame('speech_failed', $updated->getLastErrorCode());
				return true;
			});
		$this->recordingService->expects($this->once())->method('notifyAboutFailedTranscript')
			->with('owner', 'room', 123, 'transcript');

		$this->processor->process(42);
	}

	public function testMalformedSummaryFailsWithoutCallingGemini(): void {
		$operation = $this->createOperation(RecordingAiOperation::STATE_SUMMARIZING);
		$this->expectStageClaims($operation);
		$this->gemini->expects($this->never())->method('summarize');
		$this->mapper->expects($this->once())->method('updateClaimed')
			->willReturnCallback(function (RecordingAiOperation $updated): bool {
				$this->assertSame(RecordingAiOperation::STATE_FAILED, $updated->getState());
				$this->assertSame('invalid_state', $updated->getLastErrorCode());
				return true;
			});

		$this->processor->process(42);
	}

	public function testSubmittedOperationResumesPollingPipeline(): void {
		$operation = $this->createOperation(RecordingAiOperation::STATE_SUBMITTED);
		$operation->setSpeechOperation('speech-operation');
		$this->expectStageClaims($operation, 2);
		$this->speech->expects($this->once())->method('poll')->with('speech-operation')->willReturn(['done' => false]);
		$states = [];
		$this->mapper->expects($this->exactly(2))->method('updateClaimed')
			->willReturnCallback(function (RecordingAiOperation $updated) use (&$states): bool {
				$states[] = $updated->getState();
				return true;
			});

		$this->processor->process(42);

		$this->assertSame([
			RecordingAiOperation::STATE_TRANSCRIBING,
			RecordingAiOperation::STATE_TRANSCRIBING,
		], $states);
	}

	public function testRetryIsCappedAtDeadline(): void {
		$operation = $this->createOperation(RecordingAiOperation::STATE_MAPPING);
		$operation->setSpeechResponse('{}');
		$operation->setDeadlineAt(new \DateTime('2026-07-15T10:00:30+00:00'));
		$this->expectStageClaims($operation);
		$this->transcriptService->method('store')->willThrowException(new \RuntimeException('storage unavailable'));
		$this->mapper->expects($this->once())->method('updateClaimed')
			->willReturnCallback(function (RecordingAiOperation $updated): bool {
				$this->assertSame(strtotime('2026-07-15T10:00:30+00:00'), $updated->getNextAttemptAt()->getTimestamp());
				return true;
			});

		$this->processor->process(42);
	}

	public function testCleanupFailureDoesNotPreventTerminalPersistence(): void {
		$operation = $this->createOperation(RecordingAiOperation::STATE_TRANSCRIBING);
		$operation->setSpeechOperation('speech-operation');
		$operation->setGcsObject('recording-object');
		$this->expectStageClaims($operation);
		$this->speech->method('poll')->willReturn(['done' => true, 'error' => ['code' => 13]]);
		$this->mapper->expects($this->once())->method('updateClaimed')
			->willReturnCallback(fn (RecordingAiOperation $updated): bool => $updated->getState() === RecordingAiOperation::STATE_FAILED);
		$this->storage->expects($this->once())->method('delete')->willThrowException(new \RuntimeException('configuration unavailable'));
		$this->recordingService->expects($this->once())->method('notifyAboutFailedTranscript');

		$this->processor->process(42);
	}

	public function testExpiredOperationFailsBeforeExternalWork(): void {
		$operation = $this->createOperation(RecordingAiOperation::STATE_TRANSCRIBING);
		$operation->setSpeechOperation('speech-operation');
		$operation->setDeadlineAt(new \DateTime('2026-07-15T09:59:59+00:00'));
		$this->expectStageClaims($operation);
		$this->speech->expects($this->never())->method('poll');
		$this->mapper->expects($this->once())->method('updateClaimed')
			->willReturnCallback(function (RecordingAiOperation $updated): bool {
				$this->assertSame(RecordingAiOperation::STATE_FAILED, $updated->getState());
				$this->assertSame('deadline_exceeded', $updated->getLastErrorCode());
				return true;
			});

		$this->processor->process(42);
	}

	private function createOperation(string $state): RecordingAiOperation {
		$operation = new RecordingAiOperation();
		$operation->id = '42';
		$operation->setOwnerId('owner');
		$operation->setRoomToken('room');
		$operation->setRecordingFileId(123);
		$operation->setState($state);
		$operation->setAttempts(0);
		$operation->setNextAttemptAt(new \DateTime(self::NOW));
		$operation->setDeadlineAt(new \DateTime('2026-07-16T10:00:00+00:00'));
		$operation->setUpdatedAt(new \DateTime(self::NOW));
		return $operation;
	}

	private function expectStageClaims(RecordingAiOperation $operation, int $count = 1): void {
		$this->mapper->method('findById')->with(42)->willReturn($operation);
		$this->mapper->expects($this->exactly($count))->method('claimForStage')->willReturn(true);
	}
}
