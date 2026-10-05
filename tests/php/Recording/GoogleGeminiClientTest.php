<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\Recording;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\Talk\Recording\GoogleAiConfig;
use OCA\Talk\Recording\GoogleApiException;
use OCA\Talk\Recording\GoogleAuthTokenProvider;
use OCA\Talk\Recording\GoogleGeminiClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Test\TestCase;

class GoogleGeminiClientTest extends TestCase {
	public function testUsesSummaryModelWithBoundedOutput(): void {
		$config = $this->createMock(GoogleAiConfig::class);
		$config->method('getValidated')->willReturn([
			'project' => 'valid-project', 'location' => 'eu', 'bucket' => 'valid-bucket', 'language' => 'en-US',
			'speechModel' => 'chirp_3', 'geminiLocation' => 'global', 'geminiModel' => 'gemini-2.5-flash-lite', 'serviceAccount' => [],
		]);
		$config->method('getSummaryModel')->willReturn('gemini-3.5-flash-lite');
		$token = $this->createMock(GoogleAuthTokenProvider::class);
		$token->method('getAccessToken')->willReturn('token');
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"candidates":[{"content":{"parts":[{"text":"## Overview\\nShort summary"}]}}]}');
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('post')->with(
			'https://aiplatform.googleapis.com/v1/projects/valid-project/locations/global/publishers/google/models/gemini-3.5-flash-lite:generateContent',
			$this->callback(fn (array $options): bool => $options['json']['generationConfig']['maxOutputTokens'] === 8192
				&& str_contains($options['json']['systemInstruction']['parts'][0]['text'], 'untrusted data')
				&& str_contains($options['json']['contents'][0]['parts'][0]['text'], "SUMMARY TEMPLATE:\nFocus on decisions")
				&& str_contains($options['json']['contents'][0]['parts'][0]['text'], "<meeting>\nDate: 2026-10-05 (Monday)\n</meeting>")
				&& str_contains($options['json']['contents'][0]['parts'][0]['text'], "<transcript>\nTranscript\n</transcript>")),
		)->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$this->assertSame("## Overview\nShort summary", (new GoogleGeminiClient($config, $token, $clientService))->summarize('Transcript', 'Focus on decisions', 'Date: 2026-10-05 (Monday)'));
	}

	public function testRejectsSummaryThatWasCutOff(): void {
		$config = $this->createMock(GoogleAiConfig::class);
		$config->method('getValidated')->willReturn([
			'project' => 'valid-project', 'location' => 'eu', 'bucket' => 'valid-bucket', 'language' => 'en-US',
			'speechModel' => 'chirp_3', 'geminiLocation' => 'global', 'geminiModel' => 'gemini-2.5-flash-lite', 'serviceAccount' => [],
		]);
		$token = $this->createMock(GoogleAuthTokenProvider::class);
		$token->method('getAccessToken')->willReturn('token');
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"candidates":[{"finishReason":"MAX_TOKENS","content":{"parts":[{"text":"## Overview\\nShort"}]}}]}');
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$this->expectException(GoogleApiException::class);
		$this->expectExceptionMessage('Gemini summary output was cut off');
		(new GoogleGeminiClient($config, $token, $clientService))->summarize('Transcript', 'Focus on decisions');
	}

	public function testStandardizesTranscriptWithStrictInstructionsAndPreservedFormat(): void {
		$config = $this->createMock(GoogleAiConfig::class);
		$config->method('getValidated')->willReturn([
			'project' => 'valid-project', 'location' => 'eu', 'bucket' => 'valid-bucket', 'language' => 'en-US',
			'speechModel' => 'chirp_3', 'geminiLocation' => 'global', 'geminiModel' => 'gemini-2.5-flash-lite', 'serviceAccount' => [],
		]);
		$token = $this->createMock(GoogleAuthTokenProvider::class);
		$token->method('getAccessToken')->willReturn('token');
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"candidates":[{"content":{"parts":[{"text":"**admin** · 00:55\\nPossessed of a spirit that was steady."}]}}]}');
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('post')->with(
			$this->anything(),
			$this->callback(function (array $options): bool {
				$instructions = $options['json']['systemInstruction']['parts'][0]['text'] ?? '';
				return $options['json']['generationConfig'] === ['temperature' => 0.0, 'maxOutputTokens' => 65535]
					&& str_contains($instructions, 'Do not summarize')
					&& str_contains($instructions, 'Use only speaker labels and timestamps that already occur in the input');
			}),
		)->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$input = "**admin** · 00:55\nPossessed of\n\n**Speaker 1** · 00:58\na\n\n**admin** · 00:59\nspirit that was steady.";

		$this->assertSame(
			"**admin** · 00:55\nPossessed of a spirit that was steady.",
			(new GoogleGeminiClient($config, $token, $clientService))->standardizeTranscript($input),
		);
	}

	public function testIncludesGoogleResponseInRequestFailure(): void {
		$config = $this->createMock(GoogleAiConfig::class);
		$config->method('getValidated')->willReturn([
			'project' => 'valid-project', 'location' => 'eu', 'bucket' => 'valid-bucket', 'language' => 'en-US',
			'speechModel' => 'chirp_3', 'geminiLocation' => 'global', 'geminiModel' => 'gemini-2.5-flash-lite', 'serviceAccount' => [],
		]);
		$token = $this->createMock(GoogleAuthTokenProvider::class);
		$token->method('getAccessToken')->willReturn('token');
		$client = $this->createMock(IClient::class);
		$client->method('post')->willThrowException(new ClientException(
			'Bad request',
			new Request('POST', 'https://aiplatform.googleapis.com'),
			new Response(429, body: '{"error":{"message":"Quota exceeded for generate requests"}}'),
		));
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$this->expectException(GoogleApiException::class);
		$this->expectExceptionMessage('Gemini summary request failed (HTTP 429): Quota exceeded for generate requests');

		(new GoogleGeminiClient($config, $token, $clientService))->summarize('Transcript', 'Summarize briefly');
	}
}
