<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Recording;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;

class GoogleAuthTokenProvider {
	private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	private const SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

	private ?string $accessToken = null;
	private int $expiresAt = 0;

	public function __construct(
		private readonly GoogleAiConfig $config,
		private readonly IClientService $clientService,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	public function getAccessToken(): string {
		$now = $this->timeFactory->getTime();
		if ($this->accessToken !== null && $this->expiresAt > $now + 60) {
			return $this->accessToken;
		}

		$serviceAccount = $this->config->getValidated()['serviceAccount'];
		$assertion = $this->createAssertion($serviceAccount, $now);
		try {
			$response = $this->clientService->newClient()->post(self::TOKEN_URL, [
				'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
				'body' => http_build_query([
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion' => $assertion,
				], '', '&', PHP_QUERY_RFC3986),
				'timeout' => 15,
			]);
			$payload = json_decode((string)$response->getBody(), true, 16, JSON_THROW_ON_ERROR);
		} catch (\Throwable) {
			throw new GoogleApiException('Google OAuth token request failed');
		}

		if (!is_array($payload)
			|| !is_string($payload['access_token'] ?? null)
			|| $payload['access_token'] === ''
			|| !is_int($payload['expires_in'] ?? null)
			|| $payload['expires_in'] <= 60) {
			throw new GoogleApiException('Google OAuth token response was invalid');
		}

		$this->accessToken = $payload['access_token'];
		$this->expiresAt = $now + $payload['expires_in'];
		return $this->accessToken;
	}

	/** @param array<string, mixed> $serviceAccount */
	private function createAssertion(array $serviceAccount, int $now): string {
		$header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
		$claims = $this->base64UrlEncode(json_encode([
			'iss' => $serviceAccount['client_email'],
			'scope' => self::SCOPE,
			'aud' => self::TOKEN_URL,
			'iat' => $now,
			'exp' => $now + 3600,
		], JSON_THROW_ON_ERROR));
		$unsigned = $header . '.' . $claims;
		if (!openssl_sign($unsigned, $signature, $serviceAccount['private_key'], OPENSSL_ALGO_SHA256)) {
			throw new GoogleApiException('Google service-account private key could not sign requests');
		}
		return $unsigned . '.' . $this->base64UrlEncode($signature);
	}

	private function base64UrlEncode(string $value): string {
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}
}
