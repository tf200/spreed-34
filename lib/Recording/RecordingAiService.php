<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Recording;

use OCA\Talk\BackgroundJob\SubmitRecordingAi;
use OCA\Talk\Model\RecordingAiOperation;
use OCA\Talk\Model\RecordingAiOperationMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;

class RecordingAiService {
	public function __construct(
		private readonly RecordingAiOperationMapper $mapper,
		private readonly GoogleAiConfig $config,
		private readonly ITimeFactory $timeFactory,
		private readonly IJobList $jobList,
	) {
	}

	public function enqueue(int $recordingFileId, string $ownerId, string $roomToken): ?RecordingAiOperation {
		if (!$this->config->isEnabled()) {
			return null;
		}
		$this->config->getValidated();

		try {
			return $this->mapper->findByRecordingFileId($recordingFileId);
		} catch (DoesNotExistException) {
			// Create the durable operation below.
		}

		$now = $this->timeFactory->getDateTime();
		$deadline = clone $now;
		$deadline->add(new \DateInterval('P1D'));
		$operation = new RecordingAiOperation();
		$operation->setRecordingFileId($recordingFileId);
		$operation->setOwnerId($ownerId);
		$operation->setRoomToken($roomToken);
		$operation->setState(RecordingAiOperation::STATE_QUEUED);
		$operation->setNextAttemptAt($now);
		$operation->setDeadlineAt($deadline);
		$operation->setCreatedAt($now);
		$operation->setUpdatedAt($now);
		$operation = $this->mapper->insert($operation);
		$this->jobList->add(SubmitRecordingAi::class, ['operationId' => (int)$operation->getId()]);
		return $operation;
	}
}
