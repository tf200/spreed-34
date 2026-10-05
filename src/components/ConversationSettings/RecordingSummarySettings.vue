<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { RecordingSummaryTemplate, RecordingSummaryTemplateReference } from '../../services/recordingSummaryTemplateService.ts'

import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { computed, onMounted, ref } from 'vue'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import {
	getConversationSummaryTemplate,
	getRecordingSummaryTemplates,
	setConversationSummaryTemplate,
} from '../../services/recordingSummaryTemplateService.ts'
import { getSourceLabel } from '../RecordingSummaryTemplates/summaryTemplates.ts'

type Option = { id: string | null, label: string, source: string }

const props = defineProps<{
	token: string
}>()

const templates = ref<RecordingSummaryTemplate[]>([])
const conversationTemplate = ref<RecordingSummaryTemplateReference | null>(null)
const loading = ref(true)
const saving = ref(false)

const defaultOption = computed<Option>(() => ({
	id: null,
	label: t('spreed', 'Default template of the moderator'),
	source: '',
}))
const options = computed<Option[]>(() => {
	const list: Option[] = [
		defaultOption.value,
		...templates.value.map((template) => ({ id: template.id, label: template.name, source: getSourceLabel(template.source) })),
	]
	// A personal template of another moderator is only known by its name.
	const current = conversationTemplate.value
	if (current?.id && !list.some(({ id }) => id === current.id)) {
		list.push({ id: current.id, label: current.name, source: t('spreed', 'Chosen by another moderator') })
	}
	return list
})
const selected = computed(() => options.value.find(({ id }) => id === (conversationTemplate.value?.id ?? null)) ?? defaultOption.value)

onMounted(async () => {
	try {
		const [templatesResponse, conversationResponse] = await Promise.all([
			getRecordingSummaryTemplates(),
			getConversationSummaryTemplate(props.token),
		])
		templates.value = templatesResponse.data.ocs.data
		conversationTemplate.value = conversationResponse.data.ocs.data.conversation
	} catch {
		showError(t('spreed', 'Could not load the summary template of the conversation'))
	} finally {
		loading.value = false
	}
})

/**
 * Save the template of the conversation.
 *
 * @param option Selected option
 */
async function setTemplate(option: Option | null) {
	const id = option?.id ?? null
	saving.value = true
	try {
		await setConversationSummaryTemplate(props.token, id)
		conversationTemplate.value = id === null ? null : { id, name: option!.label }
	} catch {
		showError(t('spreed', 'Could not set the summary template of the conversation'))
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<div class="app-settings-subsection">
		<h4 class="app-settings-section__subtitle">
			{{ t('spreed', 'Recording summary') }}
		</h4>
		<p class="app-settings-section__hint">
			{{ t('spreed', 'Template used to summarize recorded calls in this conversation, for example a stand-up template for daily meetings.') }}
		</p>
		<NcSelect
			:modelValue="selected"
			:inputLabel="t('spreed', 'Summary template')"
			:options="options"
			:loading="loading || saving"
			:disabled="loading || saving"
			:clearable="false"
			label="label"
			@update:modelValue="setTemplate">
			<template #option="{ label, source }">
				<span class="recording-summary-settings__option">
					<span>{{ label }}</span>
					<span class="recording-summary-settings__source">{{ source }}</span>
				</span>
			</template>
		</NcSelect>
	</div>
</template>

<style scoped lang="scss">
.recording-summary-settings {
	&__option {
		display: flex;
		justify-content: space-between;
		gap: var(--default-grid-baseline);
		width: 100%;
	}

	&__source {
		color: var(--color-text-maxcontrast);
	}
}
</style>
