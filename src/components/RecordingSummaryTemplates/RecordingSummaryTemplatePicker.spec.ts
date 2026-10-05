/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RecordingSummaryTemplatePicker from './RecordingSummaryTemplatePicker.vue'
import { getRecordingSummaryTemplates } from '../../services/recordingSummaryTemplateService.ts'
import { createTemplate } from './testTemplates.ts'

vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog: vi.fn() }))
vi.mock('../../services/recordingSummaryTemplateService.ts', () => ({ getRecordingSummaryTemplates: vi.fn() }))

const stubs = {
	NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template: '<button class="action" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcLoadingIcon: true,
	NcTextField: true,
	NcEmptyContent: true,
	NcCheckboxRadioSwitch: {
		props: ['modelValue'],
		template: '<label><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>',
	},
}

describe('RecordingSummaryTemplatePicker', () => {
	beforeEach(() => {
		vi.mocked(getRecordingSummaryTemplates).mockResolvedValue({
			data: {
				ocs: {
					data: [
						createTemplate({ id: '7', source: 'personal', name: 'Weekly', isDefault: false, description: '' }),
						createTemplate(),
					],
				},
			},
		} as never)
	})

	it('groups the templates and selects the default first', async () => {
		const wrapper = mount(RecordingSummaryTemplatePicker, { global: { stubs } })
		await flushPromises()

		expect(wrapper.findAll('h3').map((heading) => heading.text())).toEqual(['Personal', 'Built-in'])
		expect(wrapper.get('[aria-checked="true"]').text()).toContain('General meeting')
		// Without a description the sections tell what the template produces.
		expect(wrapper.text()).toContain('Overview · Decisions')
	})

	it('returns the chosen template and whether to remember it', async () => {
		const wrapper = mount(RecordingSummaryTemplatePicker, {
			props: { canRememberForConversation: true, confirmLabel: 'Use template' },
			global: { stubs },
		})
		await flushPromises()

		await wrapper.findAll('[role="radio"]')[0]!.trigger('click')
		await wrapper.get('input[type="checkbox"]').setValue(true)
		await wrapper.findAll('button.action').find((button) => button.text() === 'Use template')!.trigger('click')

		expect(wrapper.emitted('close')?.[0]).toEqual([{ templateId: '7', rememberForConversation: true }])
	})

	it('only offers remembering the template in a conversation', async () => {
		const wrapper = mount(RecordingSummaryTemplatePicker, { global: { stubs } })
		await flushPromises()

		expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false)
	})
})
