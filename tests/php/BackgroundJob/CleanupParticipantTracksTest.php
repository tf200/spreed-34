<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\BackgroundJob;

use OCA\Talk\BackgroundJob\CleanupParticipantTracks;
use OCA\Talk\Model\RecordingAiOperation;
use OCA\Talk\Model\RecordingAiOperationMapper;
use OCA\Talk\Recording\GoogleAiConfig;
use OCA\Talk\Recording\ParticipantTracksStore;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class CleanupParticipantTracksTest extends TestCase {
	private const NOW = 1_800_000_000;
	private const DAY = 24 * 3600;

	private ParticipantTracksStore&MockObject $tracksStore;
	private RecordingAiOperationMapper&MockObject $mapper;
	private Folder&MockObject $userFolder;
	private CleanupParticipantTracks $job;

	protected function setUp(): void {
		parent::setUp();
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$this->tracksStore = $this->createMock(ParticipantTracksStore::class);
		$this->mapper = $this->createMock(RecordingAiOperationMapper::class);
		$config = $this->createMock(GoogleAiConfig::class);
		$config->method('getTracksRetentionCompletedDays')->willReturn(7);
		$config->method('getTracksRetentionFailedDays')->willReturn(30);
		$this->userFolder = $this->createMock(Folder::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('owner')->willReturn($this->userFolder);
		$this->job = new CleanupParticipantTracks($time, $this->tracksStore, $this->mapper, $config, $rootFolder, $this->createMock(LoggerInterface::class));
	}

	private function operation(string $state, int $updatedAt): RecordingAiOperation {
		$operation = new RecordingAiOperation();
		$operation->setRecordingFileId(42);
		$operation->setOwnerId('owner');
		$operation->setState($state);
		$operation->setUpdatedAt((new \DateTime())->setTimestamp($updatedAt));
		return $operation;
	}

	public static function dataRetention(): array {
		return [
			'in progress for long' => [RecordingAiOperation::STATE_TRANSCRIBING_TRACKS, 60, false],
			'completed recently' => [RecordingAiOperation::STATE_COMPLETED, 6, false],
			'completed long ago' => [RecordingAiOperation::STATE_COMPLETED, 8, true],
			'failed some days ago' => [RecordingAiOperation::STATE_FAILED, 8, false],
			'failed long ago' => [RecordingAiOperation::STATE_FAILED, 31, true],
			'cancelled long ago' => [RecordingAiOperation::STATE_CANCELLED, 31, true],
		];
	}

	#[DataProvider('dataRetention')]
	public function testKeepsTracksDependingOnTheTranscription(string $state, int $daysAgo, bool $expectDeleted): void {
		$this->tracksStore->method('getStoredTracks')->willReturn([42 => self::NOW - 90 * self::DAY]);
		$this->tracksStore->method('getRejectedUploads')->willReturn([]);
		$this->mapper->method('findByRecordingFileId')->with(42)->willReturn($this->operation($state, self::NOW - $daysAgo * self::DAY));
		$this->userFolder->method('getFirstNodeById')->with(42)->willReturn($this->createMock(File::class));

		$this->tracksStore->expects($expectDeleted ? $this->once() : $this->never())->method('delete')->with(42);
		$this->tracksStore->expects($expectDeleted ? $this->once() : $this->never())->method('deleteRejectedUpload')->with(42);

		self::invokePrivate($this->job, 'run', [null]);
	}

	public function testDeletesTracksOfDeletedRecording(): void {
		$this->tracksStore->method('getStoredTracks')->willReturn([]);
		$this->tracksStore->method('getRejectedUploads')->willReturn([42 => self::NOW]);
		$this->mapper->method('findByRecordingFileId')->willReturn($this->operation(RecordingAiOperation::STATE_COMPLETED, self::NOW));
		$this->userFolder->method('getFirstNodeById')->with(42)->willReturn(null);

		$this->tracksStore->expects($this->once())->method('delete')->with(42);
		$this->tracksStore->expects($this->once())->method('deleteRejectedUpload')->with(42);

		self::invokePrivate($this->job, 'run', [null]);
	}

	public function testTracksImportedAgainAreKeptFromThen(): void {
		$this->tracksStore->method('getStoredTracks')->willReturn([42 => self::NOW - self::DAY]);
		$this->tracksStore->method('getRejectedUploads')->willReturn([]);
		$this->mapper->method('findByRecordingFileId')->willReturn($this->operation(RecordingAiOperation::STATE_COMPLETED, self::NOW - 10 * self::DAY));
		$this->userFolder->method('getFirstNodeById')->willReturn($this->createMock(File::class));

		$this->tracksStore->expects($this->never())->method('delete');

		self::invokePrivate($this->job, 'run', [null]);
	}

	public function testDeletesUnusedTracksAfterSomeDays(): void {
		$this->tracksStore->method('getStoredTracks')->willReturn([
			42 => self::NOW - 3 * self::DAY,
			43 => self::NOW - self::DAY,
		]);
		$this->tracksStore->method('getRejectedUploads')->willReturn([]);
		$this->mapper->method('findByRecordingFileId')->willThrowException(new DoesNotExistException(''));

		$this->tracksStore->expects($this->once())->method('delete')->with(42);

		self::invokePrivate($this->job, 'run', [null]);
	}
}
