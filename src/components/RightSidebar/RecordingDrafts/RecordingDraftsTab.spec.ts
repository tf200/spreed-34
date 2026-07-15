/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import RecordingDraftsTab from './RecordingDraftsTab.vue'
import { getRecordingArtifacts } from '../../../services/recordingArtifactService.ts'

const { push, route } = vi.hoisted(() => ({
	push: vi.fn(),
	route: { query: { sidebar: 'recording', unrelated: 'keep-me' } },
}))

vi.mock('vue-router', () => ({
	useRoute: () => route,
	useRouter: () => ({ push }),
}))
vi.mock('../../../services/recordingArtifactService.ts', () => ({ getRecordingArtifacts: vi.fn() }))

const artifact = {
	id: 'artifact-1',
	type: 'summary',
	state: 'draft',
	fileName: 'summary.md',
	updatedAt: 1000,
	notificationTimestamp: 2000,
} as const

const stubs = {
	NcButton: { inheritAttrs: false, template: '<button @click="$emit(\'click\')"><slot name="icon" /><slot /></button>' },
	NcEmptyContent: { props: ['name'], template: '<div class="empty">{{ name }}<slot name="action" /><slot name="icon" /></div>' },
	NcLoadingIcon: { template: '<span class="loading" />' },
	IconFileDocumentEditOutline: { template: '<span />' },
}

function response(data: unknown[]) {
	return { data: { ocs: { data } } }
}

async function mountTab(active: boolean) {
	const wrapper = mount(RecordingDraftsTab, {
		props: { token: 'room-token', active },
		global: { stubs },
	})
	await flushPromises()
	return wrapper
}

describe('RecordingDraftsTab', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		vi.spyOn(console, 'error').mockImplementation(() => {})
		vi.mocked(getRecordingArtifacts).mockResolvedValue(response([artifact]) as never)
	})

	afterEach(() => vi.restoreAllMocks())

	it('fetches only when the tab becomes active', async () => {
		const wrapper = await mountTab(false)
		expect(getRecordingArtifacts).not.toHaveBeenCalled()

		await wrapper.setProps({ active: true })
		await flushPromises()

		expect(getRecordingArtifacts).toHaveBeenCalledOnce()
		expect(getRecordingArtifacts).toHaveBeenCalledWith('room-token')
	})

	it('renders the empty state', async () => {
		vi.mocked(getRecordingArtifacts).mockResolvedValue(response([]) as never)
		const wrapper = await mountTab(true)

		expect(wrapper.get('.empty').text()).toContain('No recording drafts')
	})

	it('renders an error and retries', async () => {
		vi.mocked(getRecordingArtifacts)
			.mockRejectedValueOnce(new Error('network error'))
			.mockResolvedValueOnce(response([]) as never)
		const wrapper = await mountTab(true)

		expect(wrapper.get('.empty').text()).toContain('Could not load recording drafts')
		await wrapper.get('button').trigger('click')
		await flushPromises()

		expect(getRecordingArtifacts).toHaveBeenCalledTimes(2)
		expect(wrapper.get('.empty').text()).toContain('No recording drafts')
	})

	it('preserves unrelated query parameters when opening a draft', async () => {
		const wrapper = await mountTab(true)
		await wrapper.get('button').trigger('click')

		expect(push).toHaveBeenCalledWith({
			query: {
				sidebar: 'recording',
				unrelated: 'keep-me',
				reviewArtifact: 'artifact-1',
				notificationTimestamp: '2000',
			},
		})
	})
})
