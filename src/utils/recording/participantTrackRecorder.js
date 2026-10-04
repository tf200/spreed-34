/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const MIME_TYPES = [
	'audio/ogg;codecs=opus',
	'audio/webm;codecs=opus',
]

const DEFAULT_OPTIONS = {
	// Interval in milliseconds after which the recorders emit a chunk.
	timeslice: 2000,
	audioBitsPerSecond: 32000,
	// Interval in milliseconds between AudioContext/page clock anchors.
	anchorInterval: 10000,
}

/**
 * Records the audio of every call participant on its own track.
 *
 * All the participant streams are routed through a single AudioContext, so all
 * the tracks share the same sample clock and keep advancing (with silence) even
 * if no packets are received. Each stream is recorded as a "segment"; a new
 * segment is started whenever the stream of a participant changes (join,
 * leave, reconnection...). The start and end of each segment are given in the
 * page clock (seconds of "performance.now()"), which is the same clock used by
 * the speaker timeline.
 *
 * The encoded chunks are kept in memory as base64 strings until they are
 * drained by the recording server, which should be done periodically to keep
 * the memory usage bounded.
 *
 * Screen share audio is not recorded.
 *
 * @param {object} callParticipantCollection the CallParticipantCollection.
 * @param {object} [dependencies] overridable browser APIs (for tests).
 */
export default function ParticipantTrackRecorder(callParticipantCollection, dependencies = {}) {
	this._callParticipantCollection = callParticipantCollection

	this._createAudioContext = dependencies.createAudioContext ?? (() => new (window.AudioContext || window.webkitAudioContext)())
	this._createMediaRecorder = dependencies.createMediaRecorder ?? ((stream, options) => new MediaRecorder(stream, options))
	this._isTypeSupported = dependencies.isTypeSupported ?? ((mimeType) => MediaRecorder.isTypeSupported(mimeType))
	this._now = dependencies.now ?? (() => performance.now())
	this._blobToBase64 = dependencies.blobToBase64 ?? blobToBase64

	this._started = false
	this._options = DEFAULT_OPTIONS
	this._audioContext = null
	this._mimeType = null
	this._startPageTime = null
	this._anchors = []
	this._anchorIntervalId = null
	this._nextSegmentId = 1
	this._activeSegments = new Map()
	this._segments = []
	this._chunks = []
	this._pendingConversions = new Set()
	this._stoppedPromises = []

	this._handleCallParticipantAddedBound = this._handleCallParticipantAdded.bind(this)
	this._handleCallParticipantRemovedBound = this._handleCallParticipantRemoved.bind(this)
	this._handleStreamChangedBound = this._handleStreamChanged.bind(this)
}

