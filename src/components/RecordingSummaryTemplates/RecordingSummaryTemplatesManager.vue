<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type {
	RecordingSummaryTemplate,
	RecordingSummaryTemplateInput,
} from '../../services/recordingSummaryTemplateService.ts'

import { getCurrentUser } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { useIsMobile } from '@nextcloud/vue/composables/useIsMobile'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { computed, nextTick, onMounted, ref } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import IconDomain from 'vue-material-design-icons/Domain.vue'
import IconMagnify from 'vue-material-design-icons/Magnify.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import IconStar from 'vue-material-design-icons/Star.vue'
import IconTextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import ConfirmDialog from '../UIShared/ConfirmDialog.vue'
import RecordingSummaryTemplateEditor from './RecordingSummaryTemplateEditor.vue'
import {
	createRecordingSummaryTemplate,
	deleteRecordingSummaryTemplate,
	getRecordingSummaryTemplates,
	setDefaultRecordingSummaryTemplate,
	updateRecordingSummaryTemplate,
} from '../../services/recordingSummaryTemplateService.ts'
import { getSectionsSummary, getSourceLabel, getTemplateIcon, SOURCE_ORDER } from './summaryTemplates.ts'

const props = defineProps<{
	/** Template to show first, the default template otherwise */
	templateId?: string
}>()

const emit = defineEmits<{ close: [] }>()

const isAdmin = getCurrentUser()?.isAdmin ?? false
const isMobile = useIsMobile()

const templates = ref<RecordingSummaryTemplate[]>([])
const loading = ref(true)
const loadError = ref(false)
const saving = ref(false)
const search = ref('')
const selectedId = ref<string | null>(null)
/** A new template is being created, personal or for the organization */
const creating = ref<'personal' | 'organization' | null>(null)
const dirty = ref(false)
const editor = ref<InstanceType<typeof RecordingSummaryTemplateEditor> | null>(null)
/** On narrow screens either the list or the editor is shown */
const showEditorOnMobile = ref(false)

const selectedTemplate = computed(() => templates.value.find(({ id }) => id === selectedId.value) ?? null)
const groups = computed(() => {
	const query = search.value.trim().toLocaleLowerCase()
	return SOURCE_ORDER.map((source) => ({
		source,
		label: getSourceLabel(source),
		templates: templates.value.filter((template) => template.source === source
			&& (query === '' || `${template.name} ${template.description}`.toLocaleLowerCase().includes(query))),
	})).filter(({ templates }) => templates.length > 0)
})
const showList = computed(() => !isMobile.value || !showEditorOnMobile.value)
const showEditor = computed(() => !isMobile.value || showEditorOnMobile.value)

onMounted(async () => {
	await load()
	const initial = templates.value.find(({ id }) => id === props.templateId)
		?? templates.value.find(({ isDefault }) => isDefault)
	selectedId.value = initial?.id ?? null
	showEditorOnMobile.value = props.templateId !== undefined
})

/** Load the templates. */
async function load() {
	loadError.value = false
	try {
		templates.value = (await getRecordingSummaryTemplates()).data.ocs.data
	} catch {
		loadError.value = true
	} finally {
		loading.value = false
	}
}

/** Ask whether unsaved changes may be discarded. */
async function confirmDiscard(): Promise<boolean> {
	if (!dirty.value) {
		return true
	}
	const confirmed = await spawnDialog(ConfirmDialog, {
		name: t('spreed', 'Discard changes?'),
		message: t('spreed', 'Your changes to this template have not been saved.'),
		buttons: [
			{ label: t('spreed', 'Keep editing') },
			{ label: t('spreed', 'Discard'), variant: 'error', callback: () => true },
		],
	})
	return confirmed === true
}

/**
 * Show a template in the editor.
 *
 * @param id Template ID
 */
async function select(id: string) {
	if (id === selectedId.value && creating.value === null) {
		showEditorOnMobile.value = true
		return
	}
	if (!await confirmDiscard()) {
		return
	}
	creating.value = null
	selectedId.value = id
	showEditorOnMobile.value = true
}

/**
 * Start a new template.
 *
 * @param scope Whether it is a personal or an organization template
 */
