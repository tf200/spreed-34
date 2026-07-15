<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\Migration;

use Doctrine\DBAL\Schema\Table;
use OCA\Talk\Migration\Version24000Date20260715100000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use Test\TestCase;

class RecordingAiClaimMigrationTest extends TestCase {
	public function testAddsClaimColumnsIdempotently(): void {
		$table = new Table('oc_talk_recording_ai');
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('talk_recording_ai')->willReturn(true);
		$schema->method('getTable')->with('talk_recording_ai')->willReturn($table);
		$migration = new Version24000Date20260715100000();
		$schemaClosure = fn (): ISchemaWrapper => $schema;

		$this->assertSame($schema, $migration->changeSchema($this->createMock(IOutput::class), $schemaClosure, []));
		$this->assertTrue($table->hasColumn('claim_token'));
		$this->assertTrue($table->hasColumn('claim_until'));
		$this->assertFalse($table->getColumn('claim_token')->getNotnull());
		$this->assertFalse($table->getColumn('claim_until')->getNotnull());

		$this->assertSame($schema, $migration->changeSchema($this->createMock(IOutput::class), $schemaClosure, []));
	}

	public function testDoesNothingBeforeRecordingAiTableExists(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('talk_recording_ai')->willReturn(false);
		$schema->expects($this->never())->method('getTable');
		$migration = new Version24000Date20260715100000();

		$this->assertNull($migration->changeSchema(
			$this->createMock(IOutput::class),
			fn (): ISchemaWrapper => $schema,
			[],
		));
	}
}
