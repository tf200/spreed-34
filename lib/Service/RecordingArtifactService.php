<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Service;

use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Config;
use OCA\Talk\Exceptions\RecordingArtifactException;
use OCA\Talk\Model\RecordingArtifact;
use OCA\Talk\Model\RecordingArtifactMapper;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Constants;
use OCP\DB\Exception as DbException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotFoundException;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Log\LoggerInterface;

class RecordingArtifactService {
	private const MAX_CONTENT_BYTES = 5 * 1024 * 1024;

	public function __construct(
		private readonly RecordingArtifactMapper $mapper,
		private readonly IRootFolder $rootFolder,
		private readonly ITimeFactory $timeFactory,
		private readonly Config $config,
		private readonly ConversationFolderService $conversationFolderService,
		private readonly ChatManager $chatManager,
		private readonly ICommentsManager $commentsManager,
		private readonly AttachmentService $attachmentService,
		private readonly IShareManager $shareManager,
		private readonly ISystemTagObjectMapper $systemTagMapper,
		private readonly LoggerInterface $logger,
	) {
	}

	public function findExisting(int $recordingFileId, string $type): ?RecordingArtifact {
		try {
			return $this->mapper->findByRecordingAndType($recordingFileId, $type);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function create(
		int $recordingFileId,
		File $sourceFile,
		string $ownerId,
		string $roomToken,
		string $type,
	): RecordingArtifact {
		$existing = $this->findExisting($recordingFileId, $type);
		if ($existing !== null) {
			$this->discardDuplicateSource($existing, $sourceFile);
			return $existing;
		}

		$now = $this->timeFactory->getDateTime();
		$artifact = new RecordingArtifact();
		$artifact->setRecordingFileId($recordingFileId);
		$artifact->setSourceFileId($sourceFile->getId());
		$artifact->setOwnerId($ownerId);
		$artifact->setRoomToken($roomToken);
		$artifact->setType($type);
		$artifact->setState(RecordingArtifact::STATE_DRAFT);
		$artifact->setSourceEtag($sourceFile->getEtag());
		$artifact->setNotificationTimestamp($now->getTimestamp());
		$artifact->setUpdatedAt($now);
		try {
			return $this->mapper->insert($artifact);
		} catch (DbException $e) {
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			$existing = $this->mapper->findByRecordingAndType($recordingFileId, $type);
			$this->discardDuplicateSource($existing, $sourceFile);
			return $existing;
		}
	}

	/** @return list<array{id: string, type: string, state: string, fileName: string, updatedAt: int, notificationTimestamp: int}> */
	public function list(Room $room, Participant $participant): array {
		$ownerId = $participant->getAttendee()->getActorId();
		$items = [];
		foreach ($this->mapper->findUnpublishedByOwnerAndRoom($ownerId, $room->getToken()) as $artifact) {
			try {
				[, $file] = $this->resolveDraft($room, $participant, (string)$artifact->getId(), false);
			} catch (RecordingArtifactException) {
				continue;
			}
			$items[] = [
				'id' => (string)$artifact->getId(),
				'type' => $artifact->getType(),
				'state' => $artifact->getState(),
				'fileName' => $file->getName(),
				'updatedAt' => $artifact->getUpdatedAt()->getTimestamp(),
				'notificationTimestamp' => $artifact->getNotificationTimestamp(),
			];
		}
		return $items;
	}

	/** @return array{id: string, type: string, state: string, fileName: string, content: string, etag: string, publishedFileId: ?string, publishedMessageId: ?string} */
	public function get(Room $room, Participant $participant, string $artifactId): array {
		[$artifact, $file] = $this->resolveDraft($room, $participant, $artifactId);
		if ($artifact->getState() === RecordingArtifact::STATE_PUBLISHED) {
			$file = $this->resolvePublishedFile($artifact);
		}
		return $this->format($artifact, $file);
	}

	/** @return array{id: string, type: string, state: string, fileName: string, content: string, etag: string, publishedFileId: ?string, publishedMessageId: ?string} */
	public function update(Room $room, Participant $participant, string $artifactId, string $content, string $etag): array {
		$this->validateContent($content);
		[$artifact, $file] = $this->resolveDraft($room, $participant, $artifactId, false);
		$this->assertEditableState($artifact);
		if (!hash_equals($file->getEtag(), $etag) || !hash_equals($artifact->getSourceEtag(), $etag)) {
			throw new RecordingArtifactException(RecordingArtifactException::STALE_REVISION);
		}

		$claimToken = bin2hex(random_bytes(16));
		if (!$this->mapper->claimForEditing($artifactId, $artifact->getOwnerId(), $etag, $claimToken, $this->timeFactory->getDateTime())) {
			throw new RecordingArtifactException(RecordingArtifactException::EDITING);
		}

		try {
			$file->putContent($content);
			$newEtag = $file->getEtag();
			if (!$this->mapper->finishEditing($artifactId, $artifact->getOwnerId(), $claimToken, $newEtag, $this->timeFactory->getDateTime())) {
				throw new \RuntimeException('Could not finish artifact edit');
			}
			$artifact = $this->mapper->findById($artifactId);
			return $this->format($artifact, $file);
		} catch (\Throwable $e) {
			try {
				$this->mapper->releaseClaim($artifactId, $claimToken, $file->getEtag(), $this->timeFactory->getDateTime());
			} catch (\Throwable $rollbackError) {
				$this->logger->error('Could not release recording artifact edit claim', ['exception' => $rollbackError]);
			}
			$this->logger->error('Could not update recording artifact', ['exception' => $e]);
			throw new RecordingArtifactException(RecordingArtifactException::STORAGE);
		}
	}

	/** @return array{id: string, type: string, state: string, fileName: string, content: string, etag: string, publishedFileId: ?string, publishedMessageId: ?string} */
	public function publish(Room $room, Participant $participant, string $artifactId, string $etag): array {
		[$artifact, $sourceFile] = $this->resolveDraft($room, $participant, $artifactId, false);
		if ($artifact->getState() === RecordingArtifact::STATE_PUBLISHED) {
			return $this->format($artifact, $this->resolvePublishedFile($artifact));
		}
		if ($artifact->getState() === RecordingArtifact::STATE_EDITING) {
			throw new RecordingArtifactException(RecordingArtifactException::EDITING);
		}
		if (!hash_equals($artifact->getSourceEtag(), $etag) || !hash_equals($sourceFile->getEtag(), $etag)) {
			throw new RecordingArtifactException(RecordingArtifactException::STALE_REVISION);
		}

		$claimToken = bin2hex(random_bytes(16));
		if (!$this->mapper->claimForPublishing($artifactId, $artifact->getOwnerId(), $etag, $claimToken, $this->timeFactory->getDateTime())) {
			throw new RecordingArtifactException(RecordingArtifactException::PUBLISHING);
		}
		$artifact = $this->mapper->findById($artifactId);

		try {
			[$artifact, $publishedFile, $messageParameters] = $this->ensurePublishedFile($room, $artifact, $sourceFile, $claimToken);
			$referenceId = 'recording-artifact-' . $artifactId;
			$comment = $this->findPublishedComment($room, $referenceId);
			if ($comment === null) {
				$message = json_encode(['message' => 'file_shared', 'parameters' => $messageParameters], JSON_THROW_ON_ERROR);
				try {
					$comment = $this->chatManager->addSystemMessage(
						$room,
						$participant,
						$participant->getAttendee()->getActorType(),
						$artifact->getOwnerId(),
						$message,
						$this->timeFactory->getDateTime(),
						true,
						$referenceId,
					);
				} catch (\Throwable $e) {
					$comment = $this->findPublishedComment($room, $referenceId);
					if ($comment === null) {
						throw $e;
					}
				}
			}

			$this->attachmentService->ensureAttachmentEntry($room, $comment, 'file_shared', $messageParameters);
			if (!$this->mapper->updatePublication(
				$artifactId,
				$claimToken,
				$publishedFile->getId(),
				$artifact->getPublishedShareId(),
				(int)$comment->getId(),
				$this->timeFactory->getDateTime(),
			)) {
				throw new \RuntimeException('Could not persist artifact publication message');
			}
			if (!$this->mapper->finishPublishing($artifactId, $claimToken, $this->timeFactory->getDateTime())) {
				throw new \RuntimeException('Could not finish artifact publication');
			}
			$artifact = $this->mapper->findById($artifactId);
			return $this->format($artifact, $publishedFile);
		} catch (\Throwable $e) {
			// Keep partial publication metadata. A stale lease can safely resume it.
			$this->logger->error('Could not publish recording artifact', ['exception' => $e]);
			throw new RecordingArtifactException($e instanceof NotEnoughSpaceException
				? RecordingArtifactException::QUOTA
				: RecordingArtifactException::STORAGE);
		}
	}

	/** @return array{RecordingArtifact, File, array<string, mixed>} */
	private function ensurePublishedFile(Room $room, RecordingArtifact $artifact, File $sourceFile, string $claimToken): array {
		if ($artifact->getPublishedFileId() !== null) {
			$file = $this->resolvePublishedFile($artifact);
			if (str_starts_with($file->getName(), '.recording-artifact-publication-')) {
				$targetFolder = $this->getPublicationTargetFolder($room, $artifact, $sourceFile);
				$result = $this->conversationFolderService->finalizeUploadedFile($targetFolder, $file, $sourceFile->getName());
				$file = $result['node'];
				if (!$file instanceof File) {
					throw new \RuntimeException('Published artifact is not a file');
				}
			}
			$this->systemTagMapper->assignGeneratedByAITag((string)$file->getId(), 'files');
			$artifact = $this->ensurePublishedShare($room, $artifact, $file, $claimToken);
			return [$artifact, $file, $this->messageParameters($artifact, $file)];
		}

		$targetFolder = $this->getPublicationTargetFolder($room, $artifact, $sourceFile);
		if ($this->config->isConversationSubfoldersEnabled()) {
			$draftFolder = $this->conversationFolderService->getOrCreateDraftFolder($targetFolder);
		} else {
			$draftFolder = $targetFolder;
		}

		$tempName = '.recording-artifact-publication-' . $artifact->getId() . '.md';
		if ($draftFolder->nodeExists($tempName)) {
			$stagedFile = $draftFolder->get($tempName);
			if (!$stagedFile instanceof File) {
				throw new \RuntimeException('Artifact staging path is not a file');
			}
		} else {
			$stagedFile = $sourceFile->copy($draftFolder->getPath() . '/' . $tempName);
		}
		if (!$this->mapper->updatePublication(
			(string)$artifact->getId(),
			$claimToken,
			$stagedFile->getId(),
			null,
			null,
			$this->timeFactory->getDateTime(),
		)) {
			throw new \RuntimeException('Could not persist artifact publication file');
		}
		$artifact = $this->mapper->findById((string)$artifact->getId());
		$result = $this->conversationFolderService->finalizeUploadedFile($targetFolder, $stagedFile, $sourceFile->getName());
		$publishedFile = $result['node'];
		if (!$publishedFile instanceof File) {
			throw new \RuntimeException('Published artifact is not a file');
		}
		$this->systemTagMapper->assignGeneratedByAITag((string)$publishedFile->getId(), 'files');
		$artifact = $this->ensurePublishedShare($room, $artifact, $publishedFile, $claimToken);
		return [$artifact, $publishedFile, $this->messageParameters($artifact, $publishedFile)];
	}

	private function getPublicationTargetFolder(Room $room, RecordingArtifact $artifact, File $sourceFile): Folder {
		if ($this->config->isConversationSubfoldersEnabled()) {
			return $this->conversationFolderService->getOrCreateSubfolder($artifact->getOwnerId(), $room);
		}
		$targetFolder = $sourceFile->getParent();
		if (!$targetFolder instanceof Folder) {
			throw new \RuntimeException('Artifact source parent is not a folder');
		}
		return $targetFolder;
	}

	private function ensurePublishedShare(Room $room, RecordingArtifact $artifact, File $file, string $claimToken): RecordingArtifact {
		if ($this->config->isConversationSubfoldersEnabled() || $artifact->getPublishedShareId() !== null) {
			return $artifact;
		}

		$shareId = null;
		foreach ($this->shareManager->getSharesBy($artifact->getOwnerId(), IShare::TYPE_ROOM, $file) as $share) {
			if ($share->getSharedWith() === $room->getToken()) {
				$shareId = (int)$share->getId();
				break;
			}
		}
		if ($shareId === null) {
			$share = $this->shareManager->newShare();
			$share->setNodeId($file->getId())
				->setShareTime($this->timeFactory->getDateTime())
				->setSharedBy($artifact->getOwnerId())
				->setNode($file)
				->setShareType(IShare::TYPE_ROOM)
				->setSharedWith($room->getToken())
				->setPermissions(Constants::PERMISSION_READ);
			$shareId = (int)$this->shareManager->createShare($share)->getId();
		}

		if (!$this->mapper->updatePublication(
			(string)$artifact->getId(),
			$claimToken,
			$file->getId(),
			$shareId,
			$artifact->getPublishedMessageId(),
			$this->timeFactory->getDateTime(),
		)) {
			throw new \RuntimeException('Could not persist artifact publication share');
		}
		return $this->mapper->findById((string)$artifact->getId());
	}

	/** @return array<string, mixed> */
	private function messageParameters(RecordingArtifact $artifact, File $file): array {
		$parameters = [
			'metaData' => ['mimeType' => $file->getMimeType()],
		];
		if ($artifact->getPublishedShareId() !== null) {
			$parameters['share'] = (string)$artifact->getPublishedShareId();
		} else {
			$parameters['fileId'] = (string)$file->getId();
		}
		return $parameters;
	}

	private function findPublishedComment(Room $room, string $referenceId): ?IComment {
		$messageId = $this->mapper->findMessageIdByReference($room->getId(), $referenceId);
		if ($messageId === null) {
			return null;
		}
		try {
			return $this->commentsManager->get((string)$messageId);
		} catch (\Throwable) {
			return null;
		}
	}

	/** @return array{RecordingArtifact, File} */
	private function resolveDraft(Room $room, Participant $participant, string $artifactId, bool $reconcileEtag = true): array {
		try {
			$artifact = $this->mapper->findById($artifactId);
		} catch (DoesNotExistException) {
			throw new RecordingArtifactException(RecordingArtifactException::NOT_FOUND);
		}

		$ownerId = $participant->getAttendee()->getActorId();
		if ($artifact->getOwnerId() !== $ownerId || $artifact->getRoomToken() !== $room->getToken()) {
			throw new RecordingArtifactException(RecordingArtifactException::NOT_FOUND);
		}

		try {
			$nodes = $this->rootFolder->getUserFolder($ownerId)->getById($artifact->getSourceFileId());
			$file = array_shift($nodes);
			if (!$file instanceof File || $file->getParent()->getName() !== $room->getToken()) {
				throw new NotFoundException();
			}
		} catch (\Throwable) {
			throw new RecordingArtifactException(RecordingArtifactException::NOT_FOUND);
		}

		if ($reconcileEtag) {
			$currentEtag = $file->getEtag();
			if ($artifact->getState() === RecordingArtifact::STATE_DRAFT
				&& !hash_equals($artifact->getSourceEtag(), $currentEtag)
				&& $this->mapper->reconcileSourceEtag((string)$artifact->getId(), $artifact->getSourceEtag(), $currentEtag, $this->timeFactory->getDateTime())) {
				$artifact->setSourceEtag($currentEtag);
			}
		}
		return [$artifact, $file];
	}

	private function resolvePublishedFile(RecordingArtifact $artifact): File {
		if ($artifact->getPublishedFileId() === null) {
			throw new RecordingArtifactException(RecordingArtifactException::NOT_FOUND);
		}
		try {
			$nodes = $this->rootFolder->getUserFolder($artifact->getOwnerId())->getById($artifact->getPublishedFileId());
			$file = array_shift($nodes);
			if (!$file instanceof File) {
				throw new NotFoundException();
			}
			return $file;
		} catch (\Throwable) {
			throw new RecordingArtifactException(RecordingArtifactException::NOT_FOUND);
		}
	}

	private function assertEditableState(RecordingArtifact $artifact): void {
		if ($artifact->getState() === RecordingArtifact::STATE_PUBLISHED) {
			throw new RecordingArtifactException(RecordingArtifactException::PUBLISHED);
		}
		if ($artifact->getState() === RecordingArtifact::STATE_PUBLISHING) {
			throw new RecordingArtifactException(RecordingArtifactException::PUBLISHING);
		}
	}

	private function validateContent(string $content): void {
		if (strlen($content) > self::MAX_CONTENT_BYTES) {
			throw new RecordingArtifactException(RecordingArtifactException::CONTENT_TOO_LARGE);
		}
		if ($content === '' || !mb_check_encoding($content, 'UTF-8')) {
			throw new RecordingArtifactException(RecordingArtifactException::CONTENT);
		}
	}

	private function discardDuplicateSource(RecordingArtifact $artifact, File $sourceFile): void {
		if ($artifact->getSourceFileId() === $sourceFile->getId()) {
			return;
		}
		try {
			$sourceFile->delete();
		} catch (\Throwable $e) {
			$this->logger->warning('Could not delete duplicate recording artifact source', ['exception' => $e]);
		}
	}

	/** @return array{id: string, type: string, state: string, fileName: string, content: string, etag: string, publishedFileId: ?string, publishedMessageId: ?string} */
	private function format(RecordingArtifact $artifact, File $file): array {
		return [
			'id' => (string)$artifact->getId(),
			'type' => $artifact->getType(),
			'state' => $artifact->getState(),
			'fileName' => $file->getName(),
			'content' => $file->getContent(),
			'etag' => $file->getEtag(),
			'publishedFileId' => $artifact->getPublishedFileId() === null ? null : (string)$artifact->getPublishedFileId(),
			'publishedMessageId' => $artifact->getPublishedMessageId() === null ? null : (string)$artifact->getPublishedMessageId(),
		];
	}
}
