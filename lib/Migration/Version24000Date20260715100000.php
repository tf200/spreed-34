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

class Version24000Date20260715100000 extends SimpleMigrationStep {
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('talk_recording_ai')) {
			return null;
		}

		$table = $schema->getTable('talk_recording_ai');
		if (!$table->hasColumn('claim_token')) {
			$table->addColumn('claim_token', Types::STRING, ['notnull' => false, 'length' => 64]);
		}
		if (!$table->hasColumn('claim_until')) {
			$table->addColumn('claim_until', Types::DATETIME, ['notnull' => false]);
		}

		return $schema;
	}
}
