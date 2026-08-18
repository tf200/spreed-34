/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

/**
 * Get welcome message from the recording server
 *
 * @param {number} serverId the index in the list of configured recording
 *        servers
 */
async function getWelcomeMessage(serverId) {
	return axios.get(generateOcsUrl('apps/spreed/api/v1/recording/welcome/{serverId}', { serverId }))
}

/**
 * Start call recording
 *
 * @param {string} token conversation token
 * @param {number} callRecording the type of the recording being started (@see constants CALL.RECORDING.*)
 * @param {string|null} summaryTemplateId summary template ID, or null for the built-in default
 */
async function startCallRecording(token, callRecording, summaryTemplateId) {
	await axios.post(
		generateOcsUrl('apps/spreed/api/v1/recording/{token}', { token }),
		{
			status: callRecording,
			...(summaryTemplateId !== null && summaryTemplateId !== undefined ? { summaryTemplateId } : {}),
		},
	)
}

/**
 * Stop call recording
 *
 * @param {string} token conversation token
 */
async function stopCallRecording(token) {
	await axios.delete(generateOcsUrl('apps/spreed/api/v1/recording/{token}', { token }))
}

export {
	getWelcomeMessage,
	startCallRecording,
	stopCallRecording,
}
