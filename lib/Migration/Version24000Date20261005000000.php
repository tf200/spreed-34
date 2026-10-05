<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Structured summary templates, organization templates and the summary
 * template of each conversation.
 */
class Version24000Date20261005000000 extends SimpleMigrationStep {
	public function __construct(
		private readonly IDBConnection $connection,
	) {
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('talk_recording_templates');
		if (!$table->hasColumn('definition')) {
			$table->addColumn('scope', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'user']);
			$table->addColumn('description', Types::STRING, ['notnull' => false, 'length' => 500]);
			$table->addColumn('definition', Types::TEXT, ['notnull' => false]);
			// Replaced by the definition, which keeps free text instructions
			// as its extra instructions. Dropped in the next migration.
			$table->modifyColumn('instructions', ['notnull' => false]);
			$table->addIndex(['scope'], 'talk_rec_tpl_scope');
		}

		if (!$schema->hasTable('talk_recording_tpl_rooms')) {
			$table = $schema->createTable('talk_recording_tpl_rooms');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('room_token', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('template_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			// The moderator who chose the template, as a personal template is
			// only resolved for its owner.
			$table->addColumn('actor_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['room_token'], 'talk_rec_tpl_room');
		}

		return $schema;
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$select = $this->connection->getQueryBuilder();
		$select->select('id', 'instructions')
			->from('talk_recording_templates')
			->where($select->expr()->isNull('definition'));

		$update = $this->connection->getQueryBuilder();
		$update->update('talk_recording_templates')
			->set('definition', $update->createParameter('definition'))
			->where($update->expr()->eq('id', $update->createParameter('id')));

		$result = $select->executeQuery();
		while ($row = $result->fetch()) {
			$definition = [
				'sections' => [],
				'length' => 'standard',
				'style' => 'bullets',
				'language' => '',
				'actionItemsTable' => false,
				'transcriptLinks' => false,
				'extraInstructions' => (string)$row['instructions'],
			];
			$update->setParameter('definition', json_encode($definition, JSON_THROW_ON_ERROR))
				->setParameter('id', (int)$row['id'], IQueryBuilder::PARAM_INT)
				->executeStatement();
		}
		$result->closeCursor();
	}
}
