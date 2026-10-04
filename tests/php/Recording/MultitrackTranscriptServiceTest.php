<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\Recording;

use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\RecordingAiChunk;
use OCA\Talk\Model\RecordingAiChunkMapper;
use OCA\Talk\Model\RecordingAiOperation;
use OCA\Talk\Participant;
use OCA\Talk\Recording\GoogleAiConfig;
use OCA\Talk\Recording\GoogleApiException;
use OCA\Talk\Recording\MultitrackTranscriptMerger;
use OCA\Talk\Recording\MultitrackTranscriptService;
use OCA\Talk\Recording\ParticipantTracksStore;
use OCA\Talk\Recording\TrackTranscriptionProvider;
use OCA\Talk\Room;
use OCA\Talk\Service\ParticipantService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class MultitrackTranscriptServiceTest extends TestCase {
	private ParticipantTracksStore&MockObject $tracksStore;
	private RecordingAiChunkMapper&MockObject $chunkMapper;
	private TrackTranscriptionProvider&MockObject $provider;
	private ParticipantService&MockObject $participantService;
	private ITimeFactory&MockObject $timeFactory;
	private MultitrackTranscriptService $service;
	private RecordingAiOperation $operation;

	protected function setUp(): void {
		parent::setUp();
		$this->tracksStore = $this->createMock(ParticipantTracksStore::class);
		$this->chunkMapper = $this->createMock(RecordingAiChunkMapper::class);
		$this->provider = $this->createMock(TrackTranscriptionProvider::class);
		$this->provider->method('getName')->willReturn('gemini_transcribe');
		$this->provider->method('getModel')->willReturn('gemini-3.5-transcribe-preview');
		$config = $this->createMock(GoogleAiConfig::class);
		$config->method('getTranscriptionConfig')->willReturn(['project' => 'talk-project', 'location' => 'global', 'model' => 'gemini-3.5-transcribe-preview', 'language' => 'auto']);
		$manager = $this->createMock(Manager::class);
		$manager->method('getRoomByToken')->willReturn($this->createMock(Room::class));
		$this->participantService = $this->createMock(ParticipantService::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getDateTime')->willReturnCallback(fn () => new \DateTime('2026-10-04T12:00:00+00:00'));
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $parameters = []) => vsprintf($text, $parameters));

		$this->service = new MultitrackTranscriptService(
			$this->tracksStore,
			$this->chunkMapper,
			$this->provider,
			new MultitrackTranscriptMerger(),
			$config,
			$manager,
			$this->participantService,
			$this->timeFactory,
			$l,
			$this->createMock(LoggerInterface::class),
		);

		$this->operation = new RecordingAiOperation();
		$this->operation->id = '42';
		$this->operation->setRecordingFileId(123);
		$this->operation->setRoomToken('room');

		$this->tracksStore->method('getManifest')->with(123)->willReturn([
			'version' => 1,
			'segments' => [
				['id' => '1', 'actorType' => 'users', 'actorId' => 'alice', 'displayName' => 'Alice'],
				// An id is not used as name.
				['id' => '2', 'actorType' => 'guests', 'actorId' => 'abc', 'displayName' => 'abc'],
				// Alice rejoined.
				['id' => '3', 'actorType' => 'users', 'actorId' => 'alice', 'displayName' => 'Alice'],
				['id' => '4', 'actorType' => 'guests', 'actorId' => 'gone', 'displayName' => null],
			],
			'chunks' => [
				['id' => '1-0', 'segmentId' => '1', 'file' => 'chunk-1-0.ogg', 'mimeType' => 'audio/ogg', 'duration' => 5.0,
					'cutMap' => [['chunkStart' => 0.0, 'trackStart' => 1.0, 'recordingStart' => 8.0, 'duration' => 5.0]]],
				['id' => '2-0', 'segmentId' => '2', 'file' => 'chunk-2-0.ogg', 'mimeType' => 'audio/ogg', 'duration' => 3.0,
					'cutMap' => [['chunkStart' => 0.0, 'trackStart' => 7.0, 'recordingStart' => 14.0, 'duration' => 3.0]]],
				['id' => '3-0', 'segmentId' => '3', 'file' => 'chunk-3-0.ogg', 'mimeType' => 'audio/ogg', 'duration' => 3.0,
					'cutMap' => [['chunkStart' => 0.0, 'trackStart' => 0.0, 'recordingStart' => 3700.0, 'duration' => 3.0]]],
				['id' => '4-0', 'segmentId' => '4', 'file' => 'chunk-4-0.ogg', 'mimeType' => 'audio/ogg', 'duration' => 3.0,
					'cutMap' => [['chunkStart' => 0.0, 'trackStart' => 0.0, 'recordingStart' => 3800.0, 'duration' => 3.0]]],
			],
		]);
	}

	private function chunk(string $id, string $state, ?array $words = null, int $attempts = 0): RecordingAiChunk {
		$chunk = new RecordingAiChunk();
		$chunk->setOperationId(42);
		$chunk->setChunkId($id);
		$chunk->setState($state);
		$chunk->setAttempts($attempts);
		$chunk->setWords($words === null ? null : json_encode($words));
		return $chunk;
	}

	public function testPrepareCreatesMissingChunks(): void {
		$this->chunkMapper->method('findByOperation')->willReturn([$this->chunk('1-0', RecordingAiChunk::STATE_DONE)]);
		$inserted = [];
		$this->chunkMapper->method('insert')->willReturnCallback(function (RecordingAiChunk $chunk) use (&$inserted) {
			// Only updated fields are inserted, the state column has no default.
			$this->assertArrayHasKey('state', $chunk->getUpdatedFields());
			$this->assertSame(RecordingAiChunk::STATE_PENDING, $chunk->getState());
			$inserted[] = $chunk->getChunkId();
			return $chunk;
		});

		$this->service->prepare($this->operation);

		$this->assertSame(['2-0', '3-0', '4-0'], $inserted);
	}

	public function testTranscribePendingStoresWordsAndCountsFailures(): void {
		$this->timeFactory->method('getTime')->willReturn(1000);
		$this->chunkMapper->method('findByOperation')->willReturn([
			$this->chunk('1-0', RecordingAiChunk::STATE_PENDING),
			$this->chunk('2-0', RecordingAiChunk::STATE_PENDING),
			$this->chunk('3-0', RecordingAiChunk::STATE_PENDING, null, MultitrackTranscriptService::MAX_CHUNK_ATTEMPTS - 1),
			$this->chunk('4-0', RecordingAiChunk::STATE_DONE, []),
		]);
		$this->tracksStore->method('getChunkContent')->willReturnCallback(fn (int $fileId, string $file) => $file);
		$this->provider->method('transcribe')->willReturnCallback(function (string $audio) {
			if ($audio === 'chunk-1-0.ogg') {
				return [['text' => 'Hello', 'start' => 0.1, 'end' => 0.4]];
			}
			throw new GoogleApiException('Quota');
		});
		$updated = [];
		$this->chunkMapper->method('update')->willReturnCallback(function (RecordingAiChunk $chunk) use (&$updated) {
			$updated[$chunk->getChunkId()] = [$chunk->getState(), $chunk->getAttempts()];
			return $chunk;
		});

		$result = $this->service->transcribePending($this->operation, 60);

		$this->assertSame(['pending' => 1, 'failed' => 1, 'retrying' => 1], $result);
		$this->assertSame([
			'1-0' => [RecordingAiChunk::STATE_DONE, 0],
			'2-0' => [RecordingAiChunk::STATE_PENDING, 1],
			'3-0' => [RecordingAiChunk::STATE_FAILED, MultitrackTranscriptService::MAX_CHUNK_ATTEMPTS],
		], $updated);
	}

	public function testTranscribePendingStopsStartingChunksAfterBudget(): void {
		$this->timeFactory->method('getTime')->willReturnOnConsecutiveCalls(1000, 1000, 1100, 1100);
		$this->chunkMapper->method('findByOperation')->willReturn([
			$this->chunk('1-0', RecordingAiChunk::STATE_PENDING),
			$this->chunk('2-0', RecordingAiChunk::STATE_PENDING),
		]);
		$this->tracksStore->method('getChunkContent')->willReturn('audio');
		$this->provider->expects($this->once())->method('transcribe')->willReturn([]);

		$result = $this->service->transcribePending($this->operation, 60);

		$this->assertSame(['pending' => 1, 'failed' => 0, 'retrying' => 0], $result);
	}

	public function testMergeResolvesSpeakersAndRendersMarkdown(): void {
		$this->chunkMapper->method('findByOperation')->willReturn([
			$this->chunk('1-0', RecordingAiChunk::STATE_DONE, [['text' => 'Good', 'start' => 0.0, 'end' => 0.3], ['text' => 'morning.', 'start' => 0.4, 'end' => 0.9]]),
			$this->chunk('2-0', RecordingAiChunk::STATE_DONE, [['text' => 'Thanks_a_lot*', 'start' => 0.5, 'end' => 1.0]]),
			$this->chunk('3-0', RecordingAiChunk::STATE_DONE, [['text' => 'Back', 'start' => 0.2, 'end' => 0.5], ['text' => 'again.', 'start' => 0.6, 'end' => 1.0]]),
			$this->chunk('4-0', RecordingAiChunk::STATE_DONE, [['text' => 'Bye.', 'start' => 0.1, 'end' => 0.4]]),
		]);
		$attendee = new Attendee();
		$attendee->setDisplayName('Bob (guest)');
		$participant = $this->createMock(Participant::class);
		$participant->method('getAttendee')->willReturn($attendee);
		$this->participantService->method('getParticipantByActor')->willReturnCallback(
			fn (Room $room, string $actorType, string $actorId) => $actorId === 'abc' ? $participant : throw new ParticipantNotFoundException(),
		);

		$result = $this->service->merge($this->operation);

		$this->assertSame([
			['id' => 'actor:users:alice', 'actorType' => 'users', 'actorId' => 'alice', 'displayName' => 'Alice'],
			['id' => 'actor:guests:abc', 'actorType' => 'guests', 'actorId' => 'abc', 'displayName' => 'Bob (guest)'],
			['id' => 'actor:guests:gone', 'actorType' => 'guests', 'actorId' => 'gone', 'displayName' => 'Guest 1'],
		], $result['document']['speakers']);
		$this->assertSame(2, $result['document']['version']);
		$this->assertSame([1, 2, 3, 4], array_column($result['document']['turns'], 'id'));
		$this->assertSame(8.0, $result['document']['turns'][0]['start']);
		$this->assertSame(14.5, $result['document']['turns'][1]['start']);
		// Calls of an hour or longer use H:MM:SS for every block.
		$this->assertSame(
			"**Alice** · 0:00:08\nGood morning.\n\n"
			. "**Bob (guest)** · 0:00:14\nThanks\\_a\\_lot\\*\n\n"
			. "**Alice** · 1:01:40\nBack again.\n\n"
			. "**Guest 1** · 1:03:20\nBye.",
			$result['markdown'],
		);
	}
}
