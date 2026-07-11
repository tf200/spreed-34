/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

/**
 * Save Google Cloud recording settings.
 *
 * @param {object} settings Settings and optional replacement credential
 */
export function saveRecordingGoogleSettings(settings) {
	return axios.post(generateOcsUrl('apps/spreed/api/v1/settings/recording/google'), settings)
}
