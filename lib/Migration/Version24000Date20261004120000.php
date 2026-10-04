<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Transcription state of each speech chunk of the participant tracks.
 */
class Version24000Date20261004120000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('talk_recording_ai_chunks')) {
			return null;
		}

		$table = $schema->createTable('talk_recording_ai_chunks');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('operation_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('chunk_id', Types::STRING, ['notnull' => true, 'length' => 32]);
		$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
		$table->addColumn('words', Types::TEXT, ['notnull' => false]);
		$table->addColumn('last_error_message', Types::TEXT, ['notnull' => false]);
		$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['operation_id', 'chunk_id'], 'trac_operation_chunk');

		return $schema;
	}
}
