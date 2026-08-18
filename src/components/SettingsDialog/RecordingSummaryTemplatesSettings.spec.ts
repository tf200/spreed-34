/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, test, vi } from 'vitest'
import { nextTick } from 'vue'
import RecordingSummaryTemplatesSettings from './RecordingSummaryTemplatesSettings.vue'
import { getRecordingSummaryTemplates } from '../../services/recordingSummaryTemplateService.ts'

vi.mock('../../services/recordingSummaryTemplateService.ts', () => ({
	createRecordingSummaryTemplate: vi.fn(),
	deleteRecordingSummaryTemplate: vi.fn(),
	getRecordingSummaryTemplates: vi.fn(),
	updateRecordingSummaryTemplate: vi.fn(),
}))

describe('RecordingSummaryTemplatesSettings', () => {
	beforeEach(() => {
		vi.mocked(getRecordingSummaryTemplates).mockResolvedValue({
			data: { ocs: { data: [] } },
		} as never)
	})

	test('opens the form when adding a template', async () => {
		const wrapper = mount(RecordingSummaryTemplatesSettings)
		await vi.waitFor(() => expect(getRecordingSummaryTemplates).toHaveBeenCalled())
		await nextTick()

		await wrapper.get('button').trigger('click')

		expect(wrapper.find('form').exists()).toBe(true)
	})
})
