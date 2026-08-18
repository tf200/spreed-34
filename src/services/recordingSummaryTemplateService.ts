/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

export type RecordingSummaryTemplate = {
	id: string
	name: string
	instructions: string
}

type OcsResponse<T> = { ocs: { data: T } }
const baseUrl = () => generateOcsUrl('apps/spreed/api/v1/recording/summary-templates')

/**
 *
 */
function getRecordingSummaryTemplates() {
	return axios.get<OcsResponse<RecordingSummaryTemplate[]>>(baseUrl())
}

/**
 *
 * @param name
 * @param instructions
 */
function createRecordingSummaryTemplate(name: string, instructions: string) {
	return axios.post<OcsResponse<RecordingSummaryTemplate>>(baseUrl(), { name, instructions })
}

/**
 *
 * @param id
 * @param name
 * @param instructions
 */
function updateRecordingSummaryTemplate(id: string, name: string, instructions: string) {
	return axios.put<OcsResponse<RecordingSummaryTemplate>>(`${baseUrl()}/${encodeURIComponent(id)}`, { name, instructions })
}

/**
 *
 * @param id
 */
function deleteRecordingSummaryTemplate(id: string) {
	return axios.delete(`${baseUrl()}/${encodeURIComponent(id)}`)
}

export {
	createRecordingSummaryTemplate,
	deleteRecordingSummaryTemplate,
	getRecordingSummaryTemplates,
	updateRecordingSummaryTemplate,
}
