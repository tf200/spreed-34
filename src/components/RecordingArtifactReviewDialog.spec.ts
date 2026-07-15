/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { RecordingArtifact } from '../services/recordingArtifactService.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import RecordingArtifactReviewDialog from './RecordingArtifactReviewDialog.vue'
import {
	getRecordingArtifact,
	publishRecordingArtifact,
	updateRecordingArtifact,
} from '../services/recordingArtifactService.ts'

const { spawnDialog } = vi.hoisted(() => ({ spawnDialog: vi.fn() }))

vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../services/recordingArtifactService.ts', () => ({
	getRecordingArtifact: vi.fn(),
	publishRecordingArtifact: vi.fn(),
	updateRecordingArtifact: vi.fn(),
}))

const draft: RecordingArtifact = {
	id: 'artifact-1',
	type: 'summary',
	state: 'draft',
	fileName: 'summary.md',
	content: 'Original text',
	etag: 'etag-1',
	publishedFileId: null,
	publishedMessageId: null,
}

const stubs = {
	NcDialog: {
		template: '<div><slot /><div class="actions"><slot name="actions" /></div></div>',
	},
	NcButton: {
		inheritAttrs: false,
		props: ['disabled'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcLoadingIcon: { template: '<span class="loading" />' },
	NcRichText: { props: ['text'], template: '<div class="rich-text">{{ text }}</div>' },
	NcTextArea: {
		props: ['modelValue', 'disabled'],
		template: '<textarea :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
}

function response(artifact: RecordingArtifact = draft) {
	return { data: { ocs: { data: artifact } } }
}

async function mountDialog() {
	const wrapper = mount(RecordingArtifactReviewDialog, {
		props: { token: 'room-token', artifactId: 'artifact-1', notificationTimestamp: 1234 },
		global: { stubs },
	})
	await flushPromises()
	return wrapper
}

function button(wrapper: ReturnType<typeof mount>, text: string) {
	return wrapper.findAll('button').find((item) => item.text().includes(text))!
}

describe('RecordingArtifactReviewDialog', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		vi.spyOn(console, 'error').mockImplementation(() => {})
		vi.mocked(getRecordingArtifact).mockResolvedValue(response() as never)
	})

	afterEach(() => vi.restoreAllMocks())

	it('loads and renders an artifact', async () => {
		const wrapper = await mountDialog()

		expect(getRecordingArtifact).toHaveBeenCalledWith('room-token', 'artifact-1')
		expect(wrapper.get('textarea').element.value).toBe('Original text')
		expect(wrapper.get('.rich-text').text()).toBe('Original text')
	})

	it('renders a load error and retries', async () => {
		vi.mocked(getRecordingArtifact)
			.mockRejectedValueOnce(new Error('network error'))
			.mockResolvedValueOnce(response() as never)
		const wrapper = await mountDialog()

		expect(wrapper.get('[role="alert"]').text()).toContain('Could not load the recording text')
		await button(wrapper, 'Retry').trigger('click')
		await flushPromises()

		expect(getRecordingArtifact).toHaveBeenCalledTimes(2)
		expect(wrapper.get('textarea').element.value).toBe('Original text')
	})

	it('saves dirty content before publishing with the returned etag', async () => {
		const saved = { ...draft, content: 'Corrected text', etag: 'etag-2' }
		const published = { ...saved, state: 'published' as const }
		vi.mocked(updateRecordingArtifact).mockResolvedValue(response(saved) as never)
		vi.mocked(publishRecordingArtifact).mockResolvedValue(response(published) as never)
		spawnDialog.mockResolvedValue(true)
		const wrapper = await mountDialog()

		await wrapper.get('textarea').setValue('Corrected text')
		await button(wrapper, 'Publish to chat').trigger('click')
		await flushPromises()

		expect(updateRecordingArtifact).toHaveBeenCalledWith('room-token', 'artifact-1', 'Corrected text', 'etag-1')
		expect(publishRecordingArtifact).toHaveBeenCalledWith('room-token', 'artifact-1', 'etag-2', 1234)
		expect(vi.mocked(updateRecordingArtifact).mock.invocationCallOrder[0])
			.toBeLessThan(vi.mocked(publishRecordingArtifact).mock.invocationCallOrder[0])
	})

	it('shows the structured 409 state and blocks publishing', async () => {
		vi.mocked(updateRecordingArtifact).mockRejectedValue({
			isAxiosError: true,
			response: { status: 409, data: { ocs: { data: { error: 'editing' } } } },
		})
		const wrapper = await mountDialog()

		await wrapper.get('textarea').setValue('Corrected text')
		await button(wrapper, 'Save draft').trigger('click')
		await flushPromises()

		expect(wrapper.get('[role="status"]').text()).toContain('currently being edited')
		expect(button(wrapper, 'Publish to chat').attributes('disabled')).toBeDefined()
	})

	it('renders published artifacts read-only without draft actions', async () => {
		vi.mocked(getRecordingArtifact).mockResolvedValue(response({ ...draft, state: 'published' }) as never)
		const wrapper = await mountDialog()

		expect(wrapper.get('[role="status"]').text()).toBe('Published')
		expect(wrapper.get('textarea').attributes('disabled')).toBeDefined()
		expect(button(wrapper, 'Publish to chat')).toBeUndefined()
	})
})
