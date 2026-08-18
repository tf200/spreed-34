/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RecordingSummaryTemplateDialog from './RecordingSummaryTemplateDialog.vue'
import { getRecordingSummaryTemplates } from '../services/recordingSummaryTemplateService.ts'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../services/recordingSummaryTemplateService.ts', () => ({ getRecordingSummaryTemplates: vi.fn() }))

const stubs = {
	NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	NcButton: { template: '<button @click="$emit(\'click\')"><slot /></button>' },
	NcLoadingIcon: { template: '<span />' },
	NcCheckboxRadioSwitch: {
		props: ['modelValue', 'value'],
		template: '<label><input type="radio" :checked="modelValue === value" @change="$emit(\'update:modelValue\', value)"><slot /></label>',
	},
}

describe('RecordingSummaryTemplateDialog', () => {
	beforeEach(() => {
		vi.mocked(getRecordingSummaryTemplates).mockResolvedValue({
			data: { ocs: { data: [{ id: 'weekly', name: 'Weekly update', instructions: 'Summarize progress' }] } },
		} as never)
	})

	it('offers the built-in default and starts without a template ID', async () => {
		const wrapper = mount(RecordingSummaryTemplateDialog, { global: { stubs } })
		await flushPromises()

		expect(wrapper.text()).toContain('Default summary')
		expect(wrapper.text()).toContain('Weekly update')
		await wrapper.get('button').trigger('click')
		expect(wrapper.emitted('close')?.[0]).toEqual([null])
	})

	it('returns the selected private template ID', async () => {
		const wrapper = mount(RecordingSummaryTemplateDialog, { global: { stubs } })
		await flushPromises()
		await wrapper.findAll('input')[1].trigger('change')
		await wrapper.get('button').trigger('click')

		expect(wrapper.emitted('close')?.[0]).toEqual(['weekly'])
	})
})
