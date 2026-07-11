<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\Recording;

use OCA\Talk\Recording\GoogleAiConfig;
use OCA\Talk\Recording\GoogleAuthTokenProvider;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Test\TestCase;

class GoogleAuthTokenProviderTest extends TestCase {
	public function testGetsAndCachesAccessToken(): void {
		$key = openssl_pkey_new(['private_key_bits' => 2048]);
		$this->assertNotFalse($key);
		openssl_pkey_export($key, $privateKey);

		$config = $this->createMock(GoogleAiConfig::class);
		$config->expects($this->once())->method('getValidated')->willReturn([
			'project' => 'valid-project',
			'location' => 'eu',
			'bucket' => 'valid-bucket',
			'speechModel' => 'chirp_3',
			'geminiModel' => 'gemini-2.5-flash',
			'serviceAccount' => [
				'client_email' => 'service@example.test',
				'private_key' => $privateKey,
			],
		]);
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"access_token":"token","expires_in":3600}');
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('post')->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1000);
		$provider = new GoogleAuthTokenProvider($config, $clientService, $timeFactory);

		$this->assertSame('token', $provider->getAccessToken());
		$this->assertSame('token', $provider->getAccessToken());
	}
}
