/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, test, vi } from 'vitest'
import { useStore } from 'vuex'
import { CALL } from '../../constants.ts'
import { getTalkConfig } from '../../services/CapabilitiesManager.ts'
import { useActorStore } from '../../stores/actor.ts'
import { useJoinCall } from '../useJoinCall.ts'

const { spawnDialog } = vi.hoisted(() => ({ spawnDialog: vi.fn() }))

vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog }))
vi.mock('vuex')
vi.mock('../../services/CapabilitiesManager.ts', () => ({
	getTalkConfig: vi.fn(),
	hasServerAppCapabilities: vi.fn(() => false),
	hasTalkFeature: vi.fn(() => false),
}))

describe('useJoinCall', () => {
	const token = 'XXTOKENXX'
	const dispatch = vi.fn()

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.clearAllMocks()

		const actorStore = useActorStore()
		actorStore.sessionId = 'session-id'
		actorStore.attendeeId = 123

		vi.mocked(useStore).mockReturnValue({
			dispatch,
			getters: {
				conversation: () => ({
					attendeeId: 123,
					objectId: '',
					objectType: '',
					permissions: 0,
				}),
			},
		} as unknown as ReturnType<typeof useStore>)
		vi.mocked(getTalkConfig).mockReturnValue(true as never)
	})

	test('starts recording with the selected summary template', async () => {
		spawnDialog.mockResolvedValue('42')

		await useJoinCall().joinCall(token, { shouldStartRecording: true })

		expect(dispatch).toHaveBeenCalledWith('startCallRecording', {
			token,
			callRecording: CALL.RECORDING.VIDEO,
			summaryTemplateId: '42',
		})
	})

	test('starts recording with null for the built-in default template', async () => {
		spawnDialog.mockResolvedValue(null)

		await useJoinCall().joinCall(token, { shouldStartRecording: true })

		expect(dispatch).toHaveBeenCalledWith('startCallRecording', {
			token,
			callRecording: CALL.RECORDING.VIDEO,
			summaryTemplateId: null,
		})
	})

	test('joins without starting recording when template selection is cancelled', async () => {
		spawnDialog.mockResolvedValue(undefined)

		await useJoinCall().joinCall(token, { shouldStartRecording: true })

		expect(dispatch).toHaveBeenCalledWith('joinCall', expect.objectContaining({ token }))
		expect(dispatch).not.toHaveBeenCalledWith('startCallRecording', expect.anything())
	})
})
