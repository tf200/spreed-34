<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Service;

use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Config;
use OCA\Talk\Exceptions\RecordingArtifactException;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\RecordingArtifact;
use OCA\Talk\Model\RecordingArtifactMapper;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\AttachmentService;
use OCA\Talk\Service\ConversationFolderService;
use OCA\Talk\Service\RecordingArtifactService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Constants;
use OCP\DB\Exception as DbException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class RecordingArtifactServiceTest extends TestCase {
	private RecordingArtifactMapper&MockObject $mapper;
	private IRootFolder&MockObject $rootFolder;
	private ITimeFactory&MockObject $timeFactory;
	private Config&MockObject $config;
	private ConversationFolderService&MockObject $conversationFolderService;
	private ChatManager&MockObject $chatManager;
	private ICommentsManager&MockObject $commentsManager;
	private AttachmentService&MockObject $attachmentService;
	private IShareManager&MockObject $shareManager;
	private ISystemTagObjectMapper&MockObject $systemTagMapper;
	private RecordingArtifactService $service;
	private \DateTime $now;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(RecordingArtifactMapper::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->config = $this->createMock(Config::class);
		$this->conversationFolderService = $this->createMock(ConversationFolderService::class);
		$this->chatManager = $this->createMock(ChatManager::class);
		$this->commentsManager = $this->createMock(ICommentsManager::class);
		$this->attachmentService = $this->createMock(AttachmentService::class);
		$this->shareManager = $this->createMock(IShareManager::class);
		$this->systemTagMapper = $this->createMock(ISystemTagObjectMapper::class);
		$this->now = new \DateTime('2026-07-14T21:00:00+00:00');
		$this->timeFactory->method('getDateTime')->willReturn($this->now);
		$this->service = new RecordingArtifactService(
			$this->mapper,
			$this->rootFolder,
			$this->timeFactory,
			$this->config,
			$this->conversationFolderService,
			$this->chatManager,
			$this->commentsManager,
			$this->attachmentService,
			$this->shareManager,
			$this->systemTagMapper,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testCreateReturnsExistingArtifactAndDeletesDuplicateSource(): void {
		$existing = $this->artifact();
		$source = $this->createMock(File::class);
		$source->method('getId')->willReturn(99);
		$source->expects($this->once())->method('delete');
		$this->mapper->expects($this->once())->method('findByRecordingAndType')->with(10, RecordingArtifact::TYPE_TRANSCRIPT)->willReturn($existing);
		$this->mapper->expects($this->never())->method('insert');

		$this->assertSame($existing, $this->service->create(10, $source, 'owner', 'room', RecordingArtifact::TYPE_TRANSCRIPT));
	}

	public function testCreateRecoversFromUniqueConstraintRace(): void {
		$existing = $this->artifact();
		$source = $this->createMock(File::class);
		$source->method('getId')->willReturn(99);
		$source->method('getEtag')->willReturn('etag');
		$source->expects($this->once())->method('delete');
		$lookups = 0;
		$this->mapper->expects($this->exactly(2))->method('findByRecordingAndType')
			->willReturnCallback(function () use (&$lookups, $existing): RecordingArtifact {
				if ($lookups++ === 0) {
					throw new DoesNotExistException('missing');
				}
				return $existing;
			});
		$exception = $this->createMock(DbException::class);
		$exception->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		$this->mapper->expects($this->once())->method('insert')->willThrowException($exception);

		$this->assertSame($existing, $this->service->create(10, $source, 'owner', 'room', RecordingArtifact::TYPE_TRANSCRIPT));
	}

	public function testGetReconcilesExternalEtagAndFormatsStringIds(): void {
		[$artifact, $file] = $this->resolvableArtifact('stored-etag', 'disk-etag');
		$artifact->setPublishedFileId(456);
		$artifact->setPublishedMessageId(789);
		$file->method('getName')->willReturn('recording.md');
		$file->method('getContent')->willReturn('corrected externally');
		$this->mapper->expects($this->once())->method('reconcileSourceEtag')
			->with('123', 'stored-etag', 'disk-etag', $this->now)->willReturn(true);

		$result = $this->service->get($this->room(), $this->participant(), '123');

		$this->assertSame('123', $result['id']);
		$this->assertSame('disk-etag', $result['etag']);
		$this->assertSame('456', $result['publishedFileId']);
		$this->assertSame('789', $result['publishedMessageId']);
	}

	public function testListFiltersUnresolvableArtifactsAndFormatsItems(): void {
		$valid = $this->artifact();
		$valid->setUpdatedAt(new \DateTime('2026-07-14T20:00:00+00:00'));
		$valid->setNotificationTimestamp(123456);
		$missing = $this->artifact(124);
		$this->mapper->method('findUnpublishedByOwnerAndRoom')->with('owner', 'room')->willReturn([$valid, $missing]);
		$this->mapper->method('findById')->willReturnCallback(fn (string $id) => $id === '123' ? $valid : throw new DoesNotExistException('missing'));
		$file = $this->mockResolvedFile('etag');
		$file->method('getName')->willReturn('recording.md');

		$this->assertSame([[
			'id' => '123',
			'type' => RecordingArtifact::TYPE_TRANSCRIPT,
			'state' => RecordingArtifact::STATE_DRAFT,
			'fileName' => 'recording.md',
			'updatedAt' => 1784059200,
			'notificationTimestamp' => 123456,
		]], $this->service->list($this->room(), $this->participant()));
	}

	public function testUpdateInvokesClaimForExpiredEditingLease(): void {
		[$artifact, $file] = $this->resolvableArtifact('etag', ['etag', 'new-etag', 'new-etag'], false);
		$artifact->setState(RecordingArtifact::STATE_EDITING);
		$updatedArtifact = clone $artifact;
		$updatedArtifact->setState(RecordingArtifact::STATE_DRAFT);
		$updatedArtifact->setSourceEtag('new-etag');
		$this->mapper->method('findById')->with('123')->willReturnOnConsecutiveCalls($artifact, $updatedArtifact);
		$file->expects($this->once())->method('putContent')->with('corrected text');
		$file->method('getContent')->willReturn('corrected text');
		$file->method('getName')->willReturn('recording.md');
		$this->mapper->expects($this->once())->method('claimForEditing')->willReturn(true);
		$this->mapper->expects($this->once())->method('finishEditing')->willReturn(true);

		$this->assertSame('new-etag', $this->service->update($this->room(), $this->participant(), '123', 'corrected text', 'etag')['etag']);
	}

	public function testUpdateRejectsActiveEditingClaim(): void {
		[, $file] = $this->resolvableArtifact('etag', 'etag');
		$file->expects($this->never())->method('putContent');
		$this->mapper->expects($this->once())->method('claimForEditing')->willReturn(false);

		$this->expectExceptionMessage(RecordingArtifactException::EDITING);
		$this->service->update($this->room(), $this->participant(), '123', 'corrected text', 'etag');
	}

	public function testPublishRejectsActivePublishingClaim(): void {
		$this->resolvableArtifact('etag', 'etag');
		$this->mapper->expects($this->once())->method('claimForPublishing')->willReturn(false);

		$this->expectExceptionMessage(RecordingArtifactException::PUBLISHING);
		$this->service->publish($this->room(), $this->participant(), '123', 'etag');
	}

	public function testPublishPersistsFileAndMessageAndReconcilesAttachment(): void {
		[$artifact, $source] = $this->resolvableArtifact('etag', 'etag', false);
		$publishing = clone $artifact;
		$publishing->setState(RecordingArtifact::STATE_PUBLISHING);
		$withFile = clone $publishing;
		$withFile->setPublishedFileId(456);
		$published = clone $withFile;
		$published->setPublishedMessageId(789);
		$published->setState(RecordingArtifact::STATE_PUBLISHED);
		$this->mapper->method('findById')->willReturnOnConsecutiveCalls($artifact, $publishing, $withFile, $published);
		$this->mapper->expects($this->once())->method('claimForPublishing')->willReturn(true);
		$this->config->method('isConversationSubfoldersEnabled')->willReturn(true);
		$target = $this->createMock(Folder::class);
		$draft = $this->createMock(Folder::class);
		$draft->method('nodeExists')->willReturn(false);
		$draft->method('getPath')->willReturn('/draft');
		$this->conversationFolderService->method('getOrCreateSubfolder')->willReturn($target);
		$this->conversationFolderService->method('getOrCreateDraftFolder')->willReturn($draft);
		$staged = $this->createMock(File::class);
		$staged->method('getId')->willReturn(456);
		$source->method('getName')->willReturn('recording.md');
		$source->expects($this->once())->method('copy')->with('/draft/.recording-artifact-publication-123.md')->willReturn($staged);
		$publishedFile = $this->createMock(File::class);
		$publishedFile->method('getId')->willReturn(456);
		$publishedFile->method('getName')->willReturn('recording.md');
		$publishedFile->method('getMimeType')->willReturn('text/markdown');
		$publishedFile->method('getContent')->willReturn('transcript');
		$publishedFile->method('getEtag')->willReturn('published-etag');
		$this->conversationFolderService->method('finalizeUploadedFile')->with($target, $staged, 'recording.md')->willReturn(['node' => $publishedFile]);
		$this->systemTagMapper->expects($this->once())->method('assignGeneratedByAITag')->with('456', 'files');
		$this->mapper->expects($this->exactly(2))->method('updatePublication')->willReturn(true);
		$this->mapper->method('findMessageIdByReference')->with(7, 'recording-artifact-123')->willReturn(null);
		$comment = $this->createMock(IComment::class);
		$comment->method('getId')->willReturn('789');
		$this->chatManager->expects($this->once())->method('addSystemMessage')
			->with($this->anything(), $this->anything(), Attendee::ACTOR_USERS, 'owner', $this->stringContains('"fileId":"456"'), $this->now, true, 'recording-artifact-123')
			->willReturn($comment);
		$this->attachmentService->expects($this->once())->method('ensureAttachmentEntry')
			->with($this->anything(), $comment, 'file_shared', ['metaData' => ['mimeType' => 'text/markdown'], 'fileId' => '456']);
		$this->mapper->expects($this->once())->method('finishPublishing')->willReturn(true);

		$result = $this->service->publish($this->room(), $this->participant(), '123', 'etag');

		$this->assertSame('published', $result['state']);
		$this->assertSame('456', $result['publishedFileId']);
		$this->assertSame('789', $result['publishedMessageId']);
	}

	public function testPublishWithoutConversationSubfoldersCreatesRoomShare(): void {
		[$artifact, $source] = $this->resolvableArtifact('etag', 'etag', false);
		$publishing = clone $artifact;
		$publishing->setState(RecordingArtifact::STATE_PUBLISHING);
		$withFile = clone $publishing;
		$withFile->setPublishedFileId(456);
		$withFileAndShare = clone $publishing;
		$withFileAndShare->setPublishedFileId(456);
		$withFileAndShare->setPublishedShareId(321);
		$published = clone $withFileAndShare;
		$published->setPublishedMessageId(789);
		$published->setState(RecordingArtifact::STATE_PUBLISHED);
		$this->mapper->method('findById')->willReturnOnConsecutiveCalls($artifact, $publishing, $withFile, $withFileAndShare, $published);
		$this->mapper->expects($this->once())->method('claimForPublishing')->willReturn(true);
		$this->config->method('isConversationSubfoldersEnabled')->willReturn(false);

		$sourceFolder = $source->getParent();
		$this->assertInstanceOf(Folder::class, $sourceFolder);
		$sourceFolder->method('nodeExists')->with('.recording-artifact-publication-123.md')->willReturn(false);
		$sourceFolder->method('getPath')->willReturn('/recordings/room');
		$source->method('getName')->willReturn('recording.md');
		$staged = $this->createMock(File::class);
		$staged->method('getId')->willReturn(456);
		$source->expects($this->once())->method('copy')
			->with('/recordings/room/.recording-artifact-publication-123.md')
			->willReturn($staged);
		$publishedFile = $this->createMock(File::class);
		$publishedFile->method('getId')->willReturn(456);
		$publishedFile->method('getName')->willReturn('recording.md');
		$publishedFile->method('getMimeType')->willReturn('text/markdown');
		$publishedFile->method('getContent')->willReturn('transcript');
		$publishedFile->method('getEtag')->willReturn('published-etag');
		$this->conversationFolderService->expects($this->once())->method('finalizeUploadedFile')
			->with($sourceFolder, $staged, 'recording.md')
			->willReturn(['node' => $publishedFile]);

		$share = $this->createMock(IShare::class);
		$share->expects($this->once())->method('setNodeId')->with(456)->willReturnSelf();
		$share->expects($this->once())->method('setShareTime')->with($this->now)->willReturnSelf();
		$share->expects($this->once())->method('setSharedBy')->with('owner')->willReturnSelf();
		$share->expects($this->once())->method('setNode')->with($publishedFile)->willReturnSelf();
		$share->expects($this->once())->method('setShareType')->with(IShare::TYPE_ROOM)->willReturnSelf();
		$share->expects($this->once())->method('setSharedWith')->with('room')->willReturnSelf();
		$share->expects($this->once())->method('setPermissions')->with(Constants::PERMISSION_READ)->willReturnSelf();
		$createdShare = $this->createMock(IShare::class);
		$createdShare->method('getId')->willReturn('321');
		$this->shareManager->expects($this->once())->method('newShare')->willReturn($share);
		$this->shareManager->expects($this->once())->method('getSharesBy')->with('owner', IShare::TYPE_ROOM, $publishedFile)->willReturn([]);
		$this->shareManager->expects($this->once())->method('createShare')->with($share)->willReturn($createdShare);
		$this->systemTagMapper->expects($this->once())->method('assignGeneratedByAITag')->with('456', 'files');

		$publicationUpdates = 0;
		$this->mapper->expects($this->exactly(3))->method('updatePublication')
			->willReturnCallback(function (string $id, string $claimToken, ?int $fileId, ?int $shareId, ?int $messageId, \DateTime $updatedAt) use (&$publicationUpdates): bool {
				$this->assertSame('123', $id);
				$this->assertNotSame('', $claimToken);
				$this->assertSame(456, $fileId);
				$this->assertSame($publicationUpdates === 0 ? null : 321, $shareId);
				$this->assertSame($publicationUpdates++ === 2 ? 789 : null, $messageId);
				$this->assertSame($this->now, $updatedAt);
				return true;
			});
		$this->mapper->method('findMessageIdByReference')->with(7, 'recording-artifact-123')->willReturn(null);
		$comment = $this->createMock(IComment::class);
		$comment->method('getId')->willReturn('789');
		$this->chatManager->expects($this->once())->method('addSystemMessage')
			->with($this->anything(), $this->anything(), Attendee::ACTOR_USERS, 'owner', $this->callback(
				fn (string $message): bool => str_contains($message, '"share":"321"') && !str_contains($message, 'fileId')
			), $this->now, true, 'recording-artifact-123')
			->willReturn($comment);
		$this->attachmentService->expects($this->once())->method('ensureAttachmentEntry')
			->with($this->anything(), $comment, 'file_shared', ['metaData' => ['mimeType' => 'text/markdown'], 'share' => '321']);
		$this->mapper->expects($this->once())->method('finishPublishing')->willReturn(true);

		$result = $this->service->publish($this->room(), $this->participant(), '123', 'etag');

		$this->assertSame(3, $publicationUpdates);
		$this->assertSame(RecordingArtifact::STATE_PUBLISHED, $result['state']);
		$this->assertSame('456', $result['publishedFileId']);
		$this->assertSame('789', $result['publishedMessageId']);
	}

	public function testPublishRecoversPersistedFileAndExistingMessage(): void {
		$artifact = $this->artifact();
		$source = $this->createMock(File::class);
		$source->method('getEtag')->willReturn('etag');
		$parent = $this->createMock(Folder::class);
		$parent->method('getName')->willReturn('room');
		$source->method('getParent')->willReturn($parent);
		$artifact->setPublishedFileId(456);
		$publishing = clone $artifact;
		$publishing->setState(RecordingArtifact::STATE_PUBLISHING);
		$published = clone $publishing;
		$published->setPublishedMessageId(789);
		$published->setState(RecordingArtifact::STATE_PUBLISHED);
		$this->mapper->method('findById')->willReturnOnConsecutiveCalls($artifact, $publishing, $published);
		$this->mapper->method('claimForPublishing')->willReturn(true);
		$this->config->method('isConversationSubfoldersEnabled')->willReturn(true);
		$publishedFile = $this->createMock(File::class);
		$publishedFile->method('getId')->willReturn(456);
		$publishedFile->method('getName')->willReturn('recording.md');
		$publishedFile->method('getMimeType')->willReturn('text/markdown');
		$publishedFile->method('getContent')->willReturn('transcript');
		$publishedFile->method('getEtag')->willReturn('published-etag');
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturnCallback(fn (int $id) => $id === 11 ? [$source] : [$publishedFile]);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);
		$this->conversationFolderService->expects($this->never())->method('finalizeUploadedFile');
		$this->mapper->method('findMessageIdByReference')->willReturn(789);
		$comment = $this->createMock(IComment::class);
		$comment->method('getId')->willReturn('789');
		$this->commentsManager->expects($this->once())->method('get')->with('789')->willReturn($comment);
		$this->chatManager->expects($this->never())->method('addSystemMessage');
		$this->attachmentService->expects($this->once())->method('ensureAttachmentEntry');
		$this->mapper->expects($this->once())->method('updatePublication')->willReturn(true);
		$this->mapper->expects($this->once())->method('finishPublishing')->willReturn(true);

		$this->assertSame('published', $this->service->publish($this->room(), $this->participant(), '123', 'etag')['state']);
	}

	private function artifact(int $id = 123, string $owner = 'owner'): RecordingArtifact {
		$artifact = new RecordingArtifact();
		$artifact->id = (string)$id;
		$artifact->setRecordingFileId(10);
		$artifact->setSourceFileId(11);
		$artifact->setOwnerId($owner);
		$artifact->setRoomToken('room');
		$artifact->setType(RecordingArtifact::TYPE_TRANSCRIPT);
		$artifact->setState(RecordingArtifact::STATE_DRAFT);
		$artifact->setSourceEtag('etag');
		$artifact->setUpdatedAt($this->now);
		return $artifact;
	}

	/** @return array{RecordingArtifact, File&MockObject} */
	private function resolvableArtifact(string $storedEtag, string|array $fileEtag, bool $configureMapper = true): array {
		$artifact = $this->artifact();
		$artifact->setSourceEtag($storedEtag);
		if ($configureMapper) {
			$this->mapper->method('findById')->with('123')->willReturn($artifact);
		}
		return [$artifact, $this->mockResolvedFile($fileEtag)];
	}

	private function mockResolvedFile(string|array $etag): File&MockObject {
		$file = $this->createMock(File::class);
		if (is_array($etag)) {
			$file->method('getEtag')->willReturnOnConsecutiveCalls(...$etag);
		} else {
			$file->method('getEtag')->willReturn($etag);
		}
		$parent = $this->createMock(Folder::class);
		$parent->method('getName')->willReturn('room');
		$file->method('getParent')->willReturn($parent);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with(11)->willReturn([$file]);
		$this->rootFolder->method('getUserFolder')->with('owner')->willReturn($userFolder);
		return $file;
	}

	private function room(): Room&MockObject {
		$room = $this->createMock(Room::class);
		$room->method('getId')->willReturn(7);
		$room->method('getToken')->willReturn('room');
		return $room;
	}

	private function participant(): Participant&MockObject {
		$attendee = new Attendee();
		$attendee->setActorType(Attendee::ACTOR_USERS);
		$attendee->setActorId('owner');
		$participant = $this->createMock(Participant::class);
		$participant->method('getAttendee')->willReturn($attendee);
		return $participant;
	}
}