async function startNew(scope: 'personal' | 'organization') {
	if (!await confirmDiscard()) {
		return
	}
	creating.value = scope
	showEditorOnMobile.value = true
}

/** Leave the editor on narrow screens. */
async function backToList() {
	if (!await confirmDiscard()) {
		return
	}
	if (creating.value !== null) {
		creating.value = null
	} else {
		editor.value?.reset()
	}
	showEditorOnMobile.value = false
}

/**
 * Save the template in the editor.
 *
 * @param input Name, description and definition
 */
async function save(input: RecordingSummaryTemplateInput) {
	saving.value = true
	try {
		const saved = creating.value !== null
			? (await createRecordingSummaryTemplate(input, creating.value === 'organization')).data.ocs.data
			: (await updateRecordingSummaryTemplate(selectedId.value!, input)).data.ocs.data
		editor.value?.markSaved()
		dirty.value = false
		creating.value = null
		await load()
		selectedId.value = saved.id
		showSuccess(t('spreed', 'Template saved'))
	} catch {
		showError(t('spreed', 'Could not save the template'))
	} finally {
		saving.value = false
	}
}

/**
 * Save a personal copy of the template in the editor.
 *
 * @param input Name, description and definition
 */
async function duplicate(input: RecordingSummaryTemplateInput) {
	if (!await confirmDiscard()) {
		return
	}
	saving.value = true
	try {
		const copy = (await createRecordingSummaryTemplate({
			...input,
			name: t('spreed', '{name} (copy)', { name: selectedTemplate.value?.name ?? input.name }),
		})).data.ocs.data
		dirty.value = false
		await load()
		selectedId.value = copy.id
		await nextTick()
		showSuccess(t('spreed', 'Copy saved to your personal templates'))
	} catch {
		showError(t('spreed', 'Could not duplicate the template'))
	} finally {
		saving.value = false
	}
}

/** Delete the template in the editor after confirmation. */
async function remove() {
	const template = selectedTemplate.value
	if (!template) {
		return
	}
	const confirmed = await spawnDialog(ConfirmDialog, {
		name: t('spreed', 'Delete template?'),
		message: template.source === 'organization'
			? t('spreed', 'The template "{name}" will be removed for everyone in the organization. Recordings in progress keep using it.', { name: template.name })
			: t('spreed', 'The template "{name}" will be deleted. Recordings in progress keep using it.', { name: template.name }),
		buttons: [
			{ label: t('spreed', 'Cancel') },
			{ label: t('spreed', 'Delete'), variant: 'error', callback: () => true },
		],
	})
	if (confirmed !== true) {
		return
	}
	try {
		await deleteRecordingSummaryTemplate(template.id)
		dirty.value = false
		await load()
		selectedId.value = templates.value.find(({ isDefault }) => isDefault)?.id ?? null
		showEditorOnMobile.value = false
	} catch {
		showError(t('spreed', 'Could not delete the template'))
	}
}

/** Make the template in the editor the default template of the user. */
async function setDefault() {
	if (!selectedTemplate.value) {
		return
	}
	try {
		await setDefaultRecordingSummaryTemplate(selectedTemplate.value.id)
		templates.value = templates.value.map((template) => ({ ...template, isDefault: template.id === selectedId.value }))
	} catch {
		showError(t('spreed', 'Could not set the default template'))
	}
}

/**
 * Close the dialog unless there are unsaved changes to keep.
 *
 * @param open Whether the dialog is being opened
 */
async function close(open = false) {
	if (open || saving.value) {
		return
	}
	if (await confirmDiscard()) {
		emit('close')
	}
}
</script>

