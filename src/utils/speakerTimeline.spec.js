/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, test, vi } from 'vitest'
import { recordSpeakerState, startSpeakerTimeline, stopSpeakerTimeline } from './speakerTimeline.js'

describe('speakerTimeline', () => {
	beforeEach(() => {
		vi.spyOn(performance, 'now')
		stopSpeakerTimeline()
	})

	test('records identity and speaking state relative to start', () => {
		performance.now.mockReturnValueOnce(1000)
		startSpeakerTimeline()
		performance.now.mockReturnValueOnce(2500)
		recordSpeakerState({ attributes: {
			peerId: 'peer-1',
			nextcloudSessionId: 'session-1',
			actorType: 'users',
			actorId: 'alice',
			userId: 'alice',
			name: 'Alice',
		} }, true)
		performance.now.mockReturnValueOnce(4000)

		expect(stopSpeakerTimeline()).toEqual({
			version: 1,
			duration: 3,
			events: [{
				time: 1.5,
				speaking: true,
				peerId: 'peer-1',
				sessionId: 'session-1',
				actorType: 'users',
				actorId: 'alice',
				userId: 'alice',
				displayName: 'Alice',
			}, {
				time: 3,
				speaking: false,
				peerId: 'peer-1',
				sessionId: 'session-1',
				actorType: 'users',
				actorId: 'alice',
				userId: 'alice',
				displayName: 'Alice',
			}],
		})
	})

	test('deduplicates state and closes active speakers when stopped', () => {
		performance.now.mockReturnValueOnce(1000)
		startSpeakerTimeline()
		const participant = { attributes: { peerId: 'peer-1', name: 'Alice' } }
		performance.now.mockReturnValueOnce(1500)
		recordSpeakerState(participant, true)
		recordSpeakerState(participant, true)
		performance.now.mockReturnValueOnce(2000)

		expect(stopSpeakerTimeline().events).toEqual([
			expect.objectContaining({ time: 0.5, speaking: true }),
			expect.objectContaining({ time: 1, speaking: false }),
		])
	})

	test('ignores events when collection has not started', () => {
		recordSpeakerState({ attributes: {} }, true)
		expect(stopSpeakerTimeline()).toBeNull()
	})
})
