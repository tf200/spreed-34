<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
import type { RecordingSummaryTemplate } from '../services/recordingSummaryTemplateService.ts'

import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { getRecordingSummaryTemplates } from '../services/recordingSummaryTemplateService.ts'

const emit = defineEmits<{ close: [string | null | undefined] }>()
const templates = ref<RecordingSummaryTemplate[]>([])
const selectedId = ref<string | null>(null)
const loading = ref(true)

onMounted(async () => {
	try {
		templates.value = (await getRecordingSummaryTemplates()).data.ocs.data
	} catch (error) {
		showError(t('spreed', 'Could not load recording summary templates'))
		emit('close', undefined)
	} finally {
		loading.value = false
	}
})
</script>

<template>
	<NcDialog :name="t('spreed', 'Choose a summary template')" @closing="emit('close', undefined)">
		<NcLoadingIcon v-if="loading" class="recording-template-dialog__loading" />
		<div v-else class="recording-template-dialog__options">
			<NcCheckboxRadioSwitch
				v-model="selectedId"
				type="radio"
				name="summary-template"
				:value="null">
				{{ t('spreed', 'Default summary') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				v-for="template in templates"
				:key="template.id"
				v-model="selectedId"
				type="radio"
				name="summary-template"
				:value="template.id">
				{{ template.name }}
			</NcCheckboxRadioSwitch>
		</div>
		<template #actions>
			<NcButton :disabled="loading" variant="primary" @click="emit('close', selectedId)">
				{{ t('spreed', 'Start recording') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped lang="scss">
.recording-template-dialog {
	&__loading { margin: 32px auto; }
	&__options { display: flex; flex-direction: column; gap: 8px; padding: 8px 0; }
}
</style>