<template>
	<NcDialog
		:name="t('spreed', 'Summary templates')"
		size="large"
		:noClose="saving"
		@update:open="close">
		<div v-if="loading" class="templates-manager__loading">
			<NcLoadingIcon :size="32" />
		</div>
		<NcEmptyContent v-else-if="loadError" :name="t('spreed', 'Could not load the summary templates')">
			<template #action>
				<NcButton @click="load">
					{{ t('spreed', 'Retry') }}
				</NcButton>
			</template>
		</NcEmptyContent>
		<div v-else class="templates-manager">
			<nav v-if="showList" class="templates-manager__list" :aria-label="t('spreed', 'Summary templates')">
				<NcTextField
					v-model="search"
					:label="t('spreed', 'Search templates')"
					trailingButtonIcon="close"
					:showTrailingButton="search !== ''"
					@trailingButtonClick="search = ''">
					<template #icon>
						<IconMagnify :size="20" />
					</template>
				</NcTextField>

				<NcActions
					v-if="isAdmin"
					:menuName="t('spreed', 'New template')"
					variant="secondary"
					forceMenu>
					<template #icon>
						<IconPlus :size="20" />
					</template>
					<NcActionButton closeAfterClick @click="startNew('personal')">
						<template #icon>
							<IconTextBoxOutline :size="20" />
						</template>
						{{ t('spreed', 'Personal template') }}
					</NcActionButton>
					<NcActionButton closeAfterClick @click="startNew('organization')">
						<template #icon>
							<IconDomain :size="20" />
						</template>
						{{ t('spreed', 'Organization template') }}
					</NcActionButton>
				</NcActions>
				<NcButton
					v-else
					variant="secondary"
					wide
					@click="startNew('personal')">
					<template #icon>
						<IconPlus :size="20" />
					</template>
					{{ t('spreed', 'New template') }}
				</NcButton>

				<p v-if="groups.length === 0" class="templates-manager__hint">
					{{ t('spreed', 'No templates found') }}
				</p>
				<section v-for="group in groups" :key="group.source" class="templates-manager__group">
					<h3 class="templates-manager__caption">
						{{ group.label }}
					</h3>
					<ul>
						<NcListItem
							v-for="template in group.templates"
							:key="template.id"
							:name="template.name"
							:active="creating === null && template.id === selectedId"
							compact
							@click.prevent="select(template.id)">
							<template #icon>
								<component :is="getTemplateIcon(template)" :size="20" />
							</template>
							<template #subname>
								{{ getSectionsSummary(template) }}
							</template>
							<template v-if="template.isDefault" #indicator>
								<IconStar :size="16" :title="t('spreed', 'Your default')" fillColor="var(--color-warning)" />
							</template>
						</NcListItem>
					</ul>
				</section>
			</nav>

			<div v-if="showEditor" class="templates-manager__editor">
				<NcButton v-if="isMobile" variant="tertiary" @click="backToList">
					<template #icon>
						<IconArrowLeft :size="20" />
					</template>
					{{ t('spreed', 'All templates') }}
				</NcButton>
				<RecordingSummaryTemplateEditor
					v-if="creating !== null || selectedTemplate"
					ref="editor"
					:key="creating ?? selectedTemplate?.id"
					:template="creating !== null ? null : selectedTemplate"
					:organization="creating === 'organization'"
					:saving="saving"
					@update:dirty="dirty = $event"
					@save="save"
					@cancel="creating = null; showEditorOnMobile = false"
					@duplicate="duplicate"
					@delete="remove"
					@setDefault="setDefault" />
			</div>
		</div>
	</NcDialog>
</template>

<style scoped lang="scss">
.templates-manager {
	display: flex;
	gap: calc(4 * var(--default-grid-baseline));
	height: min(70vh, 720px);

	&__loading {
		display: flex;
		justify-content: center;
		padding: calc(8 * var(--default-grid-baseline));
	}

	&__list {
		display: flex;
		flex-direction: column;
		flex: 0 0 280px;
		gap: calc(2 * var(--default-grid-baseline));
		overflow-y: auto;
		padding-inline-end: var(--default-grid-baseline);
	}

	&__group ul {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}

	&__caption {
		margin: calc(2 * var(--default-grid-baseline)) 0 var(--default-grid-baseline);
		padding-inline-start: calc(2 * var(--default-grid-baseline));
		font-size: var(--default-font-size);
		font-weight: bold;
		color: var(--color-primary-element);
	}

	&__hint {
		color: var(--color-text-maxcontrast);
	}

	&__editor {
		flex: 1;
		min-width: 0;
		overflow-y: auto;
		padding-inline: var(--default-grid-baseline);
	}
}

@media (max-width: 768px) {
	.templates-manager {
		height: auto;

		&__list {
			flex-basis: auto;
			width: 100%;
		}
	}
}
</style>
