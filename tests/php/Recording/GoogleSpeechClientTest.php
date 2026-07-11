<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\Recording;

use OCA\Talk\Recording\GoogleAiConfig;
use OCA\Talk\Recording\GoogleAuthTokenProvider;
use OCA\Talk\Recording\GoogleSpeechClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Test\TestCase;

class GoogleSpeechClientTest extends TestCase {
	private const OPERATION = 'projects/valid-project/locations/eu/operations/operation-1';

	public function testSubmitsChirpBatchRecognition(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"name":"' . self::OPERATION . '"}');
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('post')
			->with(
				'https://eu-speech.googleapis.com/v2/projects/valid-project/locations/eu/recognizers/_:batchRecognize',
				$this->callback(function (array $options): bool {
					return $options['json']['config']['model'] === 'chirp_3'
						&& $options['json']['config']['languageCodes'] === ['en-US']
						&& $options['json']['files'][0]['uri'] === 'gs://valid-bucket/recordings/instance/7/42'
						&& $options['json']['processingStrategy'] === 'DYNAMIC_BATCHING';
				}),
			)->willReturn($response);

		$this->assertSame(self::OPERATION, $this->createClient($client)->submit('recordings/instance/7/42'));
	}

	public function testPollsPendingOperation(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{}');
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('get')->willReturn($response);

		$this->assertSame(['done' => false], $this->createClient($client)->poll(self::OPERATION));
	}

	private function createClient(IClient $client): GoogleSpeechClient {
		$config = $this->createMock(GoogleAiConfig::class);
		$config->method('getValidated')->willReturn([
			'project' => 'valid-project', 'location' => 'eu', 'bucket' => 'valid-bucket', 'language' => 'en-US',
			'speechModel' => 'chirp_3', 'geminiModel' => 'gemini-2.5-flash', 'serviceAccount' => [],
		]);
		$tokenProvider = $this->createMock(GoogleAuthTokenProvider::class);
		$tokenProvider->method('getAccessToken')->willReturn('token');
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		return new GoogleSpeechClient($config, $tokenProvider, $clientService);
	}
}
