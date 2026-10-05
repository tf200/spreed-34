/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Component } from 'vue'
import type { RecordingSummaryTemplate, RecordingSummaryTemplateDefinition } from '../../services/recordingSummaryTemplateService.ts'

import { getLanguage, t } from '@nextcloud/l10n'
import IconAccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import IconAccountSearchOutline from 'vue-material-design-icons/AccountSearchOutline.vue'
import IconAccountVoice from 'vue-material-design-icons/AccountVoice.vue'
import IconDomain from 'vue-material-design-icons/Domain.vue'
import IconForumOutline from 'vue-material-design-icons/ForumOutline.vue'
import IconHandshakeOutline from 'vue-material-design-icons/HandshakeOutline.vue'
import IconHistory from 'vue-material-design-icons/History.vue'
import IconLightbulbOutline from 'vue-material-design-icons/LightbulbOutline.vue'
import IconRocketOutline from 'vue-material-design-icons/RocketOutline.vue'
import IconTextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'

const BUILT_IN_ICONS: Record<string, Component> = {
	'builtin-general': IconForumOutline,
	'builtin-standup': IconAccountGroupOutline,
	'builtin-one-on-one': IconAccountVoice,
	'builtin-client-call': IconHandshakeOutline,
	'builtin-kickoff': IconRocketOutline,
	'builtin-retrospective': IconHistory,
	'builtin-interview': IconAccountSearchOutline,
	'builtin-brainstorm': IconLightbulbOutline,
}

/**
 * Icon of a template: its own one for built-in templates.
 *
 * @param template The template
 */
export function getTemplateIcon(template: Pick<RecordingSummaryTemplate, 'id' | 'source'>): Component {
	if (template.source === 'builtin') {
		return BUILT_IN_ICONS[template.id] ?? IconTextBoxOutline
	}
	return template.source === 'organization' ? IconDomain : IconTextBoxOutline
}

/**
 * Short list of the section titles, to show what a template produces.
 *
 * @param template The template
 */
export function getSectionsSummary(template: Pick<RecordingSummaryTemplate, 'definition' | 'description'>): string {
	if (template.description) {
		return template.description
	}
	return template.definition.sections.map(({ title }) => title).join(' · ')
		|| t('spreed', 'Custom instructions')
}

/**
 * Languages a summary can be written in, keep in sync with
 * SummaryTemplateDefinition::LANGUAGES.
 */
const LANGUAGE_CODES = ['ar', 'cs', 'da', 'de', 'el', 'en', 'es', 'fi', 'fr', 'hu', 'it', 'ja', 'ko', 'nb', 'nl', 'pl', 'pt', 'ro', 'ru', 'sv', 'tr', 'uk', 'zh']

/**
 * The languages with their names in the language of the user.
 */
export function getLanguageOptions(): { id: string, label: string }[] {
	let names: Intl.DisplayNames | null = null
	try {
		names = new Intl.DisplayNames([getLanguage().replace('_', '-')], { type: 'language' })
	} catch {
		// The codes are shown instead.
	}
	return LANGUAGE_CODES
		.map((id) => ({ id, label: names?.of(id) ?? id }))
		.sort((a, b) => a.label.localeCompare(b.label))
}

/**
 * Definition of a new template.
 */
export function getEmptyDefinition(): RecordingSummaryTemplateDefinition {
	return {
		sections: [
			{ title: t('spreed', 'Overview'), description: '' },
		],
		length: 'standard',
		style: 'bullets',
		language: '',
		actionItemsTable: false,
		transcriptLinks: false,
		extraInstructions: '',
	}
}

export const SOURCE_ORDER: RecordingSummaryTemplate['source'][] = ['personal', 'organization', 'builtin']

/**
 * Heading of the group of templates of a source.
 *
 * @param source Source of the templates
 */
export function getSourceLabel(source: RecordingSummaryTemplate['source']): string {
	switch (source) {
		case 'personal':
			return t('spreed', 'Personal')
		case 'organization':
			return t('spreed', 'Organization')
		default:
			return t('spreed', 'Built-in')
	}
}
