<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Service;

use OCA\Talk\AppInfo\Application;
use OCA\Talk\Model\RecordingRoomTemplate;
use OCA\Talk\Model\RecordingRoomTemplateMapper;
use OCA\Talk\Model\RecordingSummary;
use OCA\Talk\Model\RecordingSummaryMapper;
use OCA\Talk\Model\RecordingSummaryTemplate;
use OCA\Talk\Model\RecordingSummaryTemplateMapper;
use OCA\Talk\Recording\BuiltInSummaryTemplates;
use OCA\Talk\Recording\SummaryTemplateDefinition;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;

/**
 * Summary templates: the built-in ones, the organization ones managed by
 * administrators and the personal ones of each user.
 *
 * @psalm-import-type SummaryTemplateDefinitionArray from SummaryTemplateDefinition
 * @psalm-type SummaryTemplate = array{
 *     id: string,
 *     source: 'builtin'|'organization'|'personal',
 *     name: string,
 *     description: string,
 *     definition: SummaryTemplateDefinitionArray,
 *     canEdit: bool,
 *     isDefault: bool,
 *     updatedAt: int,
 * }
 * @psalm-type SummarySnapshot = array{id: ?string, name: string, instructions: string}
 */
class RecordingSummaryTemplateService {
	public const MAX_NAME_LENGTH = 250;
	public const MAX_DESCRIPTION_LENGTH = 500;
	public const USER_DEFAULT_KEY = 'recording_summary_template';

