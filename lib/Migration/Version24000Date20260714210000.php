<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Migration;

use Closure;
use OCA\Talk\Model\RecordingArtifact;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version24000Date20260714210000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('talk_recording_artifacts')) {
			return null;
		}

		$table = $schema->createTable('talk_recording_artifacts');
		$table->addColumn('id', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'length' => 20]);
		$table->addColumn('recording_file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('source_file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('published_file_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		$table->addColumn('published_message_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		$table->addColumn('published_share_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		$table->addColumn('owner_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('room_token', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => RecordingArtifact::STATE_DRAFT]);
		$table->addColumn('source_etag', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('claim_token', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('notification_timestamp', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['source_file_id'], 'tra_source');
		$table->addUniqueIndex(['recording_file_id', 'type'], 'tra_recording_type');
		$table->addIndex(['owner_id', 'room_token', 'state'], 'tra_owner_room');

		return $schema;
	}
}