ParticipantTrackRecorder.prototype = {

	/**
	 * Starts recording all the current and future call participants.
	 *
	 * @param {object} [options] see DEFAULT_OPTIONS.
	 * @return {{startPageTime: number, mimeType: string}} the page time (in
	 *         seconds) at which recording started and the MIME type of the
	 *         chunks (empty if the browser default is used).
	 */
	start(options = {}) {
		if (this._started) {
			return { startPageTime: this._startPageTime, mimeType: this._mimeType }
		}

		this._options = { ...DEFAULT_OPTIONS, ...options }
		this._mimeType = MIME_TYPES.find((mimeType) => this._isTypeSupported(mimeType)) ?? ''
		this._audioContext = this._createAudioContext()
		// Autoplay is allowed in the recording browser, but resume just in case.
		this._audioContext.resume?.()?.catch?.(() => {})

		this._started = true
		this._startPageTime = this._pageTime()
		this._addAnchor()
		this._anchorIntervalId = setInterval(() => this._addAnchor(), this._options.anchorInterval)

		this._callParticipantCollection.on('add', this._handleCallParticipantAddedBound)
		this._callParticipantCollection.on('remove', this._handleCallParticipantRemovedBound)
		this._callParticipantCollection.callParticipantModels.forEach((callParticipantModel) => {
			this._handleCallParticipantAdded(this._callParticipantCollection, callParticipantModel)
		})

		return { startPageTime: this._startPageTime, mimeType: this._mimeType }
	},

	/**
	 * Returns and forgets the chunks recorded since the previous call.
	 *
	 * Each chunk is identified by its segment and its sequence number in the
	 * segment; chunks are returned in the order in which they were encoded, but
	 * consumers should sort them by sequence number anyway.
	 *
	 * @return {Array<{segmentId: string, seq: number, data: string}>}
	 */
	drain() {
		const chunks = this._chunks
		this._chunks = []
		return chunks
	},

	/**
	 * Stops recording and returns the manifest.
	 *
	 * The last chunks are available through "drain()" once the returned promise
	 * is resolved.
	 *
	 * @return {Promise<object|null>} the manifest, or null if not started.
	 */
	async stop() {
		if (!this._started) {
			return null
		}

		this._callParticipantCollection.off('add', this._handleCallParticipantAddedBound)
		this._callParticipantCollection.off('remove', this._handleCallParticipantRemovedBound)
		this._callParticipantCollection.callParticipantModels.forEach((callParticipantModel) => {
			callParticipantModel.off('change:stream', this._handleStreamChangedBound)
		})

		for (const peerId of [...this._activeSegments.keys()]) {
			this._closeSegment(peerId, 'stopped')
		}

		await Promise.all(this._stoppedPromises)
		await Promise.all([...this._pendingConversions])

		clearInterval(this._anchorIntervalId)
		this._addAnchor()
		const manifest = this.getManifest()

		this._started = false
		try {
			await this._audioContext.close()
		} catch (exception) {
			console.error('Error closing the audio context of the track recorder', exception)
		}

		return manifest
	},

	/**
	 * @return {object} the manifest describing the recorded segments.
	 */
	getManifest() {
		return {
			version: 1,
			mimeType: this._mimeType,
			startPageTime: this._startPageTime,
			anchors: [...this._anchors],
			segments: this._segments.map((segment) => ({
				id: segment.id,
				peerId: segment.peerId,
				...getIdentity(segment.callParticipantModel),
				startPageTime: segment.startPageTime,
				endPageTime: segment.endPageTime,
				chunkCount: segment.chunkCount,
				endReason: segment.endReason,
			})),
		}
	},

	_handleCallParticipantAdded(callParticipantCollection, callParticipantModel) {
		callParticipantModel.on('change:stream', this._handleStreamChangedBound)
		this._handleStreamChanged(callParticipantModel, callParticipantModel.get('stream'))
	},

	_handleCallParticipantRemoved(callParticipantCollection, callParticipantModel) {
		callParticipantModel.off('change:stream', this._handleStreamChangedBound)
		this._closeSegment(callParticipantModel.get('peerId'), 'left')
	},

	_handleStreamChanged(callParticipantModel, stream) {
		const peerId = callParticipantModel.get('peerId')
		this._closeSegment(peerId, 'stream_changed')

		if (!stream) {
			return
		}

		if (stream.getAudioTracks().length === 0) {
			// The audio track may be added later to the same stream.
			const handleAddTrack = () => {
				stream.removeEventListener('addtrack', handleAddTrack)
				if (this._started && callParticipantModel.get('stream') === stream && !this._activeSegments.has(peerId)) {
					this._handleStreamChanged(callParticipantModel, stream)
				}
			}
			stream.addEventListener('addtrack', handleAddTrack)
			return
		}

		this._openSegment(callParticipantModel, stream)
	},

	_openSegment(callParticipantModel, stream) {
		const peerId = callParticipantModel.get('peerId')

		let audioSource
		let audioDestination
		let mediaRecorder
		try {
			audioSource = this._audioContext.createMediaStreamSource(stream)
			audioDestination = this._audioContext.createMediaStreamDestination()
			// Downmix to mono; each track contains a single participant.
			audioDestination.channelCount = 1
			audioDestination.channelCountMode = 'explicit'
			audioSource.connect(audioDestination)

			const recorderOptions = { audioBitsPerSecond: this._options.audioBitsPerSecond }
			if (this._mimeType) {
				recorderOptions.mimeType = this._mimeType
			}
			mediaRecorder = this._createMediaRecorder(audioDestination.stream, recorderOptions)
		} catch (exception) {
			console.error('Could not record the audio of participant %s', peerId, exception)
			audioSource?.disconnect()
			return
		}

		const segment = {
			id: String(this._nextSegmentId++),
			peerId,
			callParticipantModel,
			audioSource,
			mediaRecorder,
			startPageTime: null,
			endPageTime: null,
			chunkCount: 0,
			endReason: null,
		}

		let resolveStopped
		this._stoppedPromises.push(new Promise((resolve) => {
			resolveStopped = resolve
		}))

		mediaRecorder.addEventListener('start', () => {
			segment.startPageTime = this._pageTime()
		})
		mediaRecorder.addEventListener('dataavailable', (event) => {
			if (event.data && event.data.size > 0) {
				this._addChunk(segment, event.data)
			}
		})
		mediaRecorder.addEventListener('stop', () => {
			segment.endPageTime ??= this._pageTime()
			resolveStopped()
		})
		mediaRecorder.addEventListener('error', (event) => {
			console.error('Error recording the audio of participant %s', peerId, event.error)
			this._closeSegment(peerId, 'error')
			resolveStopped()
		})

		this._activeSegments.set(peerId, segment)
		this._segments.push(segment)

		mediaRecorder.start(this._options.timeslice)
	},

	_closeSegment(peerId, endReason) {
		const segment = this._activeSegments.get(peerId)
		if (!segment) {
			return
		}

		this._activeSegments.delete(peerId)
		segment.endReason = endReason
		segment.endPageTime = this._pageTime()

		if (segment.mediaRecorder.state !== 'inactive') {
			segment.mediaRecorder.stop()
		}
		segment.audioSource.disconnect()
	},

	_addChunk(segment, blob) {
		const seq = segment.chunkCount++
		const conversion = this._blobToBase64(blob).then((data) => {
			this._chunks.push({ segmentId: segment.id, seq, data })
		}).catch((exception) => {
			console.error('Could not encode chunk %d of segment %s', seq, segment.id, exception)
		}).finally(() => {
			this._pendingConversions.delete(conversion)
		})
		this._pendingConversions.add(conversion)
	},

	_addAnchor() {
		const outputTimestamp = this._audioContext.getOutputTimestamp?.()
		this._anchors.push({
			pageTime: this._pageTime(),
			contextTime: this._audioContext.currentTime,
			outputContextTime: outputTimestamp?.contextTime ?? null,
			outputPageTime: outputTimestamp?.performanceTime === undefined ? null : outputTimestamp.performanceTime / 1000,
		})
	},

	_pageTime() {
		return this._now() / 1000
	},

}

/**
 * @param {object} callParticipantModel the model of the participant.
 * @return {object} the identity of the participant.
 */
function getIdentity(callParticipantModel) {
	const { nextcloudSessionId, actorType, actorId, userId, name } = callParticipantModel.attributes
	return {
		sessionId: nextcloudSessionId ?? null,
		actorType: actorType ?? null,
		actorId: actorId ?? null,
		userId: userId ?? null,
		// Unknown names are resolved by the server, never replaced by an id.
		displayName: name || null,
	}
}

/**
 * @param {Blob} blob the blob to encode.
 * @return {Promise<string>} the blob contents encoded in base64.
 */
function blobToBase64(blob) {
	return new Promise((resolve, reject) => {
		const reader = new FileReader()
		reader.addEventListener('load', () => {
			// Strip the "data:<mime type>;base64," prefix.
			resolve(reader.result.slice(reader.result.indexOf(',') + 1))
		})
		reader.addEventListener('error', () => reject(reader.error))
		reader.readAsDataURL(blob)
	})
}
