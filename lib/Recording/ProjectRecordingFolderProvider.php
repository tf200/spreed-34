<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Recording;

use OCP\App\IAppManager;
use OCP\Files\Folder;
use OCP\IUserManager;
use OCP\Server;
use Psr\Log\LoggerInterface;

class ProjectRecordingFolderProvider {
	private const APP_ID = 'projectcreatoraio';
	private const FOLDER_PROVIDER = 'OCA\\ProjectCreatorAIO\\Service\\ProjectRecordingFolderProvider';

	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function getFolder(string $conversationToken, string $ownerId): ?Folder {
		$owner = $this->userManager->get($ownerId);
		if ($owner === null || !$this->appManager->isEnabledForUser(self::APP_ID, $owner)) {
			return null;
		}

		try {
			$this->appManager->loadApp(self::APP_ID);
			$provider = $this->getService(self::FOLDER_PROVIDER);
			if (!is_callable([$provider, 'getFolder'])) {
				return null;
			}
			$folder = $provider->getFolder($conversationToken, $ownerId);
			return $folder instanceof Folder ? $folder : null;
		} catch (\Throwable $e) {
			$this->logger->warning('Could not resolve project folder for recording artifact', [
				'conversationToken' => $conversationToken,
				'ownerId' => $ownerId,
				'exception' => $e,
			]);
			return null;
		}
	}

	protected function getService(string $class): object {
		if (!class_exists($class)) {
			throw new \RuntimeException('The installed Project Creator app is incompatible');
		}
		return Server::get($class);
	}
}
