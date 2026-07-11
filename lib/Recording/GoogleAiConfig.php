<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Recording;

use InvalidArgumentException;
use OCP\AppFramework\Services\IAppConfig;

class GoogleAiConfig {
	public function __construct(private readonly IAppConfig $appConfig) {
	}

	public function isEnabled(): bool {
		return $this->appConfig->getAppValueBool('recording_google_ai_enabled', false, true);
	}

	/** @return array{project: string, location: string, bucket: string, language: string, speechModel: string, geminiLocation: string, geminiModel: string, serviceAccount: array<string, mixed>} */
	public function getValidated(): array {
		$serviceAccountJson = $this->appConfig->getAppValueString('recording_google_service_account', lazy: true);
		try {
			$serviceAccount = json_decode($serviceAccountJson, true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new InvalidArgumentException('recording_google_ai_invalid_configuration');
		}
		$config = [
			'project' => $this->appConfig->getAppValueString('recording_google_project'),
			'location' => $this->appConfig->getAppValueString('recording_google_location', 'eu'),
			'bucket' => $this->appConfig->getAppValueString('recording_google_bucket'),
			'language' => $this->appConfig->getAppValueString('recording_google_language', 'en-US'),
			'speechModel' => $this->appConfig->getAppValueString('recording_google_speech_model', 'chirp_3'),
			'geminiLocation' => $this->appConfig->getAppValueString('recording_google_gemini_location', 'global'),
			'geminiModel' => $this->appConfig->getAppValueString('recording_google_gemini_model', 'gemini-2.5-flash-lite'),
			'serviceAccount' => $serviceAccount,
		];
		if (!preg_match('/^[a-z][a-z0-9-]{4,28}[a-z0-9]$/', $config['project'])
			|| !preg_match('/^[a-z][a-z0-9-]{1,31}$/', $config['location'])
			|| !preg_match('/^[a-z0-9][a-z0-9._-]{1,220}[a-z0-9]$/', $config['bucket'])
			|| !preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/', $config['language'])
			|| !preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $config['speechModel'])
			|| !preg_match('/^(?:global|[a-z]+-[a-z]+[0-9])$/', $config['geminiLocation'])
			|| !preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $config['geminiModel'])
			|| !is_array($serviceAccount)
			|| ($serviceAccount['type'] ?? null) !== 'service_account'
			|| !is_string($serviceAccount['client_email'] ?? null)
			|| !is_string($serviceAccount['private_key'] ?? null)) {
			throw new InvalidArgumentException('recording_google_ai_invalid_configuration');
		}
		return $config;
	}
}
