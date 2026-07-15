<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\Model;

use OCA\Talk\Model\RecordingAiOperation;
use OCA\Talk\Model\RecordingAiOperationMapper;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\Group;
use Test\TestCase;

#[Group('DB')]
class RecordingAiOperationMapperTest extends TestCase {
	private IDBConnection $db;
	private RecordingAiOperationMapper $mapper;
	private string $ownerId;

	protected function setUp(): void {
		parent::setUp();

		$this->db = \OCP\Server::get(IDBConnection::class);
		$prefix = \OCP\Server::get(IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
		$schema = $this->db->createSchema();
		if (!$schema->hasTable($prefix . 'talk_recording_ai')
			|| !$schema->getTable($prefix . 'talk_recording_ai')->hasColumn('claim_token')
			|| !$schema->getTable($prefix . 'talk_recording_ai')->hasColumn('claim_until')) {
			$this->markTestSkipped('Recording AI claim migration has not been applied');
		}

		$this->mapper = new RecordingAiOperationMapper($this->db);
		$this->ownerId = 'recording-ai-mapper-test-' . bin2hex(random_bytes(6));
	}

	protected function tearDown(): void {
		if (isset($this->ownerId)) {
			$query = $this->db->getQueryBuilder();
			$query->delete('talk_recording_ai')
				->where($query->expr()->eq('owner_id', $query->createNamedParameter($this->ownerId)));
			$query->executeStatement();
		}
		parent::tearDown();
	}

	public function testClaimAndUpdateAreFencedByToken(): void {
		$now = new \DateTime('2026-07-15T10:00:00+00:00');
		$operation = $this->insertOperation($now);
		$claimUntil = (clone $now)->modify('+5 minutes');

		$this->assertTrue($this->mapper->claimForUpload((int)$operation->getId(), 'first-token', $now, $claimUntil));
		$this->assertFalse($this->mapper->claimForUpload((int)$operation->getId(), 'second-token', $now, $claimUntil));
		$this->assertTrue($this->mapper->updateUploadCheckpoint((int)$operation->getId(), 'first-token', 'recording-object', $now));

		$claimed = $this->mapper->findById((int)$operation->getId());
		$this->assertSame(RecordingAiOperation::STATE_UPLOADING, $claimed->getState());
		$this->assertSame('first-token', $claimed->getClaimToken());
		$this->assertSame('recording-object', $claimed->getGcsObject());
		$claimed->setState(RecordingAiOperation::STATE_TRANSCRIBING);
		$claimed->setSpeechOperation('speech-operation');
		$claimed->setNextAttemptAt((clone $now)->modify('+1 minute'));
		$claimed->setUpdatedAt($now);

		$this->assertFalse($this->mapper->updateClaimed($claimed, 'second-token'));
		$this->assertTrue($this->mapper->updateClaimed($claimed, 'first-token'));

		$updated = $this->mapper->findById((int)$operation->getId());
		$this->assertSame(RecordingAiOperation::STATE_TRANSCRIBING, $updated->getState());
		$this->assertSame('speech-operation', $updated->getSpeechOperation());
		$this->assertNull($updated->getClaimToken());
		$this->assertNull($updated->getClaimUntil());
		$this->assertFalse($this->mapper->clearGcsObject((int)$operation->getId(), 'other-object'));
		$this->assertTrue($this->mapper->clearGcsObject((int)$operation->getId(), 'recording-object'));
		$this->assertNull($this->mapper->findById((int)$operation->getId())->getGcsObject());
	}

	public function testExpiredClaimCanBeReclaimed(): void {
		$now = new \DateTime('2026-07-15T10:00:00+00:00');
		$operation = $this->insertOperation($now);

		$this->assertTrue($this->mapper->claimForUpload(
			(int)$operation->getId(),
			'first-token',
			$now,
			(clone $now)->modify('-1 second'),
		));
		$this->assertTrue($this->mapper->claimForUpload(
			(int)$operation->getId(),
			'second-token',
			$now,
			(clone $now)->modify('+5 minutes'),
		));

		$this->assertSame('second-token', $this->mapper->findById((int)$operation->getId())->getClaimToken());
	}

	public function testDueSelectionExcludesActiveClaim(): void {
		$now = new \DateTime('2026-07-15T10:00:00+00:00');
		$operation = $this->insertOperation($now);
		$this->assertContains((int)$operation->getId(), $this->mapper->findDueIds($now));

		$this->assertTrue($this->mapper->claimForUpload(
			(int)$operation->getId(),
			'claim-token',
			$now,
			(clone $now)->modify('+5 minutes'),
		));

		$this->assertNotContains((int)$operation->getId(), $this->mapper->findDueIds($now));
		$this->assertContains((int)$operation->getId(), $this->mapper->findDueIds((clone $now)->modify('+6 minutes')));
	}

	private function insertOperation(\DateTime $now): RecordingAiOperation {
		$operation = new RecordingAiOperation();
		$operation->setRecordingFileId(random_int(1_000_000_000, 2_000_000_000));
		$operation->setOwnerId($this->ownerId);
		$operation->setRoomToken('room-token');
		$operation->setState(RecordingAiOperation::STATE_QUEUED);
		$operation->setAttempts(0);
		$operation->setNextAttemptAt($now);
		$operation->setDeadlineAt((clone $now)->modify('+1 day'));
		$operation->setUpdatedAt($now);
		return $this->mapper->insert($operation);
	}
}
