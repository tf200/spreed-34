/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { afterEach, describe, expect, test, vi } from 'vitest'
import {
	getRecordingArtifact,
	getRecordingArtifacts,
	publishRecordingArtifact,
	updateRecordingArtifact,
} from '../recordingArtifactService.ts'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
		put: vi.fn(),
	},
}))

describe('recordingArtifactService', () => {
	afterEach(() => vi.clearAllMocks())

	test('loads an artifact', () => {
		getRecordingArtifact('token', '42')

		expect(axios.get).toHaveBeenCalledWith(generateOcsUrl('apps/spreed/api/v1/recording/{token}/artifact/{artifactId}', { token: 'token', artifactId: '42' }))
	})

	test('lists unpublished artifacts for a conversation', () => {
		getRecordingArtifacts('token')

		expect(axios.get).toHaveBeenCalledWith(generateOcsUrl('apps/spreed/api/v1/recording/{token}/artifacts', { token: 'token' }))
	})

	test('saves artifact content with its ETag', () => {
		updateRecordingArtifact('token', '42', '# Corrected', 'etag-1')

		expect(axios.put).toHaveBeenCalledWith(
			generateOcsUrl('apps/spreed/api/v1/recording/{token}/artifact/{artifactId}', { token: 'token', artifactId: '42' }),
			{ content: '# Corrected', etag: 'etag-1' },
		)
	})

	test('publishes an artifact and identifies its notification', () => {
		publishRecordingArtifact('token', '42', 'etag-2', 123456)

		expect(axios.post).toHaveBeenCalledWith(
			generateOcsUrl('apps/spreed/api/v1/recording/{token}/artifact/{artifactId}/publish', { token: 'token', artifactId: '42' }),
			{ etag: 'etag-2', timestamp: 123456 },
		)
	})
})
