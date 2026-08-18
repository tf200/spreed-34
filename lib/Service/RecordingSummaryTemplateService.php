<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Service;

use OCA\Talk\Model\RecordingSummary;
use OCA\Talk\Model\RecordingSummaryMapper;
use OCA\Talk\Model\RecordingSummaryTemplate;
use OCA\Talk\Model\RecordingSummaryTemplateMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

class RecordingSummaryTemplateService {
	public const MAX_NAME_LENGTH = 250;
	public const MAX_INSTRUCTIONS_LENGTH = 10000;
	public const DEFAULT_NAME = 'Default summary';
	public const DEFAULT_INSTRUCTIONS = 'Summarize the meeting in concise Markdown. Include Overview, Decisions, Action items, and Open questions. Only name owners or deadlines explicitly stated in the transcript. Do not invent facts.';

	public function __construct(
		private readonly RecordingSummaryTemplateMapper $mapper,
		private readonly RecordingSummaryMapper $summaryMapper,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/** @return array{id: ?string, name: string, instructions: string} */
	public function snapshot(?string $id, string $ownerId): array {
		if ($id === null) {
			return ['id' => null, 'name' => self::DEFAULT_NAME, 'instructions' => self::DEFAULT_INSTRUCTIONS];
		}
		try {
			$template = $this->mapper->findByIdAndOwner($id, $ownerId);
		} catch (DoesNotExistException) {
			throw new \InvalidArgumentException('summary_template');
		}
		return ['id' => (string)$template->getId(), 'name' => $template->getName(), 'instructions' => $template->getInstructions()];
	}

	/** @param array{id: ?string, name: string, instructions: string} $snapshot */
	public function persistSnapshot(int $recordingFileId, string $ownerId, array $snapshot): void {
		try {
			$this->summaryMapper->findByRecordingFileId($recordingFileId);
			return;
		} catch (DoesNotExistException) {
		}
		$summary = new RecordingSummary();
		$summary->setRecordingFileId($recordingFileId);
		$summary->setOwnerId($ownerId);
		$summary->setTemplateId($snapshot['id'] === null ? null : (int)$snapshot['id']);
		$summary->setTemplateName($snapshot['name']);
		$summary->setInstructions($snapshot['instructions']);
		$summary->setCreatedAt($this->timeFactory->getDateTime());
		$this->summaryMapper->insert($summary);
	}

	/** @return array{id: ?string, name: string, instructions: string} */
	public function findSnapshot(int $recordingFileId): array {
		try {
			$summary = $this->summaryMapper->findByRecordingFileId($recordingFileId);
			return ['id' => $summary->getTemplateId() === null ? null : (string)$summary->getTemplateId(), 'name' => $summary->getTemplateName(), 'instructions' => $summary->getInstructions()];
		} catch (DoesNotExistException) {
			// Recordings already in flight during an upgrade use the previous built-in behavior.
			return $this->snapshot(null, '');
		}
	}

	/** @return list<array{id: numeric-string, ownerId: string, name: string, instructions: string, createdAt: int, updatedAt: int}> */
	public function list(string $ownerId): array {
		return array_values(array_map($this->format(...), $this->mapper->findAllByOwner($ownerId)));
	}

	/** @return array{id: numeric-string, ownerId: string, name: string, instructions: string, createdAt: int, updatedAt: int} */
	public function create(string $ownerId, string $name, string $instructions): array {
		[$name, $instructions] = $this->validate($name, $instructions);
		$now = $this->timeFactory->getDateTime();
		$template = new RecordingSummaryTemplate();
		$template->setOwnerId($ownerId);
		$template->setName($name);
		$template->setInstructions($instructions);
		$template->setCreatedAt($now);
		$template->setUpdatedAt($now);
		return $this->format($this->mapper->insert($template));
	}

	/** @return array{id: numeric-string, ownerId: string, name: string, instructions: string, createdAt: int, updatedAt: int} */
	public function update(string $id, string $ownerId, string $name, string $instructions): array {
		[$name, $instructions] = $this->validate($name, $instructions);
		$template = $this->mapper->findByIdAndOwner($id, $ownerId);
		$template->setName($name);
		$template->setInstructions($instructions);
		$template->setUpdatedAt($this->timeFactory->getDateTime());
		return $this->format($this->mapper->update($template));
	}

	/** @throws DoesNotExistException */
	public function delete(string $id, string $ownerId): void {
		$this->mapper->delete($this->mapper->findByIdAndOwner($id, $ownerId));
	}

	/** @return array{string, string} */
	private function validate(string $name, string $instructions): array {
		$name = trim($name);
		$instructions = trim($instructions);
		if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
			throw new \InvalidArgumentException('name');
		}
		if ($instructions === '' || mb_strlen($instructions) > self::MAX_INSTRUCTIONS_LENGTH) {
			throw new \InvalidArgumentException('instructions');
		}
		return [$name, $instructions];
	}

	/** @return array{id: numeric-string, ownerId: string, name: string, instructions: string, createdAt: int, updatedAt: int} */
	private function format(RecordingSummaryTemplate $template): array {
		return [
			'id' => (string)$template->getId(),
			'ownerId' => $template->getOwnerId(),
			'name' => $template->getName(),
			'instructions' => $template->getInstructions(),
			'createdAt' => $template->getCreatedAt()->getTimestamp(),
			'updatedAt' => $template->getUpdatedAt()->getTimestamp(),
		];
	}
}
