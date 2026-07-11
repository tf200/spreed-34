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
use Override;

class Version24000Date20260711210000 extends SimpleMigrationStep {
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('talk_recording_ai')) {
			return null;
		}

		$table = $schema->createTable('talk_recording_ai');
		$table->addColumn('id', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'length' => 20]);
		$table->addColumn('recording_file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('owner_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('room_token', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 32]);
		$table->addColumn('gcs_object', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('speech_operation', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('speech_response', Types::TEXT, ['notnull' => false]);
		$table->addColumn('transcript', Types::TEXT, ['notnull' => false]);
		$table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
		$table->addColumn('next_attempt_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('deadline_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('last_error_code', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('last_error_message', Types::TEXT, ['notnull' => false]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['recording_file_id'], 'trai_recording');
		$table->addIndex(['state', 'next_attempt_at'], 'trai_state_due');

		return $schema;
	}
}
