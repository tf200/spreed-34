<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\Recording;

use OCA\Talk\Exceptions\RecordingArtifactException;
use OCA\Talk\Manager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\RecordingArtifact;
use OCA\Talk\Participant;
use OCA\Talk\Recording\GoogleApiException;
use OCA\Talk\Recording\GoogleGeminiClient;
use OCA\Talk\Recording\ParticipantTracksStore;
use OCA\Talk\Recording\RecordingSummaryService;
use OCA\Talk\Room;
use OCA\Talk\Service\RecordingArtifactService;
use OCA\Talk\Service\RecordingSummaryTemplateService;
use OCP\Config\IUserConfig;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class RecordingSummaryServiceTest extends TestCase {
	private const TRANSCRIPT = "**Alice** · 00:04\nHello\n\n**Bob\\_B** · 12:31\nBye\n\n**Alice** · 13:02\nThanks";
	private const SNAPSHOT = ['id' => 'builtin-standup', 'name' => 'Stand-up', 'instructions' => 'Per person.'];

	private GoogleGeminiClient&MockObject $gemini;
	private ParticipantTracksStore&MockObject $tracksStore;
	private RecordingSummaryTemplateService&MockObject $templateService;
	private RecordingArtifactService&MockObject $artifactService;
	private Manager&MockObject $manager;
	private IRootFolder&MockObject $rootFolder;
	private RecordingSummaryService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->gemini = $this->createMock(GoogleGeminiClient::class);
		$this->tracksStore = $this->createMock(ParticipantTracksStore::class);
		$this->templateService = $this->createMock(RecordingSummaryTemplateService::class);
		$this->artifactService = $this->createMock(RecordingArtifactService::class);
		$this->manager = $this->createMock(Manager::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$userConfig = $this->createMock(IUserConfig::class);
		$userConfig->method('getValueString')->with('owner', 'core', 'timezone')->willReturn('Europe/Amsterdam');
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->willReturn($l);

		$this->service = new RecordingSummaryService(
			$this->gemini,
			$this->tracksStore,
			$this->templateService,
			$this->artifactService,
			$this->manager,
			$this->rootFolder,
			$userConfig,
			$this->createMock(IUserManager::class),
			$l10nFactory,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testSummarizesWithMeetingDetails(): void {
		$room = $this->createMock(Room::class);
		$room->method('getDisplayName')->with('owner')->willReturn('Weekly sync');
		$this->manager->method('getRoomForUserByToken')->with('room', 'owner')->willReturn($room);
		$this->mockRecording();
		$this->gemini->expects($this->once())->method('summarize')->with(
			self::TRANSCRIPT,
			'Per person.',
			"Conversation: Weekly sync\nDate: 2026-10-05 (Monday)\nDuration: about 13 minutes\nSpeakers: Alice, Bob_B",
		)->willReturn('Summary');

		$this->assertSame('Summary', $this->service->generate('owner', 'room', 123, self::TRANSCRIPT, 'Per person.'));
	}

	public function testRegenerateReplacesDraftAndRemembersTemplate(): void {
		$this->templateService->method('snapshot')->with('builtin-standup', 'owner')->willReturn(self::SNAPSHOT);
		$this->tracksStore->method('getTranscriptMarkdown')->with(123)->willReturn(self::TRANSCRIPT);
		$this->manager->method('getRoomForUserByToken')->willThrowException(new \OCA\Talk\Exceptions\RoomNotFoundException());
		$this->mockRecording();
		$this->gemini->method('summarize')->willReturn('New summary');
		$this->templateService->expects($this->once())->method('replaceSnapshot')->with(123, 'owner', self::SNAPSHOT);

		$this->assertSame("New summary\n\nSummary is AI generated and may contain mistakes\n", $this->regenerate());
	}

	public function testRegenerateFailsWithoutStoredTranscript(): void {
		$this->templateService->method('snapshot')->willReturn(self::SNAPSHOT);
		$this->tracksStore->method('getTranscriptMarkdown')->willThrowException(new NotFoundException());
		$this->gemini->expects($this->never())->method('summarize');
		$this->expectException(RecordingArtifactException::class);
		$this->expectExceptionMessage(RecordingArtifactException::TRANSCRIPT_UNAVAILABLE);

		$this->regenerate();
	}

	public function testRegenerateKeepsTemplateWhenGenerationFails(): void {
		$this->templateService->method('snapshot')->willReturn(self::SNAPSHOT);
		$this->tracksStore->method('getTranscriptMarkdown')->willReturn(self::TRANSCRIPT);
		$this->manager->method('getRoomForUserByToken')->willThrowException(new \OCA\Talk\Exceptions\RoomNotFoundException());
		$this->rootFolder->method('getUserFolder')->willThrowException(new \OCP\Files\NotPermittedException());
		$this->gemini->method('summarize')->willThrowException(new GoogleApiException('Gemini summary request failed'));
		$this->templateService->expects($this->never())->method('replaceSnapshot');
		$this->expectException(RecordingArtifactException::class);
		$this->expectExceptionMessage(RecordingArtifactException::GENERATION);

		$this->regenerate();
	}

	public function testPreviewRejectsInvalidDefinitionBeforeCallingGemini(): void {
		$this->gemini->expects($this->never())->method('summarize');
		$this->expectException(\InvalidArgumentException::class);

		$this->service->preview(['sections' => []]);
	}

	/**
	 * Calls the generator like the artifact service does.
	 */
	private function regenerate(): string {
		$artifact = new RecordingArtifact();
		$artifact->setRecordingFileId(123);
		$artifact->setRoomToken('room');
		$generated = '';
		$this->artifactService->method('regenerate')->willReturnCallback(function (Room $room, Participant $participant, string $artifactId, string $etag, callable $generate) use ($artifact, &$generated): array {
			$this->assertSame(['5', 'etag'], [$artifactId, $etag]);
			$generated = $generate($artifact);
			return [];
		});
		$attendee = Attendee::fromRow(['actor_type' => Attendee::ACTOR_USERS, 'actor_id' => 'owner']);
		$participant = new Participant($this->createMock(Room::class), $attendee, null);

		$this->service->regenerate($this->createMock(Room::class), $participant, '5', 'etag', 'builtin-standup');
		return $generated;
	}

	private function mockRecording(): void {
		$recording = $this->createMock(File::class);
		// 2026-10-04 22:30 UTC is already Monday in Amsterdam.
		$recording->method('getMTime')->willReturn((new \DateTimeImmutable('2026-10-04T22:30:00+00:00'))->getTimestamp());
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')->with(123)->willReturn($recording);
		$this->rootFolder->method('getUserFolder')->with('owner')->willReturn($userFolder);
	}
}
