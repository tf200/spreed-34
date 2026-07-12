/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

let startedAt = null
let events = []
const activeSpeakers = new Map()

/**
 * Start collecting speaker state changes.
 *
 * @param {object[]} participants Current call participant models
 */
export function startSpeakerTimeline(participants = []) {
	startedAt = performance.now()
	events = []
	activeSpeakers.clear()
	for (const participant of participants) {
		recordSpeakerState(participant, participant.attributes.speaking)
	}
}

/**
 * Record a participant's current speaking state.
 *
 * @param {object} participant Call participant model
 * @param {boolean} speaking Whether the participant is speaking
 */
export function recordSpeakerState(participant, speaking) {
	if (startedAt === null) {
		return
	}
	speaking = speaking === true

	const { peerId, nextcloudSessionId, actorType, actorId, userId, name } = participant.attributes
	if (!peerId || activeSpeakers.has(peerId) === speaking) {
		return
	}

	const event = {
		time: Math.max(0, (performance.now() - startedAt) / 1000),
		speaking,
		peerId,
		sessionId: nextcloudSessionId,
		actorType,
		actorId,
		userId,
		displayName: name || userId || actorId || null,
	}
	events.push(event)
	if (speaking) {
		activeSpeakers.set(peerId, event)
	} else {
		activeSpeakers.delete(peerId)
	}
}

/**
 * Stop collecting and return the completed timeline.
 *
 * @param {object} timing Recorder-to-browser clock alignment
 * @return {object|null} Versioned speaker timeline
 */
export function stopSpeakerTimeline(timing = {}) {
	if (startedAt === null) {
		return null
	}

	const duration = Math.max(0, (performance.now() - startedAt) / 1000)
	for (const event of activeSpeakers.values()) {
		events.push({ ...event, time: duration, speaking: false })
	}

	const timeline = {
		version: 1,
		duration,
		events,
	}
	if (Number.isFinite(timing.recordingOffset) && timing.recordingOffset >= 0) {
		timeline.recordingOffset = timing.recordingOffset
	}
	if (Number.isFinite(timing.clockUncertainty) && timing.clockUncertainty >= 0) {
		timeline.clockUncertainty = timing.clockUncertainty
	}
	startedAt = null
	events = []
	activeSpeakers.clear()
	return timeline
}
