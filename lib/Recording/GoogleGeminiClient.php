<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Recording;

use OCP\Http\Client\IClientService;

class GoogleGeminiClient {
	public function __construct(
		private readonly GoogleAiConfig $config,
		private readonly GoogleAuthTokenProvider $tokenProvider,
		private readonly IClientService $clientService,
	) {
	}

	public function summarize(string $transcript): string {
		$config = $this->config->getValidated();
		$host = $config['geminiLocation'] === 'global'
			? 'https://aiplatform.googleapis.com'
			: 'https://' . $config['geminiLocation'] . '-aiplatform.googleapis.com';
		$url = $host . '/v1/projects/' . rawurlencode($config['project'])
			. '/locations/' . rawurlencode($config['geminiLocation'])
			. '/publishers/google/models/' . rawurlencode($config['geminiModel']) . ':generateContent';
		$prompt = "Summarize this meeting transcript in concise Markdown. Include Overview, Decisions, Action items, and Open questions. Only name owners or deadlines explicitly stated in the transcript. Do not invent facts.\n\n" . $transcript;

		try {
			$response = $this->clientService->newClient()->post($url, [
				'headers' => ['Authorization' => 'Bearer ' . $this->tokenProvider->getAccessToken()],
				'json' => [
					'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
					'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 1024],
				],
				'timeout' => 60,
			]);
			$payload = json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
		} catch (\Throwable) {
			throw new GoogleApiException('Gemini summary request failed');
		}

		$parts = $payload['candidates'][0]['content']['parts'] ?? null;
		if (!is_array($parts)) {
			throw new GoogleApiException('Gemini summary response was invalid');
		}
		$summary = trim(implode("\n", array_map(
			fn (array $part): string => is_string($part['text'] ?? null) ? $part['text'] : '',
			$parts,
		)));
		if ($summary === '') {
			throw new GoogleApiException('Gemini returned an empty summary');
		}
		return $summary;
	}
}
