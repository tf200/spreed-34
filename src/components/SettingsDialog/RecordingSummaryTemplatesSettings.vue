<!-- SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup lang="ts">
import type { RecordingSummaryTemplate } from '../../services/recordingSummaryTemplateService.ts'

import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import IconTextBoxEditOutline from 'vue-material-design-icons/TextBoxEditOutline.vue'
import RecordingSummaryTemplatesManager from '../RecordingSummaryTemplates/RecordingSummaryTemplatesManager.vue'
import { getRecordingSummaryTemplates, setDefaultRecordingSummaryTemplate } from '../../services/recordingSummaryTemplateService.ts'
import { getSourceLabel } from '../RecordingSummaryTemplates/summaryTemplates.ts'

const templates = ref<RecordingSummaryTemplate[]>([])
const loading = ref(true)
const saving = ref(false)

const options = computed(() => templates.value.map((template) => ({
	id: template.id,
	label: template.name,
	source: getSourceLabel(template.source),
})))
const defaultTemplate = computed(() => options.value.find(({ id }) => id === templates.value.find(({ isDefault }) => isDefault)?.id) ?? null)

onMounted(load)

/** Load the templates. */
async function load() {
	try {
		templates.value = (await getRecordingSummaryTemplates()).data.ocs.data
	} catch {
		showError(t('spreed', 'Could not load the summary templates'))
	} finally {
		loading.value = false
	}
}

/**
 * Set the default template.
 *
 * @param option Selected option
 */
async function setDefault(option: { id: string } | null) {
	if (!option) {
		return
	}
	saving.value = true
	try {
		await setDefaultRecordingSummaryTemplate(option.id)
		templates.value = templates.value.map((template) => ({ ...template, isDefault: template.id === option.id }))
	} catch {
		showError(t('spreed', 'Could not set the default template'))
	} finally {
		saving.value = false
	}
}

/** Open the template manager, and show its changes afterwards. */
async function manage() {
	await spawnDialog(RecordingSummaryTemplatesManager)
	await load()
}
</script>

<template>
	<div class="summary-templates">
		<p class="summary-templates__hint">
			{{ t('spreed', 'Templates decide what the AI summary of a recorded call contains. A conversation can have its own template, otherwise your default is used.') }}
		</p>
		<NcSelect
			:modelValue="defaultTemplate"
			:inputLabel="t('spreed', 'Default template')"
			:options="options"
			:loading="loading || saving"
			:disabled="loading || saving"
			:clearable="false"
			label="label"
			@update:modelValue="setDefault">
			<template #option="{ label, source }">
				<span class="summary-templates__option">
					<span>{{ label }}</span>
					<span class="summary-templates__option-source">{{ source }}</span>
				</span>
			</template>
		</NcSelect>
		<NcButton variant="secondary" wide @click="manage">
			<template #icon>
				<IconTextBoxEditOutline :size="20" />
			</template>
			{{ t('spreed', 'Manage summary templates') }}
		</NcButton>
	</div>
</template>

<style scoped lang="scss">
.summary-templates {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));

	&__hint {
		color: var(--color-text-maxcontrast);
	}

	&__option {
		display: flex;
		justify-content: space-between;
		gap: var(--default-grid-baseline);
		width: 100%;
	}

	&__option-source {
		color: var(--color-text-maxcontrast);
	}
}
</style>
