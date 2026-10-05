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
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Removes the free text instructions of summary templates, which are kept as
 * the extra instructions of their definition since
 * {@see Version24000Date20261005000000}.
 */
class Version24000Date20261005000001 extends SimpleMigrationStep {
	public function __construct(
		private readonly IDBConnection $connection,
	) {
	}

	#[\Override]
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->getTable('talk_recording_templates')->hasColumn('instructions')) {
			return;
		}

		// Templates created between the two migrations.
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

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('talk_recording_templates');
		if (!$table->hasColumn('instructions')) {
			return null;
		}
		$table->dropColumn('instructions');
		return $schema;
	}
}
