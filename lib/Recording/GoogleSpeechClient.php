<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Recording;

use OCP\Http\Client\IClientService;

class GoogleSpeechClient {
	public function __construct(
		private readonly GoogleAiConfig $config,
		private readonly GoogleAuthTokenProvider $tokenProvider,
		private readonly IClientService $clientService,
	) {
	}

	public function submit(string $gcsObject): string {
		$config = $this->config->getValidated();
		$url = $this->getEndpoint($config['location']) . '/v2/projects/' . rawurlencode($config['project'])
			. '/locations/' . rawurlencode($config['location']) . '/recognizers/_:batchRecognize';
		$body = [
			'config' => [
				'autoDecodingConfig' => new \stdClass(),
				'languageCodes' => [$config['language']],
				'model' => $config['speechModel'],
				'features' => [
					'enableAutomaticPunctuation' => true,
					'enableWordTimeOffsets' => true,
					'diarizationConfig' => new \stdClass(),
				],
			],
			'files' => [['uri' => 'gs://' . $config['bucket'] . '/' . $gcsObject]],
			'recognitionOutputConfig' => ['inlineResponseConfig' => new \stdClass()],
			'processingStrategy' => 'DYNAMIC_BATCHING',
		];

		$payload = $this->request('post', $url, ['json' => $body, 'timeout' => 30]);
		$operation = $payload['name'] ?? null;
		$prefix = 'projects/' . $config['project'] . '/locations/' . $config['location'] . '/operations/';
		if (!is_string($operation) || !str_starts_with($operation, $prefix)) {
			throw new GoogleApiException('Speech recognition submission response was invalid');
		}
		return $operation;
	}

	/**
	 * @return array{done: bool, response?: array<string, mixed>, error?: array<string, mixed>}
	 */
	public function poll(string $operation): array {
		$config = $this->config->getValidated();
		$prefix = 'projects/' . $config['project'] . '/locations/' . $config['location'] . '/operations/';
		if (!str_starts_with($operation, $prefix)) {
			throw new GoogleApiException('Speech recognition operation name was invalid');
		}
		$payload = $this->request('get', $this->getEndpoint($config['location']) . '/v2/' . $operation, ['timeout' => 30]);
		if (($payload['done'] ?? false) !== true) {
			return ['done' => false];
		}
		if (is_array($payload['error'] ?? null)) {
			return ['done' => true, 'error' => $payload['error']];
		}
		if (!is_array($payload['response'] ?? null)) {
			throw new GoogleApiException('Speech recognition operation response was invalid');
		}
		return ['done' => true, 'response' => $payload['response']];
	}

	/** @return array<string, mixed> */
	private function request(string $method, string $url, array $options): array {
		$options['headers'] = ['Authorization' => 'Bearer ' . $this->tokenProvider->getAccessToken()];
		try {
			$response = $this->clientService->newClient()->{$method}($url, $options);
			$payload = json_decode((string)$response->getBody(), true, 64, JSON_THROW_ON_ERROR);
		} catch (\Throwable) {
			throw new GoogleApiException('Speech recognition request failed');
		}
		if (!is_array($payload)) {
			throw new GoogleApiException('Speech recognition response was invalid');
		}
		return $payload;
	}

	private function getEndpoint(string $location): string {
		return 'https://' . $location . '-speech.googleapis.com';
	}
}
