<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Recording;

use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Server;

class EuroOfficePdfConverter {
	private const APP_ID = 'eurooffice';
	private const APP_CONFIG = 'OCA\\Eurooffice\\AppConfig';
	private const CRYPT = 'OCA\\Eurooffice\\Crypt';
	private const DOCUMENT_SERVICE = 'OCA\\Eurooffice\\DocumentService';

	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IURLGenerator $urlGenerator,
		private readonly IUserManager $userManager,
	) {
	}

	public function convert(File $sourceFile, string $ownerId): string {
		$owner = $this->userManager->get($ownerId);
		if ($owner === null || !$this->appManager->isEnabledForUser(self::APP_ID, $owner)) {
			throw new \RuntimeException('Euro Office is not enabled');
		}

		$this->appManager->loadApp(self::APP_ID);
		$appConfig = $this->getService(self::APP_CONFIG);
		$crypt = $this->getService(self::CRYPT);
		$documentService = $this->getService(self::DOCUMENT_SERVICE);
		if (!$this->call($appConfig, 'isUserAllowedToUse', [$ownerId])) {
			throw new \RuntimeException('The artifact owner is not allowed to use Euro Office');
		}

		$now = time();
		$jwtLifetime = (int)$this->call($appConfig, 'getJwtExpiration');
		$token = $this->call($crypt, 'getHash', [[
			'action' => 'download',
			'fileId' => $sourceFile->getId(),
			'userId' => $ownerId,
			'iat' => $now,
			'exp' => $now + max(1, $jwtLifetime) * 60,
		]]);
		if (!is_string($token) || $token === '') {
			throw new \RuntimeException('Euro Office did not create a download token');
		}

		$fileUrl = $this->urlGenerator->linkToRouteAbsolute('eurooffice.callback.download', ['doc' => $token]);
		if (!$this->call($appConfig, 'useDemo')) {
			$storageUrl = $this->call($appConfig, 'getStorageUrl');
			if (is_string($storageUrl) && $storageUrl !== '') {
				$fileUrl = str_replace($this->urlGenerator->getAbsoluteURL('/'), $storageUrl, $fileUrl);
			}
		}

		$key = substr(rtrim(strtr(base64_encode(hash('sha256', $ownerId . ':' . $sourceFile->getId() . ':' . $sourceFile->getEtag(), true)), '+/', '-_'), '='), 0, 20);
		$convertedUrl = $this->call($documentService, 'getConvertedUri', [$fileUrl, 'md', 'pdf', $key]);
		if (!is_string($convertedUrl) || $convertedUrl === '') {
			throw new \RuntimeException('Euro Office did not return a converted file URL');
		}
		$convertedUrl = $this->call($appConfig, 'replaceDocumentServerUrlToInternal', [$convertedUrl]);
		if (!is_string($convertedUrl) || $convertedUrl === '') {
			throw new \RuntimeException('Euro Office returned an invalid converted file URL');
		}
		$pdf = (string)$this->call($documentService, 'request', [$convertedUrl]);
		if (!str_starts_with($pdf, '%PDF-')) {
			throw new \RuntimeException('Euro Office returned an invalid PDF');
		}

		return $pdf;
	}

	protected function getService(string $class): object {
		if (!class_exists($class)) {
			throw new \RuntimeException('The installed Euro Office connector is incompatible');
		}
		return Server::get($class);
	}

	private function call(object $service, string $method, array $arguments = []): mixed {
		if (!is_callable([$service, $method])) {
			throw new \RuntimeException('The installed Euro Office connector is incompatible');
		}
		return $service->{$method}(...$arguments);
	}
}
