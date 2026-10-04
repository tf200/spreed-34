/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest'
import EmitterMixin from '../EmitterMixin.js'
import ParticipantTrackRecorder from './participantTrackRecorder.js'

/**
 * Stub of CallParticipantModel with the attributes used by the recorder.
 *
 * @param {string} peerId the ID of the peer
 * @param {object} attributes extra attributes
 */
function CallParticipantModelStub(peerId, attributes = {}) {
	this._superEmitterMixin()

	this.attributes = {
		peerId,
		stream: null,
		...attributes,
	}

	this.get = (key) => this.attributes[key]

	this.set = (key, value) => {
		this.attributes[key] = value
		this._trigger('change:' + key, [value])
	}
}
EmitterMixin.apply(CallParticipantModelStub.prototype)

/**
 * Stub of CallParticipantCollection.
 */
function CallParticipantCollectionStub() {
	this._superEmitterMixin()

	this.callParticipantModels = []
}
EmitterMixin.apply(CallParticipantCollectionStub.prototype)

/**
 * Mock of MediaStream.
 *
 * @param {string} id the id of the stream
 * @param {number} audioTracks the number of audio tracks
 */
function MediaStreamMock(id, audioTracks = 1) {
	const target = new EventTarget()
	this.id = id
	this.audioTracks = Array.from({ length: audioTracks }, (_, index) => ({ id: id + '-audio-' + index }))
	this.getAudioTracks = () => this.audioTracks
	this.addEventListener = target.addEventListener.bind(target)
	this.removeEventListener = target.removeEventListener.bind(target)
	this.addTrack = () => {
		this.audioTracks.push({ id: id + '-audio-' + this.audioTracks.length })
		target.dispatchEvent(new Event('addtrack'))
	}
}

/**
 * Mock of MediaRecorder that emits events synchronously.
 *
 * @param {object} stream the recorded stream
 * @param {object} options the recorder options
 */
function MediaRecorderMock(stream, options) {
	const target = new EventTarget()
	this.stream = stream
	this.options = options
	this.state = 'inactive'
	this.addEventListener = target.addEventListener.bind(target)
	this.start = vi.fn((timeslice) => {
		this.timeslice = timeslice
		this.state = 'recording'
		target.dispatchEvent(new Event('start'))
	})
	this.emitData = (data) => {
		const event = new Event('dataavailable')
		event.data = { size: data.length, data }
		target.dispatchEvent(event)
	}
	this.stop = vi.fn(() => {
		this.state = 'inactive'
		this.emitData('last-' + stream.id)
		target.dispatchEvent(new Event('stop'))
	})
}

