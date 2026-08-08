<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\Recording;

use OCA\Talk\Recording\EuroOfficePdfConverter;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use Test\TestCase;

class EuroOfficePdfConverterTest extends TestCase {
	public function testFailsCleanlyWhenEuroOfficeIsNotAvailableForOwner(): void {
		$appManager = $this->createMock(IAppManager::class);
		$userManager = $this->createMock(IUserManager::class);
		$owner = $this->createMock(IUser::class);
		$userManager->method('get')->with('owner')->willReturn($owner);
		$appManager->method('isEnabledForUser')->with('eurooffice', $owner)->willReturn(false);
		$appManager->expects($this->never())->method('loadApp');

		$this->expectExceptionMessage('Euro Office is not enabled');
		(new EuroOfficePdfConverter(
			$appManager,
			$this->createMock(IURLGenerator::class),
			$userManager,
		))->convert($this->createMock(File::class), 'owner');
	}

	public function testConvertsMarkdownWithExpiringSignedCallback(): void {
		$appManager = $this->createMock(IAppManager::class);
		$userManager = $this->createMock(IUserManager::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$owner = $this->createMock(IUser::class);
		$userManager->method('get')->with('owner')->willReturn($owner);
		$appManager->method('isEnabledForUser')->with('eurooffice', $owner)->willReturn(true);
		$appManager->expects($this->once())->method('loadApp')->with('eurooffice');
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/eurooffice/download?doc=signed');
		$urlGenerator->method('getAbsoluteURL')->with('/')->willReturn('https://cloud.example/');
		$source = $this->createMock(File::class);
		$source->method('getId')->willReturn(42);
		$source->method('getEtag')->willReturn('revision');
		$appConfig = new FakeEuroOfficeAppConfig();
		$crypt = new FakeEuroOfficeCrypt();
		$documentService = new FakeEuroOfficeDocumentService();
		$converter = new TestEuroOfficePdfConverter($appManager, $urlGenerator, $userManager, [
			'OCA\\Eurooffice\\AppConfig' => $appConfig,
			'OCA\\Eurooffice\\Crypt' => $crypt,
			'OCA\\Eurooffice\\DocumentService' => $documentService,
		]);

		$this->assertSame('%PDF-1.7 generated', $converter->convert($source, 'owner'));
		$this->assertSame('download', $crypt->payload['action']);
		$this->assertSame(42, $crypt->payload['fileId']);
		$this->assertSame('owner', $crypt->payload['userId']);
		$this->assertSame(300, $crypt->payload['exp'] - $crypt->payload['iat']);
		$this->assertSame('http://nextcloud/apps/eurooffice/download?doc=signed', $documentService->sourceUrl);
		$this->assertSame('md', $documentService->sourceExtension);
		$this->assertSame('pdf', $documentService->targetExtension);
		$this->assertSame(20, strlen($documentService->key));
		$this->assertSame('http://office/result.pdf', $documentService->requestedUrl);
	}
}

class TestEuroOfficePdfConverter extends EuroOfficePdfConverter {
	/** @param array<string, object> $services */
	public function __construct(
		IAppManager $appManager,
		IURLGenerator $urlGenerator,
		IUserManager $userManager,
		private array $services,
	) {
		parent::__construct($appManager, $urlGenerator, $userManager);
	}

	#[\Override]
	protected function getService(string $class): object {
		return $this->services[$class];
	}
}

class FakeEuroOfficeAppConfig {
	public function isUserAllowedToUse(string $ownerId): bool {
		return $ownerId === 'owner';
	}

	public function getJwtExpiration(): int {
		return 5;
	}

	public function useDemo(): bool {
		return false;
	}

	public function getStorageUrl(): string {
		return 'http://nextcloud/';
	}

	public function replaceDocumentServerUrlToInternal(string $url): string {
		return str_replace('https://office/', 'http://office/', $url);
	}
}

class FakeEuroOfficeCrypt {
	/** @var array<string, mixed> */
	public array $payload = [];

	public function getHash(array $payload): string {
		$this->payload = $payload;
		return 'signed';
	}
}

class FakeEuroOfficeDocumentService {
	public string $sourceUrl = '';
	public string $sourceExtension = '';
	public string $targetExtension = '';
	public string $key = '';
	public string $requestedUrl = '';

	public function getConvertedUri(string $sourceUrl, string $sourceExtension, string $targetExtension, string $key): string {
		$this->sourceUrl = $sourceUrl;
		$this->sourceExtension = $sourceExtension;
		$this->targetExtension = $targetExtension;
		$this->key = $key;
		return 'https://office/result.pdf';
	}

	public function request(string $url): string {
		$this->requestedUrl = $url;
		return '%PDF-1.7 generated';
	}
}
