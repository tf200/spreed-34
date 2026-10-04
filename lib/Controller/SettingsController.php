<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Controller;

use OCA\Talk\Recording\GoogleAiConfig;
use OCA\Talk\Settings\BeforePreferenceSetEventListener;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use SensitiveParameter;

class SettingsController extends OCSController {

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IConfig $config,
		private readonly IGroupManager $groupManager,
		private readonly BeforePreferenceSetEventListener $preferenceListener,
		private readonly ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Update user setting
	 *
	 * @param 'attachment_folder'|'read_status_privacy'|'typing_privacy'|'play_sounds' $key Key to update
	 * @param string|int|null $value New value for the key
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST, null, array{}>
	 *
	 * 200: User setting updated successfully
	 * 400: Updating user setting is not possible
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/settings/user', requirements: [
		'apiVersion' => '(v1)',
	])]
	public function setUserSetting(string $key, string|int|null $value): DataResponse {
		if (!$this->preferenceListener->validatePreference($this->userId, $key, $value)) {
			return new DataResponse(null, Http::STATUS_BAD_REQUEST);
		}

		$this->config->setUserValue($this->userId, 'spreed', $key, $value);

		return new DataResponse(null);
	}

	/**
	 * Update SIP bridge settings
	 *
	 * @param list<string> $sipGroups New SIP groups
	 * @param string $dialInInfo New dial info
	 * @param string $sharedSecret New shared secret
	 * @return DataResponse<Http::STATUS_OK, null, array{}>
	 *
	 * 200: Successfully set new SIP settings
	 */
	#[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION, tags: ['settings'])]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/settings/sip', requirements: [
		'apiVersion' => '(v1)',
	])]
	public function setSIPSettings(
		array $sipGroups = [],
		string $dialInInfo = '',
		string $sharedSecret = ''): DataResponse {
		$groups = [];
		foreach ($sipGroups as $gid) {
			$group = $this->groupManager->get($gid);
			if ($group instanceof IGroup) {
				$groups[] = $group->getGID();
			}
		}

		$this->config->setAppValue('spreed', 'sip_bridge_groups', json_encode($groups));
		$this->config->setAppValue('spreed', 'sip_bridge_dialin_info', $dialInInfo);
		$this->config->setAppValue('spreed', 'sip_bridge_shared_secret', $sharedSecret);

		return new DataResponse(null);
	}

	/**
	 * Configure Google transcription and summarization
	 *
	 * @param bool $enabled Whether Google AI processing is enabled
	 * @param string $project Google Cloud project ID
	 * @param string $location Google Speech region
	 * @param string $bucket Google Cloud Storage bucket
	 * @param string $language Speech recognition language
	 * @param string $speechModel Google Speech model
	 * @param string $geminiLocation Gemini region
	 * @param string $geminiModel Gemini model
	 * @param string $serviceAccountJson Service account JSON credential
	 * @param bool $removeServiceAccount Whether to remove the stored credential
	 * @param bool $multitrackEnabled Whether to transcribe recordings per participant track
	 * @param string $transcriptionModel Gemini Transcribe model to transcribe participant tracks
	 * @return DataResponse<Http::STATUS_OK, null, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, null, array{}>
	 *
	 * 200: Settings updated
	 * 400: Settings are invalid
	 */
	#[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION, tags: ['settings'])]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/settings/recording/google', requirements: ['apiVersion' => '(v1)'])]
	public function setRecordingGoogleSettings(
		bool $enabled,
		string $project,
		string $location,
		string $bucket,
		string $language,
		string $speechModel,
		string $geminiLocation,
		string $geminiModel,
		#[SensitiveParameter] string $serviceAccountJson = '',
		bool $removeServiceAccount = false,
		bool $multitrackEnabled = false,
		string $transcriptionModel = GoogleAiConfig::DEFAULT_TRANSCRIPTION_MODEL,
	): DataResponse {
		if (!preg_match('/^[a-z][a-z0-9-]{4,28}[a-z0-9]$/', $project)
			|| !preg_match('/^[a-z][a-z0-9-]{1,31}$/', $location)
			|| !preg_match('/^[a-z0-9][a-z0-9._-]{1,220}[a-z0-9]$/', $bucket)
			|| !GoogleAiConfig::isValidLanguage($language)
			|| !preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $speechModel)
			|| !preg_match('/^(?:global|[a-z]+-[a-z]+[0-9])$/', $geminiLocation)
			|| !preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $geminiModel)
			|| !preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $transcriptionModel)) {
			return new DataResponse(null, Http::STATUS_BAD_REQUEST);
		}

		if ($serviceAccountJson !== '') {
			try {
				$credential = json_decode($serviceAccountJson, true, 16, JSON_THROW_ON_ERROR);
			} catch (\JsonException) {
				return new DataResponse(null, Http::STATUS_BAD_REQUEST);
			}
			if (!is_array($credential)
				|| ($credential['type'] ?? null) !== 'service_account'
				|| !filter_var($credential['client_email'] ?? null, FILTER_VALIDATE_EMAIL)
				|| !is_string($credential['private_key'] ?? null)
				|| !str_contains($credential['private_key'], 'BEGIN PRIVATE KEY')) {
				return new DataResponse(null, Http::STATUS_BAD_REQUEST);
			}
		}

		$values = compact('project', 'location', 'bucket', 'language', 'speechModel', 'geminiLocation', 'geminiModel', 'transcriptionModel');
		foreach ($values as $key => $value) {
			$this->config->setAppValue('spreed', 'recording_google_' . strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $key)), $value);
		}
		$this->config->setAppValue('spreed', 'recording_google_ai_enabled', $enabled ? 'yes' : 'no');
		$this->config->setAppValue('spreed', 'recording_google_multitrack_enabled', $multitrackEnabled ? 'yes' : 'no');
		if ($removeServiceAccount) {
			$this->config->deleteAppValue('spreed', 'recording_google_service_account');
		} elseif ($serviceAccountJson !== '') {
			$this->config->setAppValue('spreed', 'recording_google_service_account', $serviceAccountJson);
		}
		return new DataResponse(null);
	}
}