describe('ParticipantTrackRecorder', () => {
	let callParticipantCollection
	let recorder
	let now
	let audioContext
	let mediaRecorders

	/**
	 * @param {object} callParticipantModel the model to add
	 */
	function addCallParticipantModel(callParticipantModel) {
		callParticipantCollection.callParticipantModels.push(callParticipantModel)
		callParticipantCollection._trigger('add', [callParticipantModel])
	}

	/**
	 * @param {object} callParticipantModel the model to remove
	 */
	function removeCallParticipantModel(callParticipantModel) {
		const index = callParticipantCollection.callParticipantModels.indexOf(callParticipantModel)
		callParticipantCollection.callParticipantModels.splice(index, 1)
		callParticipantCollection._trigger('remove', [callParticipantModel])
	}

	beforeEach(() => {
		vi.useFakeTimers()
		now = 1000
		mediaRecorders = []
		callParticipantCollection = new CallParticipantCollectionStub()
		audioContext = {
			currentTime: 0,
			resume: vi.fn(() => Promise.resolve()),
			close: vi.fn(() => Promise.resolve()),
			getOutputTimestamp: vi.fn(() => ({ contextTime: audioContext.currentTime, performanceTime: now })),
			createMediaStreamSource: vi.fn((stream) => ({ stream, connect: vi.fn(), disconnect: vi.fn() })),
			createMediaStreamDestination: vi.fn(() => ({ stream: { id: 'destination' } })),
		}
		recorder = new ParticipantTrackRecorder(callParticipantCollection, {
			createAudioContext: () => audioContext,
			createMediaRecorder: (stream, options) => {
				const mediaRecorder = new MediaRecorderMock(audioContext.createMediaStreamSource.mock.calls.at(-1)[0], options)
				mediaRecorders.push(mediaRecorder)
				return mediaRecorder
			},
			isTypeSupported: (mimeType) => mimeType === 'audio/webm;codecs=opus',
			now: () => now,
			blobToBase64: (blob) => Promise.resolve(blob.data),
		})
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	test('records each participant on its own mono track', async () => {
		addCallParticipantModel(new CallParticipantModelStub('peer-a', {
			stream: new MediaStreamMock('stream-a'),
			actorType: 'users',
			actorId: 'alice',
			userId: 'alice',
			name: 'Alice',
			nextcloudSessionId: 'session-a',
		}))

		expect(recorder.start({ timeslice: 1000 })).toEqual({ startPageTime: 1, mimeType: 'audio/webm;codecs=opus' })

		now = 2500
		addCallParticipantModel(new CallParticipantModelStub('peer-b', {
			stream: new MediaStreamMock('stream-b'),
			actorType: 'guests',
			actorId: 'guest-hash',
			// The name of the guest is not known.
		}))

		expect(mediaRecorders).toHaveLength(2)
		expect(mediaRecorders[0].options).toEqual({ audioBitsPerSecond: 32000, mimeType: 'audio/webm;codecs=opus' })
		expect(mediaRecorders[0].timeslice).toBe(1000)
		const destination = audioContext.createMediaStreamDestination.mock.results[0].value
		expect(destination.channelCount).toBe(1)
		expect(destination.channelCountMode).toBe('explicit')

		mediaRecorders[0].emitData('a-0')
		mediaRecorders[1].emitData('b-0')
		await Promise.resolve()
		await Promise.resolve()

		expect(recorder.drain()).toEqual([
			{ segmentId: '1', seq: 0, data: 'a-0' },
			{ segmentId: '2', seq: 0, data: 'b-0' },
		])
		expect(recorder.drain()).toEqual([])

		now = 4000
		const manifest = await recorder.stop()

		expect(recorder.drain()).toEqual([
			{ segmentId: '1', seq: 1, data: 'last-stream-a' },
			{ segmentId: '2', seq: 1, data: 'last-stream-b' },
		])
		expect(manifest).toEqual({
			version: 1,
			mimeType: 'audio/webm;codecs=opus',
			startPageTime: 1,
			anchors: [
				{ pageTime: 1, contextTime: 0, outputContextTime: 0, outputPageTime: 1 },
				{ pageTime: 4, contextTime: 0, outputContextTime: 0, outputPageTime: 4 },
			],
			segments: [{
				id: '1',
				peerId: 'peer-a',
				sessionId: 'session-a',
				actorType: 'users',
				actorId: 'alice',
				userId: 'alice',
				displayName: 'Alice',
				startPageTime: 1,
				endPageTime: 4,
				chunkCount: 2,
				endReason: 'stopped',
			}, {
				id: '2',
				peerId: 'peer-b',
				sessionId: null,
				actorType: 'guests',
				actorId: 'guest-hash',
				userId: null,
				displayName: null,
				startPageTime: 2.5,
				endPageTime: 4,
				chunkCount: 2,
				endReason: 'stopped',
			}],
		})
		expect(audioContext.close).toHaveBeenCalled()
	})

	test('starts a new segment when the stream changes and closes it when the participant leaves', async () => {
		const callParticipantModel = new CallParticipantModelStub('peer-a', { stream: new MediaStreamMock('stream-a'), name: 'Alice' })
		addCallParticipantModel(callParticipantModel)
		recorder.start()

		now = 3000
		callParticipantModel.set('stream', new MediaStreamMock('stream-a2'))
		now = 5000
		removeCallParticipantModel(callParticipantModel)

		const manifest = await recorder.stop()

		expect(manifest.segments.map(({ id, startPageTime, endPageTime, endReason }) => ({ id, startPageTime, endPageTime, endReason }))).toEqual([
			{ id: '1', startPageTime: 1, endPageTime: 3, endReason: 'stream_changed' },
			{ id: '2', startPageTime: 3, endPageTime: 5, endReason: 'left' },
		])
		expect(audioContext.createMediaStreamSource.mock.results.every(({ value }) => value.disconnect.mock.calls.length === 1)).toBe(true)
	})

	test('waits for the audio track of a stream without audio', async () => {
		const stream = new MediaStreamMock('stream-a', 0)
		addCallParticipantModel(new CallParticipantModelStub('peer-a', { stream }))
		recorder.start()

		expect(mediaRecorders).toHaveLength(0)

		now = 2000
		stream.addTrack()

		expect(mediaRecorders).toHaveLength(1)
		const manifest = await recorder.stop()
		expect(manifest.segments[0].startPageTime).toBe(2)
	})

	test('ignores screen shares and participants without stream', async () => {
		addCallParticipantModel(new CallParticipantModelStub('peer-a', { screen: new MediaStreamMock('screen-a') }))
		recorder.start()

		expect(mediaRecorders).toHaveLength(0)
		expect((await recorder.stop()).segments).toEqual([])
	})

	test('adds clock anchors periodically', async () => {
		recorder.start({ anchorInterval: 1000 })
		now = 2000
		audioContext.currentTime = 1
		vi.advanceTimersByTime(1000)

		const manifest = await recorder.stop()
		expect(manifest.anchors.map(({ pageTime, contextTime }) => [pageTime, contextTime])).toEqual([
			[1, 0],
			[2, 1],
			[2, 1],
		])
	})

	test('stop without start returns null', async () => {
		expect(await recorder.stop()).toBeNull()
	})
})
