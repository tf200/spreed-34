/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { components } from '../types/openapi/openapi.ts'

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

export type RecordingSummaryTemplate = components['schemas']['RecordingSummaryTemplate']
export type RecordingSummaryTemplateDefinition = components['schemas']['RecordingSummaryTemplateDefinition']
export type RecordingSummaryTemplateSection = components['schemas']['RecordingSummaryTemplateSection']
export type RecordingSummaryTemplateReference = components['schemas']['RecordingSummaryTemplateReference']

export type RecordingSummaryTemplateInput = {
	name: string
	description: string
	definition: RecordingSummaryTemplateDefinition
}

type OcsResponse<T> = { ocs: { data: T } }
const baseUrl = () => generateOcsUrl('apps/spreed/api/v1/recording/summary-templates')

/**
 * List the built-in, organization and personal templates the user can use.
 */
function getRecordingSummaryTemplates() {
	return axios.get<OcsResponse<RecordingSummaryTemplate[]>>(baseUrl())
}

/**
 * Create a personal template, or an organization template (administrators only).
 *
 * @param template Name, description and definition
 * @param organization Whether all users can use the template
 */
function createRecordingSummaryTemplate(template: RecordingSummaryTemplateInput, organization = false) {
	return axios.post<OcsResponse<RecordingSummaryTemplate>>(baseUrl(), { ...template, organization })
}

/**
 * Update a template.
 *
 * @param id Template ID
 * @param template Name, description and definition
 */
function updateRecordingSummaryTemplate(id: string, template: RecordingSummaryTemplateInput) {
	return axios.put<OcsResponse<RecordingSummaryTemplate>>(`${baseUrl()}/${encodeURIComponent(id)}`, template)
}

/**
 * Delete a template.
 *
 * @param id Template ID
 */
function deleteRecordingSummaryTemplate(id: string) {
	return axios.delete(`${baseUrl()}/${encodeURIComponent(id)}`)
}

/**
 * Set the default template of the user.
 *
 * @param templateId Template ID
 */
function setDefaultRecordingSummaryTemplate(templateId: string) {
	return axios.put(`${baseUrl()}/default`, { templateId })
}

/**
 * Summarize a sample meeting with a template definition.
 *
 * @param definition Sections and options of the summary
 */
function previewRecordingSummaryTemplate(definition: RecordingSummaryTemplateDefinition) {
	return axios.post<OcsResponse<{ summary: string, transcript: string }>>(`${baseUrl()}/preview`, { definition })
}

/**
 * Get the template of the conversation and of the recording in progress.
 *
 * @param token Conversation token
 */
function getConversationSummaryTemplate(token: string) {
	return axios.get<OcsResponse<{ conversation: RecordingSummaryTemplateReference | null, active: RecordingSummaryTemplateReference | null }>>(generateOcsUrl('apps/spreed/api/v1/recording/{token}/summary-template', { token }))
}

/**
 * Set the template used for the recordings of the conversation.
 *
 * @param token Conversation token
 * @param templateId Template ID, or null to use the default template of the moderator
 */
function setConversationSummaryTemplate(token: string, templateId: string | null) {
	return axios.put(generateOcsUrl('apps/spreed/api/v1/recording/{token}/summary-template', { token }), { templateId })
}

/**
 * Change the template of the recording in progress.
 *
 * @param token Conversation token
 * @param templateId Template ID
 */
function setActiveSummaryTemplate(token: string, templateId: string) {
	return axios.put(generateOcsUrl('apps/spreed/api/v1/recording/{token}/summary-template/active', { token }), { templateId })
}

export {
	createRecordingSummaryTemplate,
	deleteRecordingSummaryTemplate,
	getConversationSummaryTemplate,
	getRecordingSummaryTemplates,
	previewRecordingSummaryTemplate,
	setActiveSummaryTemplate,
	setConversationSummaryTemplate,
	setDefaultRecordingSummaryTemplate,
	updateRecordingSummaryTemplate,
}
