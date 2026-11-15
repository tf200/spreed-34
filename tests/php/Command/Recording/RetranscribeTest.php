<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Command\Recording;

use OCA\Talk\BackgroundJob\SubmitRecordingAi;
use OCA\Talk\Command\Recording\Retranscribe;
use OCA\Talk\Model\RecordingAiOperation;
use OCA\Talk\Model\RecordingAiOperationMapper;
use OCA\Talk\Recording\GoogleAiConfig;
use OCA\Talk\Recording\MultitrackTranscriptService;
use OCA\Talk\Recording\ParticipantTracksStore;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Test\TestCase;

class RetranscribeTest extends TestCase {
	private RecordingAiOperationMapper&MockObject $mapper;
	private ParticipantTracksStore&MockObject $tracksStore;
	private MultitrackTranscriptService&MockObject $multitrack;
	private GoogleAiConfig&MockObject $config;
	private IJobList&MockObject $jobList;
	private Retranscribe $command;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(RecordingAiOperationMapper::class);
		$this->tracksStore = $this->createMock(ParticipantTracksStore::class);
		$this->multitrack = $this->createMock(MultitrackTranscriptService::class);
		$this->config = $this->createMock(GoogleAiConfig::class);
		$this->config->method('isMultitrackEnabled')->willReturn(true);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturnCallback(static fn () => new \DateTime('2026-11-15 12:00:00'));
		$this->jobList = $this->createMock(IJobList::class);
		$this->command = new Retranscribe($this->mapper, $this->tracksStore, $this->multitrack, $this->config, $timeFactory, $this->jobList);
	}

	private function failedOperation(): RecordingAiOperation {
		$operation = new RecordingAiOperation();
		$operation->id = '7';
		$operation->setRecordingFileId(42);
		$operation->setState(RecordingAiOperation::STATE_FAILED);
		$operation->setAttempts(8);
		$operation->setLastErrorCode('mapping_failed');
		$operation->setSkipTracks(true);
		$operation->setGcsObject('recordings/old');
		return $operation;
	}

	private function runCommand(array $arguments): array {
		$output = new BufferedOutput();
		$status = $this->command->run(new ArrayInput($arguments), $output);
		return [$status, $output->fetch()];
	}

	public function testRequeuesWithKeptTracks(): void {
		$operation = $this->failedOperation();
		$this->mapper->method('findByRecordingFileId')->with(42)->willReturn($operation);
		$this->tracksStore->method('hasRejectedUpload')->willReturn(false);
		$this->tracksStore->method('has')->with(42)->willReturn(true);
		$this->multitrack->expects($this->once())->method('reset')->with($operation);
		$this->mapper->expects($this->once())->method('update')->with($operation);
		$this->jobList->expects($this->once())->method('add')->with(SubmitRecordingAi::class, ['operationId' => 7]);

		[$status, $output] = $this->runCommand(['recording-file-id' => '42']);

		$this->assertSame(0, $status);
		$this->assertStringContainsString('the audio of the participants', $output);
		$this->assertSame(RecordingAiOperation::STATE_QUEUED, $operation->getState());
		$this->assertFalse($operation->getSkipTracks());
		$this->assertNull($operation->getGcsObject());
		$this->assertSame(0, $operation->getAttempts());
		$this->assertNull($operation->getLastErrorCode());
		$this->assertSame('2026-11-16 12:00:00', $operation->getDeadlineAt()->format('Y-m-d H:i:s'));
	}

	public function testImportsRejectedUpload(): void {
		$operation = $this->failedOperation();
		$this->mapper->method('findByRecordingFileId')->willReturn($operation);
		$this->tracksStore->method('hasRejectedUpload')->willReturn(true);
		$this->tracksStore->expects($this->once())->method('importRejectedUpload')->with(42)->willReturn(0);
		$this->tracksStore->method('has')->willReturn(true);

		[$status, $output] = $this->runCommand(['recording-file-id' => '42']);

		$this->assertSame(0, $status);
		$this->assertStringContainsString('Imported the participant tracks', $output);
		$this->assertFalse($operation->getSkipTracks());
	}

	public function testFallsBackToMixedRecordingWithoutTracks(): void {
		$operation = $this->failedOperation();
		$this->mapper->method('findByRecordingFileId')->willReturn($operation);
		$this->tracksStore->method('hasRejectedUpload')->willReturn(false);
		$this->tracksStore->method('has')->willReturn(false);

		[$status, $output] = $this->runCommand(['recording-file-id' => '42']);

		$this->assertSame(0, $status);
		$this->assertStringContainsString('No participant tracks are kept', $output);
		$this->assertTrue($operation->getSkipTracks());
		$this->assertSame('recordings/old', $operation->getGcsObject());
	}

	public function testMixedOptionIgnoresTracks(): void {
		$operation = $this->failedOperation();
		$this->mapper->method('findByRecordingFileId')->willReturn($operation);
		$this->tracksStore->expects($this->never())->method('importRejectedUpload');
		$this->tracksStore->method('has')->willReturn(true);

		[$status] = $this->runCommand(['recording-file-id' => '42', '--mixed' => true]);

		$this->assertSame(0, $status);
		$this->assertTrue($operation->getSkipTracks());
	}

	public function testRefusesOperationInProgress(): void {
		$operation = $this->failedOperation();
		$operation->setState(RecordingAiOperation::STATE_TRANSCRIBING_TRACKS);
		$this->mapper->method('findByRecordingFileId')->willReturn($operation);
		$this->mapper->expects($this->never())->method('update');

		[$status, $output] = $this->runCommand(['recording-file-id' => '42']);

		$this->assertSame(1, $status);
		$this->assertStringContainsString('still being processed', $output);
	}

	public function testRefusesUnknownRecording(): void {
		$this->mapper->method('findByRecordingFileId')->willThrowException(new DoesNotExistException(''));

		[$status] = $this->runCommand(['recording-file-id' => '42']);

		$this->assertSame(1, $status);
	}
}
