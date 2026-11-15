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
 * Whether a recording AI operation transcribes the mixed recording although
 * participant tracks are kept, for example after the tracks failed.
 */
class Version24000Date20261115120000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('talk_recording_ai');
		if ($table->hasColumn('skip_tracks')) {
			return null;
		}

		$table->addColumn('skip_tracks', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		return $schema;
	}
}
