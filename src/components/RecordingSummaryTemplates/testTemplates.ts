/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { RecordingSummaryTemplate } from '../../services/recordingSummaryTemplateService.ts'

/**
 * A template for tests.
 *
 * @param overrides Fields to change
 */
export function createTemplate(overrides: Partial<RecordingSummaryTemplate> = {}): RecordingSummaryTemplate {
	return {
		id: 'builtin-general',
		source: 'builtin',
		name: 'General meeting',
		description: 'Overview, decisions, action items and open questions',
		definition: {
			sections: [
				{ title: 'Overview', description: 'The purpose.' },
				{ title: 'Decisions', description: '' },
			],
			length: 'standard',
			style: 'bullets',
			language: '',
			actionItemsTable: false,
			transcriptLinks: false,
			extraInstructions: '',
		},
		canEdit: false,
		isDefault: true,
		updatedAt: 0,
		...overrides,
	}
}
