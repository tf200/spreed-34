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
	// The GA model gemini-3.5-transcribe is documented, but not served yet.
	public const DEFAULT_TRANSCRIPTION_MODEL = 'gemini-3.5-transcribe-preview';
	public const DEFAULT_TRANSCRIPTION_LOCATION = 'global';
	// gemini-2.5-flash-lite wrote some summaries in another language than
	// the meeting, and the 2.5 models are deprecated.
	public const DEFAULT_SUMMARY_MODEL = 'gemini-3.5-flash-lite';

	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}

	public function isEnabled(): bool {
		return $this->appConfig->getAppValueBool('recording_google_ai_enabled', false, true);
	}

	/**
	 * Whether recordings with participant tracks are transcribed per track.
	 */
	public function isMultitrackEnabled(): bool {
		return $this->isEnabled() && $this->appConfig->getAppValueBool('recording_google_multitrack_enabled');
	}

	/**
	 * The transcription runs on Vertex AI with the project and the service
	 * account of the Google AI processing.
	 *
	 * @return array{project: string, location: string, model: string, language: string}
	 * @throws InvalidArgumentException
	 */
	public function getTranscriptionConfig(): array {
		$base = $this->getValidated();
		$config = [
			'project' => $base['project'],
			'location' => $this->appConfig->getAppValueString('recording_google_transcription_location', self::DEFAULT_TRANSCRIPTION_LOCATION),
			'model' => $this->appConfig->getAppValueString('recording_google_transcription_model', self::DEFAULT_TRANSCRIPTION_MODEL),
			'language' => $base['language'],
		];
		if (!preg_match('/^(?:global|us|eu|[a-z]+-[a-z]+[0-9])$/', $config['location'])
			|| !preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $config['model'])) {
			throw new InvalidArgumentException('recording_google_transcription_invalid_configuration');
		}
		return $config;
	}

	/**
	 * The Gemini model of the summaries, which runs with the location of
	 * the transcript cleanup.
	 *
	 * @throws InvalidArgumentException
	 */
	public function getSummaryModel(): string {
		$model = $this->appConfig->getAppValueString('recording_google_summary_model', self::DEFAULT_SUMMARY_MODEL);
		if (!preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $model)) {
			throw new InvalidArgumentException('recording_google_summary_invalid_configuration');
		}
		return $model;
	}

	public static function isValidLanguage(string $language): bool {
		return $language === 'auto' || preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/', $language) === 1;
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
			'language' => $this->appConfig->getAppValueString('recording_google_language', 'auto'),
			'speechModel' => $this->appConfig->getAppValueString('recording_google_speech_model', 'chirp_3'),
			'geminiLocation' => $this->appConfig->getAppValueString('recording_google_gemini_location', 'global'),
			'geminiModel' => $this->appConfig->getAppValueString('recording_google_gemini_model', 'gemini-2.5-flash-lite'),
			'serviceAccount' => $serviceAccount,
		];
		if (!preg_match('/^[a-z][a-z0-9-]{4,28}[a-z0-9]$/', $config['project'])
			|| !preg_match('/^[a-z][a-z0-9-]{1,31}$/', $config['location'])
			|| !preg_match('/^[a-z0-9][a-z0-9._-]{1,220}[a-z0-9]$/', $config['bucket'])
			|| !self::isValidLanguage($config['language'])
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
