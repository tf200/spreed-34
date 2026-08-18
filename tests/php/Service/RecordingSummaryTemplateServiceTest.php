<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Service;

use OCA\Talk\Model\RecordingSummaryMapper;
use OCA\Talk\Model\RecordingSummaryTemplate;
use OCA\Talk\Model\RecordingSummaryTemplateMapper;
use OCA\Talk\Service\RecordingSummaryTemplateService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class RecordingSummaryTemplateServiceTest extends TestCase {
	private RecordingSummaryTemplateMapper&MockObject $mapper;
	private RecordingSummaryMapper&MockObject $summaryMapper;
	private RecordingSummaryTemplateService $service;
	private \DateTime $now;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(RecordingSummaryTemplateMapper::class);
		$this->summaryMapper = $this->createMock(RecordingSummaryMapper::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$this->now = new \DateTime('2026-08-17T12:00:00+00:00');
		$timeFactory->method('getDateTime')->willReturn($this->now);
		$this->service = new RecordingSummaryTemplateService($this->mapper, $this->summaryMapper, $timeFactory);
	}

	public function testCreateTrimsAndFormatsTemplate(): void {
		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(function (RecordingSummaryTemplate $template): RecordingSummaryTemplate {
			$this->assertSame('owner', $template->getOwnerId());
			$this->assertSame('Weekly recap', $template->getName());
			$this->assertSame('Focus on decisions', $template->getInstructions());
			$template->setId(123);
			return $template;
		});

		$this->assertSame([
			'id' => '123',
			'ownerId' => 'owner',
			'name' => 'Weekly recap',
			'instructions' => 'Focus on decisions',
			'createdAt' => 1786968000,
			'updatedAt' => 1786968000,
		], $this->service->create('owner', ' Weekly recap ', ' Focus on decisions '));
	}

	public function testCreateRejectsEmptyInstructions(): void {
		$this->mapper->expects($this->never())->method('insert');
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('instructions');
		$this->service->create('owner', 'Name', " \n ");
	}

	public function testSnapshotsOwnedTemplate(): void {
		$template = $this->template();
		$this->mapper->expects($this->once())->method('findByIdAndOwner')->with('123', 'owner')->willReturn($template);

		$this->assertSame([
			'id' => '123',
			'name' => 'Name',
			'instructions' => 'Instructions',
		], $this->service->snapshot('123', 'owner'));
	}

	public function testRejectsTemplateFromAnotherOwner(): void {
		$this->mapper->method('findByIdAndOwner')->willThrowException(new DoesNotExistException('missing'));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('summary_template');

		$this->service->snapshot('123', 'other-owner');
	}

	public function testCreateRejectsLongName(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('name');
		$this->service->create('owner', str_repeat('a', RecordingSummaryTemplateService::MAX_NAME_LENGTH + 1), 'Instructions');
	}

	public function testCreateRejectsLongInstructions(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('instructions');
		$this->service->create('owner', 'Name', str_repeat('a', RecordingSummaryTemplateService::MAX_INSTRUCTIONS_LENGTH + 1));
	}

	public function testUpdateUsesOwnerScopedLookup(): void {
		$template = $this->template();
		$this->mapper->expects($this->once())->method('findByIdAndOwner')->with('123', 'owner')->willReturn($template);
		$this->mapper->expects($this->once())->method('update')->with($template)->willReturn($template);

		$result = $this->service->update('123', 'owner', 'New name', 'New instructions');

		$this->assertSame('New name', $result['name']);
		$this->assertSame('New instructions', $result['instructions']);
		$this->assertSame($this->now, $template->getUpdatedAt());
	}

	public function testDeleteUsesOwnerScopedLookup(): void {
		$template = $this->template();
		$this->mapper->expects($this->once())->method('findByIdAndOwner')->with('123', 'owner')->willReturn($template);
		$this->mapper->expects($this->once())->method('delete')->with($template);

		$this->service->delete('123', 'owner');
	}

	private function template(): RecordingSummaryTemplate {
		$template = new RecordingSummaryTemplate();
		$template->setId(123);
		$template->setOwnerId('owner');
		$template->setName('Name');
		$template->setInstructions('Instructions');
		$template->setCreatedAt(new \DateTime('2026-08-16T12:00:00+00:00'));
		$template->setUpdatedAt(new \DateTime('2026-08-16T12:00:00+00:00'));
		return $template;
	}
}
