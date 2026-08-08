<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\Recording;

use OCA\Talk\Recording\ProjectRecordingFolderProvider;
use OCP\App\IAppManager;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ProjectRecordingFolderProviderTest extends TestCase {
	public function testReturnsNullWhenProjectCreatorIsUnavailable(): void {
		$appManager = $this->createMock(IAppManager::class);
		$userManager = $this->createMock(IUserManager::class);
		$owner = $this->createMock(IUser::class);
		$userManager->method('get')->with('owner')->willReturn($owner);
		$appManager->method('isEnabledForUser')->with('projectcreatoraio', $owner)->willReturn(false);
		$appManager->expects($this->never())->method('loadApp');

		$provider = new ProjectRecordingFolderProvider(
			$appManager,
			$userManager,
			$this->createMock(LoggerInterface::class),
		);

		$this->assertNull($provider->getFolder('room', 'owner'));
	}

	public function testReturnsProjectRecordingsFolder(): void {
		$appManager = $this->createMock(IAppManager::class);
		$userManager = $this->createMock(IUserManager::class);
		$owner = $this->createMock(IUser::class);
		$folder = $this->createMock(Folder::class);
		$userManager->method('get')->with('owner')->willReturn($owner);
		$appManager->method('isEnabledForUser')->with('projectcreatoraio', $owner)->willReturn(true);
		$appManager->expects($this->once())->method('loadApp')->with('projectcreatoraio');
		$service = new class($folder) {
			public function __construct(
				private readonly Folder $folder,
			) {
			}

			public function getFolder(string $conversationToken, string $ownerId): Folder {
				return $this->folder;
			}
		};
		$provider = new TestProjectRecordingFolderProvider(
			$appManager,
			$userManager,
			$this->createMock(LoggerInterface::class),
			$service,
		);

		$this->assertSame($folder, $provider->getFolder('room', 'owner'));
	}
}

class TestProjectRecordingFolderProvider extends ProjectRecordingFolderProvider {
	public function __construct(
		IAppManager $appManager,
		IUserManager $userManager,
		LoggerInterface $logger,
		private readonly object $service,
	) {
		parent::__construct($appManager, $userManager, $logger);
	}

	#[\Override]
	protected function getService(string $class): object {
		return $this->service;
	}
}
