<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Service;

use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Config;
use OCA\Talk\Exceptions\RecordingArtifactConversionException;
use OCA\Talk\Exceptions\RecordingArtifactException;
use OCA\Talk\Model\RecordingArtifact;
use OCA\Talk\Model\RecordingArtifactMapper;
use OCA\Talk\Participant;
use OCA\Talk\Recording\EuroOfficePdfConverter;
use OCA\Talk\Recording\ProjectRecordingFolderProvider;
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
		private readonly EuroOfficePdfConverter $pdfConverter,
		private readonly ProjectRecordingFolderProvider $projectRecordingFolderProvider,
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
		$artifact = $this->resolveArtifact($room, $participant, $artifactId);
		if ($artifact->getState() === RecordingArtifact::STATE_PUBLISHED) {
			return $this->formatAndCleanupPublished($artifact);
		}
		[$artifact, $file] = $this->resolveDraft($room, $participant, $artifactId);
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

	/**
	 * Replaces the content of a summary draft with a generated one.
	 *
	 * The draft is locked like during an edit while the content is generated,
	 * so it can not be edited or published meanwhile.
	 *
	 * @param callable(RecordingArtifact): string $generate
	 * @return array{id: string, type: string, state: string, fileName: string, content: string, etag: string, publishedFileId: ?string, publishedMessageId: ?string}
	 */
	public function regenerate(Room $room, Participant $participant, string $artifactId, string $etag, callable $generate): array {
		[$artifact, $file] = $this->resolveDraft($room, $participant, $artifactId, false);
		if ($artifact->getType() !== RecordingArtifact::TYPE_SUMMARY) {
			throw new RecordingArtifactException(RecordingArtifactException::TYPE);
		}
		$this->assertEditableState($artifact);
		if (!hash_equals($file->getEtag(), $etag) || !hash_equals($artifact->getSourceEtag(), $etag)) {
			throw new RecordingArtifactException(RecordingArtifactException::STALE_REVISION);
		}

		$claimToken = bin2hex(random_bytes(16));
		if (!$this->mapper->claimForEditing($artifactId, $artifact->getOwnerId(), $etag, $claimToken, $this->timeFactory->getDateTime())) {
			throw new RecordingArtifactException(RecordingArtifactException::EDITING);
		}

		try {
			$content = $generate($artifact);
			$this->validateContent($content);
			$file->putContent($content);
			if (!$this->mapper->finishEditing($artifactId, $artifact->getOwnerId(), $claimToken, $file->getEtag(), $this->timeFactory->getDateTime())) {
				throw new \RuntimeException('Could not finish artifact regeneration');
			}
			return $this->format($this->mapper->findById($artifactId), $file);
		} catch (\Throwable $e) {
			try {
				$this->mapper->releaseClaim($artifactId, $claimToken, $file->getEtag(), $this->timeFactory->getDateTime());
			} catch (\Throwable $rollbackError) {
				$this->logger->error('Could not release recording artifact regeneration claim', ['exception' => $rollbackError]);
			}
			if ($e instanceof RecordingArtifactException) {
				throw $e;
			}
			$this->logger->error('Could not regenerate recording artifact', ['exception' => $e]);
			throw new RecordingArtifactException(RecordingArtifactException::STORAGE);
		}
	}

	/** @return array{id: string, type: string, state: string, fileName: string, content: string, etag: string, publishedFileId: ?string, publishedMessageId: ?string} */
	public function publish(Room $room, Participant $participant, string $artifactId, string $etag): array {
		$artifact = $this->resolveArtifact($room, $participant, $artifactId);
		if ($artifact->getState() === RecordingArtifact::STATE_PUBLISHED) {
			return $this->formatAndCleanupPublished($artifact);
		}
		$sourceFile = $this->resolveSourceFile($artifact);
		if (!$sourceFile instanceof File || $sourceFile->getParent()->getName() !== $room->getToken()) {
			throw new RecordingArtifactException(RecordingArtifactException::NOT_FOUND);
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
			$result = $this->formatPublished($artifact, $publishedFile, $sourceFile);
			$this->deletePublishedSource($artifact, $sourceFile);
			return $result;
		} catch (\Throwable $e) {
			$hasPersistedFile = $artifact->getPublishedFileId() !== null;
			if (!$hasPersistedFile && !($e instanceof RecordingArtifactConversionException)) {
				try {
					$hasPersistedFile = $this->mapper->findById($artifactId)->getPublishedFileId() !== null;
				} catch (\Throwable) {
					$hasPersistedFile = true;
				}
			}
			if (!$hasPersistedFile) {
				try {
					$this->mapper->releaseClaim($artifactId, $claimToken, $sourceFile->getEtag(), $this->timeFactory->getDateTime());
				} catch (\Throwable $releaseError) {
					$this->logger->error('Could not release recording artifact publication claim', ['exception' => $releaseError]);
				}
			}
			// Keep persisted publication metadata so a stale lease can safely resume it.
			$this->logger->error('Could not publish recording artifact', ['exception' => $e]);
			$reason = match (true) {
				$e instanceof RecordingArtifactConversionException => RecordingArtifactException::CONVERSION,
				$e instanceof NotEnoughSpaceException => RecordingArtifactException::QUOTA,
				default => RecordingArtifactException::STORAGE,
			};
			throw new RecordingArtifactException($reason);
		}
	}

	/** @return array{RecordingArtifact, File, array<string, mixed>} */
	private function ensurePublishedFile(Room $room, RecordingArtifact $artifact, File $sourceFile, string $claimToken): array {
		if ($artifact->getPublishedFileId() !== null) {
			$file = $this->resolvePublishedFile($artifact);
			$fileParent = $file->getParent();
			$sourceParent = $sourceFile->getParent();
			if (!$fileParent instanceof Folder || !$sourceParent instanceof Folder) {
				throw new \RuntimeException('Artifact publication parent is not a folder');
			}
			$skipRoomShare = $fileParent->getId() !== $sourceParent->getId();
			if (str_starts_with($file->getName(), '.recording-artifact-publication-')) {
				$targetFolder = $fileParent->getName() === 'Draft'
					? $this->conversationFolderService->getOrCreateSubfolder($artifact->getOwnerId(), $room)
					: $fileParent;
				$publishedName = pathinfo($sourceFile->getName(), PATHINFO_FILENAME) . '.pdf';
				$result = $this->conversationFolderService->finalizeUploadedFile($targetFolder, $file, $publishedName);
				$file = $result['node'];
				if (!$file instanceof File) {
					throw new \RuntimeException('Published artifact is not a file');
				}
			}
			$this->assignGeneratedByAiTag($file);
			$artifact = $this->ensurePublishedShare($room, $artifact, $file, $claimToken, $skipRoomShare);
			return [$artifact, $file, $this->messageParameters($artifact, $file)];
		}

		[$targetFolder, $isProjectFolder] = $this->getPublicationTargetFolder($room, $artifact, $sourceFile);
		if (!$isProjectFolder && $this->config->isConversationSubfoldersEnabled()) {
			$draftFolder = $this->conversationFolderService->getOrCreateDraftFolder($targetFolder);
		} else {
			$draftFolder = $targetFolder;
		}

		$sourceRevision = substr(hash('sha256', $sourceFile->getEtag()), 0, 12);
		$tempName = '.recording-artifact-publication-' . $artifact->getId() . '-' . $sourceRevision . '-' . $claimToken . '.pdf';
		if ($draftFolder->nodeExists($tempName)) {
			$stagedFile = $draftFolder->get($tempName);
			if (!$stagedFile instanceof File) {
				throw new \RuntimeException('Artifact staging path is not a file');
			}
		} else {
			try {
				$pdf = $this->pdfConverter->convert($sourceFile, $artifact->getOwnerId());
			} catch (\Throwable $e) {
				throw new RecordingArtifactConversionException('Could not convert recording artifact to PDF', previous: $e);
			}
			$stagedFile = $draftFolder->newFile($tempName, $pdf);
		}
		try {
			$persisted = $this->mapper->updatePublication(
				(string)$artifact->getId(),
				$claimToken,
				$stagedFile->getId(),
				null,
				null,
				$this->timeFactory->getDateTime(),
			);
		} catch (\Throwable $e) {
			$this->cleanupUnpersistedStagedFile($artifact, $stagedFile);
			throw $e;
		}
		if (!$persisted) {
			$this->cleanupUnpersistedStagedFile($artifact, $stagedFile);
			throw new \RuntimeException('Could not persist artifact publication file');
		}
		$artifact = $this->mapper->findById((string)$artifact->getId());
		$publishedName = pathinfo($sourceFile->getName(), PATHINFO_FILENAME) . '.pdf';
		$result = $this->conversationFolderService->finalizeUploadedFile($targetFolder, $stagedFile, $publishedName);
		$publishedFile = $result['node'];
		if (!$publishedFile instanceof File) {
			throw new \RuntimeException('Published artifact is not a file');
		}
		$this->assignGeneratedByAiTag($publishedFile);
		$skipRoomShare = $isProjectFolder || $this->config->isConversationSubfoldersEnabled();
		$artifact = $this->ensurePublishedShare($room, $artifact, $publishedFile, $claimToken, $skipRoomShare);
		return [$artifact, $publishedFile, $this->messageParameters($artifact, $publishedFile)];
	}

	/** @return array{Folder, bool} */
	private function getPublicationTargetFolder(Room $room, RecordingArtifact $artifact, File $sourceFile): array {
		$projectFolder = $this->projectRecordingFolderProvider->getFolder($room->getToken(), $artifact->getOwnerId());
		if ($projectFolder !== null) {
			return [$projectFolder, true];
		}
		return [$this->getFallbackPublicationTargetFolder($room, $artifact, $sourceFile), false];
	}

	private function getFallbackPublicationTargetFolder(Room $room, RecordingArtifact $artifact, File $sourceFile): Folder {
		if ($this->config->isConversationSubfoldersEnabled()) {
			return $this->conversationFolderService->getOrCreateSubfolder($artifact->getOwnerId(), $room);
		}
		$targetFolder = $sourceFile->getParent();
		if (!$targetFolder instanceof Folder) {
			throw new \RuntimeException('Artifact source parent is not a folder');
		}
		return $targetFolder;
	}

	private function assignGeneratedByAiTag(File $file): void {
		try {
			$this->systemTagMapper->assignGeneratedByAITag((string)$file->getId(), 'files');
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to tag recording artifact as AI-generated', [
				'fileId' => $file->getId(),
				'exception' => $e,
			]);
		}
	}

	private function ensurePublishedShare(Room $room, RecordingArtifact $artifact, File $file, string $claimToken, bool $skipRoomShare): RecordingArtifact {
		if ($skipRoomShare || $artifact->getPublishedShareId() !== null) {
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
		$artifact = $this->resolveArtifact($room, $participant, $artifactId);
		$file = $this->resolveSourceFile($artifact);
		if (!$file instanceof File || $file->getParent()->getName() !== $room->getToken()) {
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

	private function resolveArtifact(Room $room, Participant $participant, string $artifactId): RecordingArtifact {
		try {
			$artifact = $this->mapper->findById($artifactId);
		} catch (DoesNotExistException) {
			throw new RecordingArtifactException(RecordingArtifactException::NOT_FOUND);
		}

		$ownerId = $participant->getAttendee()->getActorId();
		if ($artifact->getOwnerId() !== $ownerId || $artifact->getRoomToken() !== $room->getToken()) {
			throw new RecordingArtifactException(RecordingArtifactException::NOT_FOUND);
		}
		return $artifact;
	}

	private function resolveSourceFile(RecordingArtifact $artifact): ?File {
		$sourceFileId = $artifact->getSourceFileId();
		if ($sourceFileId === null) {
			return null;
		}
		try {
			$nodes = $this->rootFolder->getUserFolder($artifact->getOwnerId())->getById($sourceFileId);
			$file = array_shift($nodes);
			if (!$file instanceof File) {
				throw new NotFoundException();
			}
			return $file;
		} catch (\Throwable) {
			return null;
		}
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

	private function deletePublishedSource(RecordingArtifact $artifact, File $sourceFile): void {
		$sourceFileId = $sourceFile->getId();
		try {
			$sourceFile->delete();
		} catch (\Throwable $e) {
			$this->logger->warning('Could not delete published recording artifact source', ['exception' => $e]);
			return;
		}
		if (!$this->mapper->clearSourceFile((string)$artifact->getId(), $sourceFileId, $this->timeFactory->getDateTime())) {
			$this->logger->warning('Could not clear published recording artifact source reference', [
				'artifactId' => $artifact->getId(),
				'sourceFileId' => $sourceFileId,
			]);
		}
	}

	private function cleanupUnpersistedStagedFile(RecordingArtifact $artifact, File $stagedFile): void {
		try {
			$persistedFileId = $this->mapper->findById((string)$artifact->getId())->getPublishedFileId();
		} catch (\Throwable) {
			// Preserve the staging file when persistence is uncertain so recovery remains possible.
			return;
		}
		if ($persistedFileId !== null) {
			$artifact->setPublishedFileId($persistedFileId);
			if ($persistedFileId === $stagedFile->getId()) {
				return;
			}
		}
		try {
			$stagedFile->delete();
		} catch (\Throwable $e) {
			$this->logger->warning('Could not delete unpersisted recording artifact PDF', ['exception' => $e]);
		}
	}

	/** @return array{id: string, type: string, state: string, fileName: string, content: string, etag: string, publishedFileId: ?string, publishedMessageId: ?string} */
	private function formatAndCleanupPublished(RecordingArtifact $artifact): array {
		$sourceFile = $this->resolveSourceFile($artifact);
		$result = $this->formatPublished($artifact, $this->resolvePublishedFile($artifact), $sourceFile);
		if ($sourceFile !== null) {
			$this->deletePublishedSource($artifact, $sourceFile);
		}
		return $result;
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

	/** @return array{id: string, type: string, state: string, fileName: string, content: string, etag: string, publishedFileId: ?string, publishedMessageId: ?string} */
	private function formatPublished(RecordingArtifact $artifact, File $publishedFile, ?File $sourceFile): array {
		return [
			'id' => (string)$artifact->getId(),
			'type' => $artifact->getType(),
			'state' => $artifact->getState(),
			'fileName' => $publishedFile->getName(),
			'content' => $sourceFile?->getContent() ?? '',
			'etag' => $sourceFile?->getEtag() ?? $publishedFile->getEtag(),
			'publishedFileId' => $artifact->getPublishedFileId() === null ? null : (string)$artifact->getPublishedFileId(),
			'publishedMessageId' => $artifact->getPublishedMessageId() === null ? null : (string)$artifact->getPublishedMessageId(),
		];
	}
}
