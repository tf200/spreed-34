/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

export type RecordingArtifact = {
	id: string
	type: 'transcript' | 'summary'
	state: 'draft' | 'editing' | 'publishing' | 'published'
	fileName: string
	content: string
	etag: string
	publishedFileId: string | null
	publishedMessageId: string | null
}

export type RecordingArtifactListItem = Pick<RecordingArtifact, 'id' | 'type' | 'state' | 'fileName'> & {
	updatedAt: number
	notificationTimestamp: number
}

type OcsResponse<T> = {
	ocs: {
		data: T
	}
}

/**
 * Load a private transcript or summary draft.
 *
 * @param token Conversation token
 * @param artifactId Artifact ID
 */
function getRecordingArtifact(token: string, artifactId: string) {
	return axios.get<OcsResponse<RecordingArtifact>>(generateOcsUrl('apps/spreed/api/v1/recording/{token}/artifact/{artifactId}', { token, artifactId }))
}

/**
 * List the current user's unpublished recording artifacts in a conversation.
 *
 * @param token Conversation token
 */
function getRecordingArtifacts(token: string) {
	return axios.get<OcsResponse<RecordingArtifactListItem[]>>(generateOcsUrl('apps/spreed/api/v1/recording/{token}/artifacts', { token }))
}

/**
 * Save reviewed Markdown using the current ETag.
 *
 * @param token Conversation token
 * @param artifactId Artifact ID
 * @param content Updated Markdown
 * @param etag Expected ETag
 */
function updateRecordingArtifact(token: string, artifactId: string, content: string, etag: string) {
	return axios.put<OcsResponse<RecordingArtifact>>(generateOcsUrl('apps/spreed/api/v1/recording/{token}/artifact/{artifactId}', { token, artifactId }), {
		content,
		etag,
	})
}

/**
 * Publish a detached snapshot and dismiss its notification.
 *
 * @param token Conversation token
 * @param artifactId Artifact ID
 * @param etag Expected ETag
 * @param timestamp Notification timestamp
 */
function publishRecordingArtifact(token: string, artifactId: string, etag: string, timestamp: number) {
	return axios.post<OcsResponse<RecordingArtifact>>(generateOcsUrl('apps/spreed/api/v1/recording/{token}/artifact/{artifactId}/publish', { token, artifactId }), {
		etag,
		timestamp,
	})
}

export {
	getRecordingArtifact,
	getRecordingArtifacts,
	publishRecordingArtifact,
	updateRecordingArtifact,
}
