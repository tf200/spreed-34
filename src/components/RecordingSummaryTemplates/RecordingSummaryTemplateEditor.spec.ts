/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import RecordingSummaryTemplateEditor from './RecordingSummaryTemplateEditor.vue'
import { createTemplate } from './testTemplates.ts'

vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog: vi.fn() }))

const stubs = {
	NcButton: {
		props: ['disabled', 'type', 'variant'],
		emits: ['click'],
		template: '<button :type="type ?? \'button\'" :disabled="disabled" :data-variant="variant" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: {
		props: ['modelValue', 'label'],
		template: '<input :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
	NcTextArea: true,
	NcSelect: true,
	NcRadioGroup: true,
	NcRadioGroupButton: true,
	NcFormBox: true,
	NcFormBoxSwitch: true,
	NcNoteCard: { template: '<p class="note"><slot /></p>' },
}

/**
 * Find a button by its text.
 *
 * @param wrapper Mounted editor
 * @param text Button text
 */
function button(wrapper: ReturnType<typeof mount>, text: string) {
	return wrapper.findAll('button').find((element) => element.text() === text)
}

describe('RecordingSummaryTemplateEditor', () => {
	it('shows built-in templates read-only with duplicating as the main action', () => {
		const wrapper = mount(RecordingSummaryTemplateEditor, { props: { template: createTemplate() }, global: { stubs } })

		expect(wrapper.find('.note').text()).toContain('Duplicate it to make your own version')
		expect(wrapper.find('fieldset').attributes('disabled')).toBeDefined()
		expect(button(wrapper, 'Save')).toBeUndefined()
		expect(button(wrapper, 'Delete')).toBeUndefined()
		expect(button(wrapper, 'Duplicate')?.attributes('data-variant')).toBe('primary')
	})

	it('duplicates with the content of the template', async () => {
		const wrapper = mount(RecordingSummaryTemplateEditor, { props: { template: createTemplate() }, global: { stubs } })

		await button(wrapper, 'Duplicate')!.trigger('click')

		expect(wrapper.emitted('duplicate')?.[0]?.[0]).toMatchObject({
			name: 'General meeting',
			definition: { sections: [{ title: 'Overview', description: 'The purpose.' }, { title: 'Decisions', description: '' }] },
		})
	})

	it('requires a name for a new template', async () => {
		const wrapper = mount(RecordingSummaryTemplateEditor, { props: { template: null }, global: { stubs } })

		expect(button(wrapper, 'Save')!.attributes('disabled')).toBeDefined()
		await wrapper.get('input[aria-label="Name"]').setValue('Weekly')
		expect(button(wrapper, 'Save')!.attributes('disabled')).toBeUndefined()
	})

	it('saves trimmed sections in their new order and leaves out empty ones', async () => {
		const template = createTemplate({ id: '7', source: 'personal', canEdit: true })
		const wrapper = mount(RecordingSummaryTemplateEditor, { props: { template }, global: { stubs } })

		await button(wrapper, 'Add section')!.trigger('click')
		const titles = wrapper.findAll('input[aria-label="Section title"]')
		expect(titles).toHaveLength(3)
		await titles[1]!.setValue('  Decisions taken ')
		await wrapper.get('button[title="Move up"]:not([disabled])').trigger('click')
		await wrapper.get('form').trigger('submit')

		expect(wrapper.emitted('update:dirty')?.at(-1)).toEqual([true])
		expect(wrapper.emitted('save')?.[0]?.[0]).toMatchObject({
			definition: {
				sections: [{ title: 'Decisions taken', description: '' }, { title: 'Overview', description: 'The purpose.' }],
			},
		})
	})

	it('removes a section', async () => {
		const template = createTemplate({ id: '7', source: 'personal', canEdit: true })
		const wrapper = mount(RecordingSummaryTemplateEditor, { props: { template }, global: { stubs } })

		await wrapper.get('button[title="Remove"]').trigger('click')

		expect(wrapper.findAll('input[aria-label="Section title"]').map((input) => (input.element as HTMLInputElement).value)).toEqual(['Decisions'])
	})
})
