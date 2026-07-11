<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\Recording;

use OCA\Talk\Recording\GoogleAiConfig;
use OCA\Talk\Recording\GoogleAuthTokenProvider;
use OCA\Talk\Recording\GoogleCloudStorageClient;
use OCP\Files\File;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use Test\TestCase;

class GoogleCloudStorageClientTest extends TestCase {
	public function testUploadsRecordingAsStream(): void {
		$config = $this->createMock(GoogleAiConfig::class);
		$config->method('getValidated')->willReturn([
			'project' => 'valid-project', 'location' => 'eu', 'bucket' => 'valid-bucket',
			'speechModel' => 'chirp_3', 'geminiModel' => 'gemini-2.5-flash', 'serviceAccount' => [],
		]);
		$tokenProvider = $this->createMock(GoogleAuthTokenProvider::class);
		$tokenProvider->method('getAccessToken')->willReturn('token');
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getMimeType')->willReturn('audio/ogg');
		$file->method('getSize')->willReturn(5);
		$file->method('fopen')->with('r')->willReturnCallback(function () {
			$stream = fopen('php://temp', 'r+');
			fwrite($stream, 'audio');
			rewind($stream);
			return $stream;
		});
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"name":"recordings/instance/7/42"}');
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('post')
			->with($this->stringContains('name=recordings%2Finstance%2F7%2F42'), $this->callback(fn (array $options): bool => is_resource($options['body'])))
			->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$serverConfig = $this->createMock(IConfig::class);
		$serverConfig->method('getSystemValueString')->willReturn('instance');

		$storage = new GoogleCloudStorageClient($config, $tokenProvider, $clientService, $serverConfig);
		$this->assertSame('recordings/instance/7/42', $storage->upload($file, 7));
	}
}
