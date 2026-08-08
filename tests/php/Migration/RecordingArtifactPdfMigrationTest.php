<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\Migration;

use Doctrine\DBAL\Schema\Table;
use OCA\Talk\Migration\Version24000Date20260805190000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use Test\TestCase;

class RecordingArtifactPdfMigrationTest extends TestCase {
	public function testMakesSourceFileNullable(): void {
		$table = new Table('oc_talk_recording_artifacts');
		$table->addColumn('source_file_id', 'bigint', ['notnull' => true]);
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('talk_recording_artifacts')->willReturn(true);
		$schema->method('getTable')->with('talk_recording_artifacts')->willReturn($table);
		$migration = new Version24000Date20260805190000();
		$schemaClosure = fn (): ISchemaWrapper => $schema;

		$this->assertSame($schema, $migration->changeSchema($this->createMock(IOutput::class), $schemaClosure, []));
		$this->assertFalse($table->getColumn('source_file_id')->getNotnull());
		$this->assertSame($schema, $migration->changeSchema($this->createMock(IOutput::class), $schemaClosure, []));
	}

	public function testDoesNothingBeforeArtifactTableExists(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('talk_recording_artifacts')->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		$this->assertNull((new Version24000Date20260805190000())->changeSchema(
			$this->createMock(IOutput::class),
			fn (): ISchemaWrapper => $schema,
			[],
		));
	}
}
