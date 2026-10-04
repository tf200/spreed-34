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
use OCA\Talk\Recording\GeminiTranscribeClient;
use OCA\Talk\Recording\GoogleAiConfig;
use OCA\Talk\Recording\GoogleApiException;
use OCA\Talk\Recording\GoogleAuthTokenProvider;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class GeminiTranscribeClientTest extends TestCase {
	private GoogleAiConfig&MockObject $config;
	private IClient&MockObject $client;
	private GeminiTranscribeClient $transcriber;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(GoogleAiConfig::class);
		$this->client = $this->createMock(IClient::class);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);
		$tokenProvider = $this->createMock(GoogleAuthTokenProvider::class);
		$tokenProvider->method('getAccessToken')->willReturn('token');
		$this->transcriber = new GeminiTranscribeClient($this->config, $tokenProvider, $clientService);
	}

	private function configure(string $language, string $location = 'global'): void {
		$this->config->method('getTranscriptionConfig')->willReturn([
			'project' => 'talk-project',
			'location' => $location,
			'model' => 'gemini-3.5-transcribe-preview',
			'language' => $language,
		]);
	}

	public function testRequestsVerbatimWordTimestampsWithoutDiarization(): void {
		$this->configure('auto');
		$response = $this->createMock(IResponse::class);
		// Captured from Vertex AI.
		$response->method('getBody')->willReturn(json_encode([
			'candidates' => [[
				'content' => [
					'role' => 'model',
					'parts' => [[
						'text' => 'Good morning.',
						'audioTranscription' => [
							'text' => 'Good morning.',
							'words' => [
								['word' => 'morning.', 'startOffset' => '0.500s', 'endOffset' => '1s'],
								['word' => 'Good', 'startOffset' => '0.400s', 'endOffset' => '0.500s'],
								['word' => '', 'startOffset' => '1s', 'endOffset' => '1s'],
							],
						],
					]],
				],
				'finishReason' => 'STOP',
			]],
			'modelVersion' => 'gemini-3.5-transcribe-preview',
		]));
		$this->client->expects($this->once())->method('post')->with(
			'https://aiplatform.googleapis.com/v1/projects/talk-project/locations/global/publishers/google/models/gemini-3.5-transcribe-preview:generateContent',
			$this->callback(function (array $options): bool {
				$this->assertSame('Bearer token', $options['headers']['Authorization']);
				$this->assertSame([['role' => 'user', 'parts' => [['inlineData' => ['mimeType' => 'audio/ogg', 'data' => base64_encode('OggS')]]]]], $options['json']['contents']);
				// No language codes enable the language detection.
				$this->assertSame(['audioTranscriptionConfig' => ['wordTimestamp' => true, 'mode' => 'VERBATIM']], $options['json']['generationConfig']);
				return true;
			}),
		)->willReturn($response);

		$this->assertSame([
			['text' => 'Good', 'start' => 0.4, 'end' => 0.5],
			['text' => 'morning.', 'start' => 0.5, 'end' => 1.0],
		], $this->transcriber->transcribe('OggS', 'audio/ogg'));
	}

	public static function dataLocation(): array {
		return [
			['eu', 'https://aiplatform.eu.rep.googleapis.com/v1/projects/talk-project/locations/eu/'],
			['europe-west4', 'https://europe-west4-aiplatform.googleapis.com/v1/projects/talk-project/locations/europe-west4/'],
		];
	}

	#[DataProvider('dataLocation')]
	public function testUsesConfiguredLanguageAndLocation(string $location, string $urlPrefix): void {
		$this->configure('nl-NL', $location);
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"candidates":[{"content":{"parts":[]},"finishReason":"STOP"}]}');
		$this->client->expects($this->once())->method('post')->with(
			$this->stringStartsWith($urlPrefix),
			$this->callback(fn (array $options): bool => $options['json']['generationConfig']['audioTranscriptionConfig']['languageCodes'] === ['nl-NL']),
		)->willReturn($response);

		$this->assertSame([], $this->transcriber->transcribe('OggS', 'audio/ogg'));
	}

	public function testIncompleteResponseIsAnError(): void {
		$this->configure('auto');
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"candidates":[{"content":{"parts":[{"text":"Good"}]},"finishReason":"MAX_TOKENS"}]}');
		$this->client->method('post')->willReturn($response);

		$this->expectException(GoogleApiException::class);
		$this->expectExceptionMessage('did not complete: MAX_TOKENS');
		$this->transcriber->transcribe('OggS', 'audio/ogg');
	}

	public function testTextWithoutTimestampsIsAnError(): void {
		$this->expectException(GoogleApiException::class);
		$this->expectExceptionMessage('no word timestamps');
		$this->transcriber->getWords(['candidates' => [['content' => ['parts' => [['text' => 'Hello']]]]]]);
	}

	public function testReportsApiErrorMessage(): void {
		$this->configure('auto');
		$this->client->method('post')->willThrowException(new ClientException(
			'Not found',
			new Request('POST', 'https://aiplatform.googleapis.com/v1/projects/talk-project/locations/global/publishers/google/models/gemini-3.5-transcribe-preview:generateContent'),
			new Response(404, [], '{"error":{"code":404,"message":"Publisher model was not found.","status":"NOT_FOUND"}}'),
		));

		$this->expectException(GoogleApiException::class);
		$this->expectExceptionMessage('Gemini transcription request failed (HTTP 404): Publisher model was not found.');
		$this->transcriber->transcribe('OggS', 'audio/ogg');
	}
}
