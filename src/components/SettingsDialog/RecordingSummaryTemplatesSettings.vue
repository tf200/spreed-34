<!-- SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup lang="ts">
import type { RecordingSummaryTemplate } from '../../services/recordingSummaryTemplateService.ts'

import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconDelete from 'vue-material-design-icons/DeleteOutline.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import { createRecordingSummaryTemplate, deleteRecordingSummaryTemplate, getRecordingSummaryTemplates, updateRecordingSummaryTemplate } from '../../services/recordingSummaryTemplateService.ts'

const templates = ref<RecordingSummaryTemplate[]>([])
const loading = ref(true)
const saving = ref(false)
const formOpen = ref(false)
const editingId = ref<string | null>(null)
const name = ref('')
const instructions = ref('')

onMounted(load)

/** Load the current user's templates. */
async function load() {
	try {
		templates.value = (await getRecordingSummaryTemplates()).data.ocs.data
	} catch {
		showError(t('spreed', 'Could not load recording summary templates'))
	} finally {
		loading.value = false
	}
}
/**
 * Populate or reset the template editor.
 *
 * @param template Template to edit, if any
 */
function edit(template?: RecordingSummaryTemplate) {
	formOpen.value = true
	editingId.value = template?.id ?? null
	name.value = template?.name ?? ''
	instructions.value = template?.instructions ?? ''
}

/**
 * Close and reset the template form.
 */
function cancelEditing() {
	formOpen.value = false
	editingId.value = null
	name.value = ''
	instructions.value = ''
}
/** Save the template currently in the editor. */
async function save() {
	if (!name.value.trim() || !instructions.value.trim()) {
		return
	}
	saving.value = true
	try {
		if (editingId.value) {
			await updateRecordingSummaryTemplate(editingId.value, name.value.trim(), instructions.value.trim())
		} else {
			await createRecordingSummaryTemplate(name.value.trim(), instructions.value.trim())
		}
		cancelEditing()
		await load()
	} catch {
		showError(t('spreed', 'Could not save recording summary template'))
	} finally {
		saving.value = false
	}
}
/**
 * Delete a template from the server and local list.
 *
 * @param template Template to delete
 */
async function remove(template: RecordingSummaryTemplate) {
	try {
		await deleteRecordingSummaryTemplate(template.id)
		templates.value = templates.value.filter(({ id }) => id !== template.id)
	} catch {
		showError(t('spreed', 'Could not delete recording summary template'))
	}
}
</script>

<template>
	<NcLoadingIcon v-if="loading" />
	<div v-else class="summary-templates">
		<p>{{ t('spreed', 'Create private instructions used to generate summaries of your recordings.') }}</p>
		<div v-for="template in templates" :key="template.id" class="summary-templates__item">
			<NcButton variant="tertiary" wide @click="edit(template)">
				{{ template.name }}
			</NcButton>
			<NcButton :aria-label="t('spreed', 'Delete {name}', { name: template.name })" variant="tertiary" @click="remove(template)">
				<template #icon>
					<IconDelete :size="20" />
				</template>
			</NcButton>
		</div>
		<NcButton v-if="!formOpen" variant="secondary" @click="edit()">
			<template #icon>
				<IconPlus :size="20" />
			</template>{{ t('spreed', 'Add template') }}
		</NcButton>
		<form v-else class="summary-templates__form" @submit.prevent="save">
			<NcTextField
				v-model="name"
				:label="t('spreed', 'Template name')"
				:maxlength="250"
				required />
			<NcTextArea
				v-model="instructions"
				:label="t('spreed', 'Summary instructions')"
				:maxlength="10000"
				required />
			<div class="summary-templates__actions">
				<NcButton @click="cancelEditing">
					{{ t('spreed', 'Cancel') }}
				</NcButton>
				<NcButton type="submit" variant="primary" :disabled="saving || !name.trim() || !instructions.trim()">
					{{ t('spreed', 'Save') }}
				</NcButton>
			</div>
		</form>
	</div>
</template>

<style scoped lang="scss">
.summary-templates {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.summary-templates__item {
	display: flex;
	align-items: center;
}

.summary-templates__form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.summary-templates__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