	public function __construct(
		private readonly RecordingSummaryTemplateMapper $mapper,
		private readonly RecordingSummaryMapper $summaryMapper,
		private readonly RecordingRoomTemplateMapper $roomTemplateMapper,
		private readonly BuiltInSummaryTemplates $builtInTemplates,
		private readonly IUserConfig $userConfig,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Templates the user can choose, in the order to show them.
	 *
	 * @return list<SummaryTemplate>
	 */
	public function list(string $userId, bool $isAdmin): array {
		$defaultId = $this->getUserDefaultId($userId);
		$templates = [];
		foreach ($this->mapper->findPersonal($userId) as $template) {
			$templates[] = $this->format($template, $isAdmin, $defaultId);
		}
		foreach ($this->mapper->findOrganization() as $template) {
			$templates[] = $this->format($template, $isAdmin, $defaultId);
		}
		foreach ($this->builtInTemplates->getAll() as $id => $template) {
			$templates[] = $this->formatBuiltIn($id, $template, $defaultId);
		}
		return $templates;
	}

	/**
	 * @return SummaryTemplate
	 * @throws \InvalidArgumentException when the user can not use the template
	 */
	public function get(string $id, string $userId, bool $isAdmin = false): array {
		$defaultId = $this->getUserDefaultId($userId);
		if (BuiltInSummaryTemplates::isBuiltIn($id)) {
			$template = $this->builtInTemplates->getAll()[$id] ?? null;
			if ($template === null) {
				throw new \InvalidArgumentException('summary_template');
			}
			return $this->formatBuiltIn($id, $template, $defaultId);
		}
		return $this->format($this->findUsable($id, $userId), $isAdmin, $defaultId);
	}

	/**
	 * @param array<string, mixed> $definition
	 * @return SummaryTemplate
	 * @throws \InvalidArgumentException with the name of the invalid field
	 */
	public function create(string $userId, bool $isAdmin, string $name, string $description, array $definition, bool $organization): array {
		if ($organization && !$isAdmin) {
			throw new \InvalidArgumentException('organization');
		}
		[$name, $description, $definition] = $this->validate($name, $description, $definition);
		$now = $this->timeFactory->getDateTime();
		$template = new RecordingSummaryTemplate();
		$template->setOwnerId($userId);
		$template->setScope($organization ? RecordingSummaryTemplate::SCOPE_ORGANIZATION : RecordingSummaryTemplate::SCOPE_USER);
		$template->setName($name);
		$template->setDescription($description);
		$template->setDefinition(json_encode($definition, JSON_THROW_ON_ERROR));
		$template->setCreatedAt($now);
		$template->setUpdatedAt($now);
		return $this->format($this->mapper->insert($template), $isAdmin, $this->getUserDefaultId($userId));
	}

	/**
	 * @param array<string, mixed> $definition
	 * @return SummaryTemplate
	 * @throws DoesNotExistException when the user can not edit the template
	 * @throws \InvalidArgumentException with the name of the invalid field
	 */
	public function update(string $id, string $userId, bool $isAdmin, string $name, string $description, array $definition): array {
		[$name, $description, $definition] = $this->validate($name, $description, $definition);
		$template = $this->findEditable($id, $userId, $isAdmin);
		$template->setName($name);
		$template->setDescription($description);
		$template->setDefinition(json_encode($definition, JSON_THROW_ON_ERROR));
		$template->setUpdatedAt($this->timeFactory->getDateTime());
		return $this->format($this->mapper->update($template), $isAdmin, $this->getUserDefaultId($userId));
	}

	/** @throws DoesNotExistException when the user can not edit the template */
	public function delete(string $id, string $userId, bool $isAdmin): void {
		$this->mapper->delete($this->findEditable($id, $userId, $isAdmin));
		$this->roomTemplateMapper->deleteByTemplateId($id);
	}

	/**
	 * @throws \InvalidArgumentException when the user can not use the template
	 */
	public function setUserDefault(string $userId, string $id): void {
		$this->get($id, $userId);
		if ($id === BuiltInSummaryTemplates::DEFAULT_ID) {
			$this->userConfig->deleteUserConfig($userId, Application::APP_ID, self::USER_DEFAULT_KEY);
			return;
		}
		$this->userConfig->setValueString($userId, Application::APP_ID, self::USER_DEFAULT_KEY, $id);
	}

	/**
	 * The template used for the recordings of the conversation, if one was
	 * chosen and is still available.
	 *
	 * @return ?SummaryTemplate
	 */
	public function getRoomTemplate(string $roomToken): ?array {
		try {
			$roomTemplate = $this->roomTemplateMapper->findByRoomToken($roomToken);
			return $this->get($roomTemplate->getTemplateId(), $roomTemplate->getActorId());
		} catch (DoesNotExistException|\InvalidArgumentException) {
			return null;
		}
	}

	/**
	 * @param ?string $id null to use the default template of the moderator
	 *                    starting the recording
	 * @throws \InvalidArgumentException when the user can not use the template
	 */
	public function setRoomTemplate(string $roomToken, ?string $id, string $userId): void {
		if ($id === null) {
			$this->roomTemplateMapper->deleteByRoomToken($roomToken);
			return;
		}
		$this->get($id, $userId);

		try {
			$roomTemplate = $this->roomTemplateMapper->findByRoomToken($roomToken);
		} catch (DoesNotExistException) {
			$roomTemplate = new RecordingRoomTemplate();
			$roomTemplate->setRoomToken($roomToken);
		}
		$roomTemplate->setTemplateId($id);
		$roomTemplate->setActorId($userId);
		$roomTemplate->setUpdatedAt($this->timeFactory->getDateTime());
		if ($roomTemplate->getId() === null) {
			$this->roomTemplateMapper->insert($roomTemplate);
		} else {
			$this->roomTemplateMapper->update($roomTemplate);
		}
	}

	public function deleteRoomTemplate(string $roomToken): void {
		$this->roomTemplateMapper->deleteByRoomToken($roomToken);
	}

	/**
	 * Freezes the template for a recording, so later changes of the template
	 * do not change its summary.
	 *
	 * Without an explicit template the template of the conversation is used,
	 * then the default template of the user, then the built-in default.
	 *
	 * @return SummarySnapshot
	 * @throws \InvalidArgumentException when the user can not use the template
	 */
	public function snapshot(?string $id, string $userId, ?string $roomToken = null): array {
		if ($id !== null) {
			$template = $this->get($id, $userId);
		} else {
			$template = ($roomToken !== null ? $this->getRoomTemplate($roomToken) : null)
				?? $this->get($this->getUserDefaultId($userId), $userId);
		}
		return [
			'id' => $template['id'],
			'name' => $template['name'],
			'instructions' => SummaryTemplateDefinition::compile($template['definition']),
		];
	}

	/** @param SummarySnapshot $snapshot */
	public function persistSnapshot(int $recordingFileId, string $ownerId, array $snapshot): void {
		try {
			$this->summaryMapper->findByRecordingFileId($recordingFileId);
			return;
		} catch (DoesNotExistException) {
		}
		$summary = new RecordingSummary();
		$summary->setRecordingFileId($recordingFileId);
		$summary->setOwnerId($ownerId);
		$this->applySnapshot($summary, $snapshot);
		$summary->setCreatedAt($this->timeFactory->getDateTime());
		$this->summaryMapper->insert($summary);
	}

	/**
	 * Records the template a summary was regenerated with.
	 *
	 * @param SummarySnapshot $snapshot
	 */
	public function replaceSnapshot(int $recordingFileId, string $ownerId, array $snapshot): void {
		try {
			$summary = $this->summaryMapper->findByRecordingFileId($recordingFileId);
		} catch (DoesNotExistException) {
			$this->persistSnapshot($recordingFileId, $ownerId, $snapshot);
			return;
		}
		$this->applySnapshot($summary, $snapshot);
		$this->summaryMapper->update($summary);
	}

	/** @return SummarySnapshot */
	public function findSnapshot(int $recordingFileId): array {
		try {
			$summary = $this->summaryMapper->findByRecordingFileId($recordingFileId);
			return ['id' => $summary->getTemplateId() === null ? null : (string)$summary->getTemplateId(), 'name' => $summary->getTemplateName(), 'instructions' => $summary->getInstructions()];
		} catch (DoesNotExistException) {
			// Recordings already in flight during an upgrade use the built-in default.
			return $this->defaultSnapshot();
		}
	}

	/** @return SummarySnapshot */
	public function defaultSnapshot(): array {
		$template = $this->builtInTemplates->getAll()[BuiltInSummaryTemplates::DEFAULT_ID];
		return ['id' => BuiltInSummaryTemplates::DEFAULT_ID, 'name' => $template['name'], 'instructions' => SummaryTemplateDefinition::compile($template['definition'])];
	}

	/** @param SummarySnapshot $snapshot */
	private function applySnapshot(RecordingSummary $summary, array $snapshot): void {
		$summary->setTemplateId($snapshot['id'] !== null && ctype_digit($snapshot['id']) ? (int)$snapshot['id'] : null);
		$summary->setTemplateName($snapshot['name']);
		$summary->setInstructions($snapshot['instructions']);
	}

	private function getUserDefaultId(string $userId): string {
		$id = $this->userConfig->getValueString($userId, Application::APP_ID, self::USER_DEFAULT_KEY, BuiltInSummaryTemplates::DEFAULT_ID);
		if (BuiltInSummaryTemplates::isBuiltIn($id)) {
			return isset($this->builtInTemplates->getAll()[$id]) ? $id : BuiltInSummaryTemplates::DEFAULT_ID;
		}
		try {
			$this->findUsable($id, $userId);
			return $id;
		} catch (\InvalidArgumentException) {
			return BuiltInSummaryTemplates::DEFAULT_ID;
		}
	}

	/**
	 * @throws \InvalidArgumentException when the user can not use the template
	 */
	private function findUsable(string $id, string $userId): RecordingSummaryTemplate {
		if (!ctype_digit($id)) {
			throw new \InvalidArgumentException('summary_template');
		}
		try {
			$template = $this->mapper->findById($id);
		} catch (DoesNotExistException) {
			throw new \InvalidArgumentException('summary_template');
		}
		if ($template->getScope() !== RecordingSummaryTemplate::SCOPE_ORGANIZATION && $template->getOwnerId() !== $userId) {
			throw new \InvalidArgumentException('summary_template');
		}
		return $template;
	}

	/**
	 * @throws DoesNotExistException
	 */
	private function findEditable(string $id, string $userId, bool $isAdmin): RecordingSummaryTemplate {
		$template = $this->mapper->findById($id);
		if (!$this->canEdit($template, $userId, $isAdmin)) {
			throw new DoesNotExistException('Template can not be edited');
		}
		return $template;
	}

	private function canEdit(RecordingSummaryTemplate $template, string $userId, bool $isAdmin): bool {
		return $template->getScope() === RecordingSummaryTemplate::SCOPE_ORGANIZATION
			? $isAdmin
			: $template->getOwnerId() === $userId;
	}

	/**
	 * @param array<string, mixed> $definition
	 * @return array{string, string, SummaryTemplateDefinitionArray}
	 */
	private function validate(string $name, string $description, array $definition): array {
		$name = trim($name);
		$description = trim($description);
		if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
			throw new \InvalidArgumentException('name');
		}
		if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
			throw new \InvalidArgumentException('description');
		}
		return [$name, $description, SummaryTemplateDefinition::normalize($definition)];
	}

	/** @return SummaryTemplate */
	private function format(RecordingSummaryTemplate $template, bool $isAdmin, string $defaultId): array {
		$id = (string)$template->getId();
		return [
			'id' => $id,
			'source' => $template->getScope() === RecordingSummaryTemplate::SCOPE_ORGANIZATION ? 'organization' : 'personal',
			'name' => $template->getName(),
			'description' => $template->getDescription() ?? '',
			'definition' => $this->decodeDefinition($template->getDefinition()),
			// Only usable templates are formatted, so personal ones are the user's own.
			'canEdit' => $template->getScope() !== RecordingSummaryTemplate::SCOPE_ORGANIZATION || $isAdmin,
			'isDefault' => $id === $defaultId,
			'updatedAt' => $template->getUpdatedAt()->getTimestamp(),
		];
	}

	/**
	 * @param array{name: string, description: string, definition: SummaryTemplateDefinitionArray} $template
	 * @return SummaryTemplate
	 */
	private function formatBuiltIn(string $id, array $template, string $defaultId): array {
		return [
			'id' => $id,
			'source' => 'builtin',
			'name' => $template['name'],
			'description' => $template['description'],
			'definition' => $template['definition'],
			'canEdit' => false,
			'isDefault' => $id === $defaultId,
			'updatedAt' => 0,
		];
	}

	/** @return SummaryTemplateDefinitionArray */
	private function decodeDefinition(?string $definition): array {
		try {
			return SummaryTemplateDefinition::normalize(json_decode((string)$definition, true, 8, JSON_THROW_ON_ERROR));
		} catch (\JsonException|\InvalidArgumentException) {
			// Stored definitions are validated, so this is only a safety net.
			return $this->builtInTemplates->getAll()[BuiltInSummaryTemplates::DEFAULT_ID]['definition'];
		}
	}
}
