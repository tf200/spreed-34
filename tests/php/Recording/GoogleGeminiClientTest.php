<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\Recording;

use OCA\Talk\Recording\GoogleAiConfig;
use OCA\Talk\Recording\GoogleAuthTokenProvider;
use OCA\Talk\Recording\GoogleGeminiClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Test\TestCase;

class GoogleGeminiClientTest extends TestCase {
	public function testUsesFlashLiteWithBoundedOutput(): void {
		$config = $this->createMock(GoogleAiConfig::class);
		$config->method('getValidated')->willReturn([
			'project' => 'valid-project', 'location' => 'eu', 'bucket' => 'valid-bucket', 'language' => 'en-US',
			'speechModel' => 'chirp_3', 'geminiLocation' => 'global', 'geminiModel' => 'gemini-2.5-flash-lite', 'serviceAccount' => [],
		]);
		$token = $this->createMock(GoogleAuthTokenProvider::class);
		$token->method('getAccessToken')->willReturn('token');
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"candidates":[{"content":{"parts":[{"text":"## Overview\\nShort summary"}]}}]}');
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('post')->with(
			'https://aiplatform.googleapis.com/v1/projects/valid-project/locations/global/publishers/google/models/gemini-2.5-flash-lite:generateContent',
			$this->callback(fn (array $options): bool => $options['json']['generationConfig']['maxOutputTokens'] === 1024),
		)->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$this->assertSame("## Overview\nShort summary", (new GoogleGeminiClient($config, $token, $clientService))->summarize('Transcript'));
	}
}
