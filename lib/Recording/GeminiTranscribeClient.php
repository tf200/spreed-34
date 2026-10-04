<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Recording;

use GuzzleHttp\Exception\RequestException;
use OCP\Http\Client\IClientService;

/**
 * Transcribes the audio of a single speaker with Gemini Transcribe on Vertex AI.
 *
 * Diarization is not requested, as every chunk contains a single participant.
 * Word timestamps are requested, which limits the audio to 15 minutes per
 * request (the chunks are shorter).
 */
class GeminiTranscribeClient implements TrackTranscriptionProvider {
	private const TIMEOUT = 240;

	public function __construct(
		private readonly GoogleAiConfig $config,
		private readonly GoogleAuthTokenProvider $tokenProvider,
		private readonly IClientService $clientService,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'gemini_transcribe';
	}

	#[\Override]
	public function getModel(): string {
		return $this->config->getTranscriptionConfig()['model'];
	}

	#[\Override]
	public function transcribe(string $audio, string $mimeType): array {
		$config = $this->config->getTranscriptionConfig();
		$transcriptionConfig = ['wordTimestamp' => true, 'mode' => 'VERBATIM'];
		if ($config['language'] !== 'auto') {
			$transcriptionConfig['languageCodes'] = [$config['language']];
		}
		$json = [
			'contents' => [['role' => 'user', 'parts' => [['inlineData' => ['mimeType' => $mimeType, 'data' => base64_encode($audio)]]]]],
			'generationConfig' => ['audioTranscriptionConfig' => $transcriptionConfig],
		];

		try {
			$response = $this->clientService->newClient()->post($this->getUrl($config), [
				'headers' => ['Authorization' => 'Bearer ' . $this->tokenProvider->getAccessToken()],
				'json' => $json,
				'timeout' => self::TIMEOUT,
			]);
			$payload = json_decode((string)$response->getBody(), true, 64, JSON_THROW_ON_ERROR);
		} catch (RequestException $e) {
			throw new GoogleApiException($this->getRequestError($e));
		} catch (GoogleApiException $e) {
			throw $e;
		} catch (\Throwable) {
			throw new GoogleApiException('Gemini transcription request failed');
		}

		if (!is_array($payload) || !is_array($payload['candidates'][0] ?? null)) {
			throw new GoogleApiException('Gemini transcription response was invalid');
		}
		$finishReason = $payload['candidates'][0]['finishReason'] ?? 'STOP';
		if ($finishReason !== 'STOP') {
			throw new GoogleApiException('Gemini transcription did not complete: ' . (is_string($finishReason) ? substr($finishReason, 0, 64) : 'unknown'));
		}

		return $this->getWords($payload);
	}

	/**
	 * @param array{project: string, location: string, model: string} $config
	 */
	private function getUrl(array $config): string {
		$host = match (true) {
			$config['location'] === 'global' => 'aiplatform.googleapis.com',
			// Multi-region endpoints.
			in_array($config['location'], ['us', 'eu'], true) => 'aiplatform.' . $config['location'] . '.rep.googleapis.com',
			default => $config['location'] . '-aiplatform.googleapis.com',
		};
		return 'https://' . $host . '/v1/projects/' . rawurlencode($config['project'])
			. '/locations/' . rawurlencode($config['location'])
			. '/publishers/google/models/' . rawurlencode($config['model']) . ':generateContent';
	}

	/**
	 * @return list<array{text: string, start: float, end: float}>
	 */
	public function getWords(array $payload): array {
		$words = [];
		$hasText = false;
		foreach (($payload['candidates'][0]['content']['parts'] ?? []) as $part) {
			if (!is_array($part)) {
				continue;
			}
			$transcription = is_array($part['audioTranscription'] ?? null) ? $part['audioTranscription'] : [];
			foreach ([$part['text'] ?? null, $transcription['text'] ?? null] as $text) {
				if (is_string($text) && trim($text) !== '') {
					$hasText = true;
				}
			}
			foreach (($transcription['words'] ?? []) as $word) {
				if (!is_array($word)) {
					continue;
				}
				$text = trim((string)($word['word'] ?? ''));
				$start = $this->seconds($word['startOffset'] ?? null);
				$end = $this->seconds($word['endOffset'] ?? null);
				if ($text === '' || $start === null) {
					continue;
				}
				$words[] = ['text' => $text, 'start' => $start, 'end' => max($start, $end ?? $start)];
			}
		}

		if ($words === [] && $hasText) {
			// Without timestamps the words could not be placed in the call.
			throw new GoogleApiException('Gemini transcription response has no word timestamps');
		}

		usort($words, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
		return $words;
	}

	private function seconds(mixed $offset): ?float {
		if (is_int($offset) || is_float($offset)) {
			return (float)$offset;
		}
		return is_string($offset) && preg_match('/^([0-9]+(?:\.[0-9]+)?)s$/', $offset, $matches)
			? (float)$matches[1]
			: null;
	}

	private function getRequestError(RequestException $exception): string {
		$prefix = 'Gemini transcription request failed';
		$response = $exception->getResponse();
		if ($response === null) {
			return $prefix;
		}
		$message = '';
		try {
			$payload = json_decode((string)$response->getBody(), true, 8, JSON_THROW_ON_ERROR);
			// The error is either an object or wrapped in a list.
			$error = $payload['error'] ?? $payload[0]['error'] ?? null;
			$message = is_string($error['message'] ?? null) ? $error['message'] : '';
		} catch (\Throwable) {
			// The HTTP status still provides a safe diagnostic.
		}
		$message = preg_replace('/\s+/', ' ', $message) ?? '';
		return substr($prefix . ' (HTTP ' . $response->getStatusCode() . ')' . ($message !== '' ? ': ' . $message : ''), 0, 500);
	}
}
