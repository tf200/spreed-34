<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Service;

use OCA\Talk\Model\RecordingRoomTemplate;
use OCA\Talk\Model\RecordingRoomTemplateMapper;
use OCA\Talk\Model\RecordingSummaryMapper;
use OCA\Talk\Model\RecordingSummaryTemplate;
use OCA\Talk\Model\RecordingSummaryTemplateMapper;
use OCA\Talk\Recording\BuiltInSummaryTemplates;
use OCA\Talk\Service\RecordingSummaryTemplateService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class RecordingSummaryTemplateServiceTest extends TestCase {
	private const DEFINITION = [
		'sections' => [['title' => 'Decisions', 'description' => 'What was decided.']],
		'length' => 'brief',
		'style' => 'bullets',
		'language' => 'nl',
		'actionItemsTable' => true,
		'transcriptLinks' => false,
		'extraInstructions' => '',
	];

	private RecordingSummaryTemplateMapper&MockObject $mapper;
	private RecordingSummaryMapper&MockObject $summaryMapper;
	private RecordingRoomTemplateMapper&MockObject $roomTemplateMapper;
	private IUserConfig&MockObject $userConfig;
	/** @var array<string, string> */
	private array $userDefaults = [];
	private RecordingSummaryTemplateService $service;
	private \DateTime $now;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(RecordingSummaryTemplateMapper::class);
		$this->summaryMapper = $this->createMock(RecordingSummaryMapper::class);
		$this->roomTemplateMapper = $this->createMock(RecordingRoomTemplateMapper::class);
		$this->userConfig = $this->createMock(IUserConfig::class);
		$this->userConfig->method('getValueString')->willReturnCallback(
			fn (string $userId, string $app, string $key, string $default): string => $this->userDefaults[$userId] ?? $default,
		);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$this->now = new \DateTime('2026-08-17T12:00:00+00:00');
		$timeFactory->method('getDateTime')->willReturn($this->now);
		$this->service = new RecordingSummaryTemplateService(
			$this->mapper,
			$this->summaryMapper,
			$this->roomTemplateMapper,
			new BuiltInSummaryTemplates($l),
			$this->userConfig,
			$timeFactory,
		);
	}

	public function testListsPersonalThenOrganizationThenBuiltInTemplates(): void {
		$this->userDefaults['owner'] = '123';
		$this->mapper->method('findPersonal')->with('owner')->willReturn([$this->template()]);
		$this->mapper->method('findOrganization')->willReturn([$this->template(124, 'admin', RecordingSummaryTemplate::SCOPE_ORGANIZATION)]);
		$this->mapper->method('findById')->with('123')->willReturn($this->template());

		$templates = $this->service->list('owner', false);

		$this->assertSame(['personal', 'organization', 'builtin'], array_values(array_unique(array_column($templates, 'source'))));
		$this->assertSame(['123'], array_column(array_filter($templates, static fn (array $template): bool => $template['isDefault']), 'id'));
		$this->assertSame([true, false], array_column(array_slice($templates, 0, 2), 'canEdit'));
		$this->assertSame(self::DEFINITION, $templates[0]['definition']);
	}

	public function testBuiltInDefaultIsTheDefaultWithoutAChoice(): void {
		$this->mapper->method('findPersonal')->willReturn([]);
		$this->mapper->method('findOrganization')->willReturn([]);

		$defaults = array_filter($this->service->list('owner', false), static fn (array $template): bool => $template['isDefault']);

		$this->assertSame([BuiltInSummaryTemplates::DEFAULT_ID], array_column($defaults, 'id'));
	}

	public function testCreateStoresNormalizedDefinition(): void {
		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(function (RecordingSummaryTemplate $template): RecordingSummaryTemplate {
			$this->assertSame('owner', $template->getOwnerId());
			$this->assertSame(RecordingSummaryTemplate::SCOPE_USER, $template->getScope());
			$this->assertSame('Weekly recap', $template->getName());
			$this->assertSame('Short', $template->getDescription());
			$this->assertSame(self::DEFINITION, json_decode((string)$template->getDefinition(), true));
			$template->setId(123);
			return $template;
		});

		$definition = self::DEFINITION;
		$definition['sections'][0]['title'] = ' Decisions ';
		$result = $this->service->create('owner', false, ' Weekly recap ', ' Short ', $definition, false);

		$this->assertSame('123', $result['id']);
		$this->assertSame('personal', $result['source']);
		$this->assertSame($this->now->getTimestamp(), $result['updatedAt']);
	}

	public function testOnlyAdministratorsCreateOrganizationTemplates(): void {
		$this->mapper->expects($this->never())->method('insert');
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('organization');

		$this->service->create('owner', false, 'Board', '', self::DEFINITION, true);
	}

	public function testAdministratorCreatesOrganizationTemplate(): void {
		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(function (RecordingSummaryTemplate $template): RecordingSummaryTemplate {
			$this->assertSame(RecordingSummaryTemplate::SCOPE_ORGANIZATION, $template->getScope());
			$template->setId(125);
			return $template;
		});

		$result = $this->service->create('admin', true, 'Board', '', self::DEFINITION, true);

		$this->assertSame('organization', $result['source']);
		$this->assertTrue($result['canEdit']);
	}

	public function testCreateRejectsTemplateWithoutContent(): void {
		$this->mapper->expects($this->never())->method('insert');
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('sections');

		$this->service->create('owner', false, 'Name', '', ['sections' => [], 'extraInstructions' => ' '], false);
	}

	public function testCreateRejectsLongName(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('name');
		$this->service->create('owner', false, str_repeat('a', RecordingSummaryTemplateService::MAX_NAME_LENGTH + 1), '', self::DEFINITION, false);
	}

	public static function dataNotEditable(): array {
		return [
			'personal template of another user' => [RecordingSummaryTemplate::SCOPE_USER, 'other', true],
			'organization template without being admin' => [RecordingSummaryTemplate::SCOPE_ORGANIZATION, 'owner', false],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('dataNotEditable')]
	public function testUpdateRejectsTemplateThatCanNotBeEdited(string $scope, string $ownerId, bool $isAdmin): void {
		$this->mapper->method('findById')->with('123')->willReturn($this->template(123, $ownerId, $scope));
		$this->mapper->expects($this->never())->method('update');
		$this->expectException(DoesNotExistException::class);

		$this->service->update('123', 'owner', $isAdmin, 'Name', '', self::DEFINITION);
	}

	public function testDeleteAlsoForgetsConversationsUsingTheTemplate(): void {
		$template = $this->template();
		$this->mapper->method('findById')->with('123')->willReturn($template);
		$this->mapper->expects($this->once())->method('delete')->with($template);
		$this->roomTemplateMapper->expects($this->once())->method('deleteByTemplateId')->with('123');

		$this->service->delete('123', 'owner', false);
	}

	public function testSnapshotUsesTemplateOfTheConversationFirst(): void {
		$this->userDefaults['moderator'] = BuiltInSummaryTemplates::PREFIX . 'standup';
		$roomTemplate = new RecordingRoomTemplate();
		$roomTemplate->setTemplateId('123');
		$roomTemplate->setActorId('owner');
		$this->roomTemplateMapper->method('findByRoomToken')->with('room')->willReturn($roomTemplate);
		// A personal template chosen for the conversation by its owner.
		$this->mapper->method('findById')->with('123')->willReturn($this->template());

		$snapshot = $this->service->snapshot(null, 'moderator', 'room');

		$this->assertSame('123', $snapshot['id']);
		$this->assertSame('Name', $snapshot['name']);
		$this->assertStringContainsString('1. Decisions: What was decided.', $snapshot['instructions']);
		$this->assertStringContainsString('Write the summary in Dutch', $snapshot['instructions']);
	}

	public function testSnapshotUsesDefaultOfTheUserWithoutConversationTemplate(): void {
		$this->userDefaults['moderator'] = BuiltInSummaryTemplates::PREFIX . 'standup';
		$this->roomTemplateMapper->method('findByRoomToken')->willThrowException(new DoesNotExistException(''));

		$this->assertSame(BuiltInSummaryTemplates::PREFIX . 'standup', $this->service->snapshot(null, 'moderator', 'room')['id']);
	}

	public function testSnapshotFallsBackToBuiltInDefaultWhenDefaultWasDeleted(): void {
		$this->userDefaults['moderator'] = '123';
		$this->mapper->method('findById')->willThrowException(new DoesNotExistException(''));

		$this->assertSame(BuiltInSummaryTemplates::DEFAULT_ID, $this->service->snapshot(null, 'moderator')['id']);
	}

	public function testSnapshotRejectsPersonalTemplateOfAnotherUser(): void {
		$this->mapper->method('findById')->with('123')->willReturn($this->template(123, 'other'));
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('summary_template');

		$this->service->snapshot('123', 'owner');
	}

	public function testSettingBuiltInDefaultForgetsTheChoice(): void {
		$this->userConfig->expects($this->once())->method('deleteUserConfig')->with('owner', 'spreed', RecordingSummaryTemplateService::USER_DEFAULT_KEY);
		$this->userConfig->expects($this->never())->method('setValueString');

		$this->service->setUserDefault('owner', BuiltInSummaryTemplates::DEFAULT_ID);
	}

	public function testSetConversationTemplateRequiresUsableTemplate(): void {
		$this->mapper->method('findById')->with('123')->willReturn($this->template(123, 'other'));
		$this->roomTemplateMapper->expects($this->never())->method('insert');
		$this->expectException(\InvalidArgumentException::class);

		$this->service->setRoomTemplate('room', '123', 'owner');
	}

	public function testSetConversationTemplateRemembersWhoChoseIt(): void {
		$this->roomTemplateMapper->method('findByRoomToken')->willThrowException(new DoesNotExistException(''));
		$this->roomTemplateMapper->expects($this->once())->method('insert')->willReturnCallback(function (RecordingRoomTemplate $roomTemplate): RecordingRoomTemplate {
			$this->assertSame('room', $roomTemplate->getRoomToken());
			$this->assertSame(BuiltInSummaryTemplates::PREFIX . 'standup', $roomTemplate->getTemplateId());
			$this->assertSame('owner', $roomTemplate->getActorId());
			return $roomTemplate;
		});

		$this->service->setRoomTemplate('room', BuiltInSummaryTemplates::PREFIX . 'standup', 'owner');
	}

	private function template(int $id = 123, string $ownerId = 'owner', string $scope = RecordingSummaryTemplate::SCOPE_USER): RecordingSummaryTemplate {
		$template = new RecordingSummaryTemplate();
		$template->setId($id);
		$template->setOwnerId($ownerId);
		$template->setScope($scope);
		$template->setName('Name');
		$template->setDescription('');
		$template->setDefinition(json_encode(self::DEFINITION));
		$template->setCreatedAt(new \DateTime('2026-08-16T12:00:00+00:00'));
		$template->setUpdatedAt(new \DateTime('2026-08-16T12:00:00+00:00'));
		return $template;
	}
}
