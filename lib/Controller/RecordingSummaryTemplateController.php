<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Controller;

use OCA\Talk\Recording\GoogleApiException;
use OCA\Talk\Recording\RecordingSummaryService;
use OCA\Talk\ResponseDefinitions;
use OCA\Talk\Service\RecordingSummaryTemplateService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * @psalm-import-type TalkRecordingSummaryTemplate from ResponseDefinitions
 */
class RecordingSummaryTemplateController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly RecordingSummaryTemplateService $service,
		private readonly RecordingSummaryService $summaryService,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
		private readonly ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List the summary templates the user can use
	 *
	 * Personal templates come first, then organization and built-in templates.
	 *
	 * @return DataResponse<Http::STATUS_OK, list<TalkRecordingSummaryTemplate>, array{}>
	 *
	 * 200: Recording summary templates returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/recording/summary-templates', requirements: ['apiVersion' => '(v1)'])]
	public function index(): DataResponse {
		return new DataResponse($this->service->list((string)$this->userId, $this->isAdmin()));
	}

	/**
	 * Create a summary template
	 *
	 * @param string $name Template name
	 * @param array<string, mixed> $definition Sections and options of the summary
	 * @param string $description Short description of the template
	 * @param bool $organization Whether all users can use the template (administrators only)
	 * @return DataResponse<Http::STATUS_CREATED, TalkRecordingSummaryTemplate, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
	 *
	 * 201: Recording summary template created
	 * 400: A field is invalid
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/recording/summary-templates', requirements: ['apiVersion' => '(v1)'])]
	public function create(string $name, array $definition, string $description = '', bool $organization = false): DataResponse {
		try {
			return new DataResponse($this->service->create((string)$this->userId, $this->isAdmin(), $name, $description, $definition, $organization), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Update a summary template
	 *
	 * @param string $templateId Template ID
	 * @param string $name Template name
	 * @param array<string, mixed> $definition Sections and options of the summary
	 * @param string $description Short description of the template
	 * @return DataResponse<Http::STATUS_OK, TalkRecordingSummaryTemplate, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, null, array{}>
	 *
	 * 200: Recording summary template updated
	 * 400: A field is invalid
	 * 404: Recording summary template not found or not editable
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/{apiVersion}/recording/summary-templates/{templateId}', requirements: ['apiVersion' => '(v1)', 'templateId' => '\\d+'])]
	public function update(string $templateId, string $name, array $definition, string $description = ''): DataResponse {
		try {
			return new DataResponse($this->service->update($templateId, (string)$this->userId, $this->isAdmin(), $name, $description, $definition));
		} catch (DoesNotExistException) {
			return new DataResponse(null, Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Delete a summary template
	 *
	 * @param string $templateId Template ID
	 * @return DataResponse<Http::STATUS_OK, null, array{}>|DataResponse<Http::STATUS_NOT_FOUND, null, array{}>
	 *
	 * 200: Recording summary template deleted
	 * 404: Recording summary template not found or not editable
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/{apiVersion}/recording/summary-templates/{templateId}', requirements: ['apiVersion' => '(v1)', 'templateId' => '\\d+'])]
	public function destroy(string $templateId): DataResponse {
		try {
			$this->service->delete($templateId, (string)$this->userId, $this->isAdmin());
			return new DataResponse(null);
		} catch (DoesNotExistException) {
			return new DataResponse(null, Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Set the default summary template of the user
	 *
	 * It is used for recordings in conversations without a summary template.
	 *
	 * @param string $templateId Template ID
	 * @return DataResponse<Http::STATUS_OK, null, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
	 *
	 * 200: Default template set
	 * 400: Template can not be used
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/{apiVersion}/recording/summary-templates/default', requirements: ['apiVersion' => '(v1)'])]
	public function setDefault(string $templateId): DataResponse {
		try {
			$this->service->setUserDefault((string)$this->userId, $templateId);
			return new DataResponse(null);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Summarize a sample meeting with a template
	 *
	 * @param array<string, mixed> $definition Sections and options of the summary
	 * @return DataResponse<Http::STATUS_OK, array{summary: string, transcript: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_SERVICE_UNAVAILABLE, array{error: string}, array{}>
	 *
	 * 200: Summary of the sample meeting returned
	 * 400: A field is invalid
	 * 503: The summary could not be generated
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 3600)]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/recording/summary-templates/preview', requirements: ['apiVersion' => '(v1)'])]
	public function preview(array $definition): DataResponse {
		try {
			return new DataResponse([
				'summary' => $this->summaryService->preview($definition),
				'transcript' => $this->summaryService->getSampleTranscript(),
			]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (GoogleApiException $e) {
			$this->logger->warning('Summary template preview failed', ['exception' => $e]);
			return new DataResponse(['error' => 'generation'], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}

	private function isAdmin(): bool {
		return $this->userId !== null && $this->groupManager->isAdmin($this->userId);
	}
}
