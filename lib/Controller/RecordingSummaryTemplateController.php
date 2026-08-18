<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Controller;

use OCA\Talk\ResponseDefinitions;
use OCA\Talk\Service\RecordingSummaryTemplateService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/** @psalm-import-type TalkRecordingSummaryTemplate from ResponseDefinitions */
class RecordingSummaryTemplateController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly RecordingSummaryTemplateService $service,
		private readonly ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List recording summary templates
	 *
	 * @return DataResponse<Http::STATUS_OK, list<TalkRecordingSummaryTemplate>, array{}>
	 *
	 * 200: Recording summary templates returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/recording/summary-templates', requirements: ['apiVersion' => '(v1)'])]
	public function index(): DataResponse {
		return new DataResponse($this->service->list($this->userId));
	}

	/**
	 * Create a recording summary template
	 *
	 * @param string $name Template name
	 * @param string $instructions Instructions for generating the summary
	 * @return DataResponse<Http::STATUS_CREATED, TalkRecordingSummaryTemplate, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>
	 *
	 * 201: Recording summary template created
	 * 400: Name or instructions are invalid
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/{apiVersion}/recording/summary-templates', requirements: ['apiVersion' => '(v1)'])]
	public function create(string $name, string $instructions): DataResponse {
		try {
			return new DataResponse($this->service->create($this->userId, $name, $instructions), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Update a recording summary template
	 *
	 * @param string $templateId Template ID
	 * @param string $name Template name
	 * @param string $instructions Instructions for generating the summary
	 * @return DataResponse<Http::STATUS_OK, TalkRecordingSummaryTemplate, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{error: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, null, array{}>
	 *
	 * 200: Recording summary template updated
	 * 400: Name or instructions are invalid
	 * 404: Recording summary template not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/{apiVersion}/recording/summary-templates/{templateId}', requirements: ['apiVersion' => '(v1)', 'templateId' => '\\d+'])]
	public function update(string $templateId, string $name, string $instructions): DataResponse {
		try {
			return new DataResponse($this->service->update($templateId, $this->userId, $name, $instructions));
		} catch (DoesNotExistException) {
			return new DataResponse(null, Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Delete a recording summary template
	 *
	 * @param string $templateId Template ID
	 * @return DataResponse<Http::STATUS_OK, null, array{}>|DataResponse<Http::STATUS_NOT_FOUND, null, array{}>
	 *
	 * 200: Recording summary template deleted
	 * 404: Recording summary template not found
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/{apiVersion}/recording/summary-templates/{templateId}', requirements: ['apiVersion' => '(v1)', 'templateId' => '\\d+'])]
	public function destroy(string $templateId): DataResponse {
		try {
			$this->service->delete($templateId, $this->userId);
			return new DataResponse(null);
		} catch (DoesNotExistException) {
			return new DataResponse(null, Http::STATUS_NOT_FOUND);
		}
	}
}
