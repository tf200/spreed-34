<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Recording;

use GuzzleHttp\Exception\ClientException;
use OCP\Files\File;
use OCP\Http\Client\IClientService;
use OCP\IConfig;

class GoogleCloudStorageClient {
	public function __construct(
		private readonly GoogleAiConfig $config,
		private readonly GoogleAuthTokenProvider $tokenProvider,
		private readonly IClientService $clientService,
		private readonly IConfig $serverConfig,
	) {
	}

	public function upload(File $file, int $operationId): string {
		$config = $this->config->getValidated();
		$instanceId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $this->serverConfig->getSystemValueString('instanceid', 'unknown'));
		$object = sprintf(
			'recordings/%s/%d/%d',
			$instanceId,
			$operationId,
			$file->getId(),
		);
		$stream = $file->fopen('r');
		if (!is_resource($stream)) {
			throw new GoogleApiException('Recording could not be opened for cloud upload');
		}

		$url = 'https://storage.googleapis.com/upload/storage/v1/b/'
			. rawurlencode($config['bucket']) . '/o?uploadType=media&name=' . rawurlencode($object);
		try {
			$response = $this->clientService->newClient()->post($url, [
				'headers' => [
					'Authorization' => 'Bearer ' . $this->tokenProvider->getAccessToken(),
					'Content-Type' => $file->getMimeType(),
					'Content-Length' => (string)$file->getSize(),
				],
				'body' => $stream,
				'timeout' => 3600,
			]);
			$payload = json_decode((string)$response->getBody(), true, 16, JSON_THROW_ON_ERROR);
		} catch (\Throwable) {
			throw new GoogleApiException('Cloud Storage upload failed');
		} finally {
			fclose($stream);
		}

		if (!is_array($payload) || ($payload['name'] ?? null) !== $object) {
			throw new GoogleApiException('Cloud Storage upload response was invalid');
		}
		return $object;
	}

	public function delete(string $object): void {
		$bucket = $this->config->getValidated()['bucket'];
		$url = 'https://storage.googleapis.com/storage/v1/b/'
			. rawurlencode($bucket) . '/o/' . rawurlencode($object);
		try {
			$this->clientService->newClient()->delete($url, [
				'headers' => ['Authorization' => 'Bearer ' . $this->tokenProvider->getAccessToken()],
				'timeout' => 30,
			]);
		} catch (ClientException $e) {
			if ($e->getResponse()->getStatusCode() !== 404) {
				throw new GoogleApiException('Cloud Storage deletion failed');
			}
		} catch (\Throwable) {
			throw new GoogleApiException('Cloud Storage deletion failed');
		}
	}
}
