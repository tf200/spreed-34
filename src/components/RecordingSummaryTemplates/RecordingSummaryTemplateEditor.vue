<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type {
	RecordingSummaryTemplate,
	RecordingSummaryTemplateDefinition,
	RecordingSummaryTemplateInput,
} from '../../services/recordingSummaryTemplateService.ts'

import { t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcFormBox from '@nextcloud/vue/components/NcFormBox'
import NcFormBoxSwitch from '@nextcloud/vue/components/NcFormBoxSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import IconArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import IconChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import IconChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import IconContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import IconDeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import IconEyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import IconStar from 'vue-material-design-icons/Star.vue'
import IconStarOutline from 'vue-material-design-icons/StarOutline.vue'
import RecordingSummaryTemplatePreviewDialog from './RecordingSummaryTemplatePreviewDialog.vue'
import { getEmptyDefinition, getLanguageOptions } from './summaryTemplates.ts'

const props = defineProps<{
	/** The template to show, or null for a new one */
	template: RecordingSummaryTemplate | null
	/** Whether a new template is an organization template */
	organization?: boolean
	saving?: boolean
}>()
const emit = defineEmits<{
	save: [RecordingSummaryTemplateInput]
	cancel: []
	duplicate: [RecordingSummaryTemplateInput]
	delete: []
	setDefault: []
	'update:dirty': [boolean]
}>()
// Keep in sync with SummaryTemplateDefinition and RecordingSummaryTemplateService.
const MAX_SECTIONS = 12
const MAX_NAME_LENGTH = 250
const MAX_DESCRIPTION_LENGTH = 500
const MAX_SECTION_TITLE_LENGTH = 100
const MAX_SECTION_DESCRIPTION_LENGTH = 500
const MAX_EXTRA_INSTRUCTIONS_LENGTH = 4000

const name = ref('')
const description = ref('')
const definition = ref<RecordingSummaryTemplateDefinition>(getEmptyDefinition())
const extraOpen = ref(false)
const initialState = ref('')

const isNew = computed(() => props.template === null)
const readOnly = computed(() => props.template !== null && !props.template.canEdit)
const languageOptions = computed(() => [
	{ id: '', label: t('spreed', 'Same as the meeting') },
	...getLanguageOptions(),
])
const selectedLanguage = computed({
	get: () => languageOptions.value.find(({ id }) => id === definition.value.language) ?? languageOptions.value[0],
	set: (option) => {
		definition.value.language = option?.id ?? ''
	},
})

const currentState = computed(() => JSON.stringify({ name: name.value, description: description.value, definition: definition.value }))
const isDirty = computed(() => !readOnly.value && currentState.value !== initialState.value)
const hasContent = computed(() => definition.value.sections.some(({ title }) => title.trim() !== '')
	|| definition.value.extraInstructions.trim() !== '')
const canSave = computed(() => !readOnly.value && name.value.trim() !== '' && hasContent.value && !props.saving)
const heading = computed(() => {
	if (isNew.value) {
		return props.organization ? t('spreed', 'New organization template') : t('spreed', 'New template')
	}
	return props.template!.name
})

watch(() => props.template, reset, { immediate: true })
watch(isDirty, (dirty) => emit('update:dirty', dirty), { immediate: true })

/** Load the shown template into the form. */
function reset() {
	name.value = props.template?.name ?? ''
	description.value = props.template?.description ?? ''
	definition.value = props.template
		? JSON.parse(JSON.stringify(props.template.definition)) as RecordingSummaryTemplateDefinition
		: getEmptyDefinition()
	extraOpen.value = definition.value.extraInstructions !== ''
	initialState.value = currentState.value
}

/** The form content with empty sections left out. */
function getInput(): RecordingSummaryTemplateInput {
	return {
		name: name.value.trim(),
		description: description.value.trim(),
		definition: {
			...definition.value,
			sections: definition.value.sections
				.map(({ title, description }) => ({ title: title.trim(), description: description.trim() }))
				.filter(({ title }) => title !== ''),
			extraInstructions: definition.value.extraInstructions.trim(),
		},
	}
}

/** Add an empty section at the end and focus it. */
function addSection() {
	definition.value.sections.push({ title: '', description: '' })
}

/**
 * Move a section up or down.
 *
 * @param index Index of the section
 * @param offset -1 to move up, 1 to move down
 */
function moveSection(index: number, offset: number) {
	const sections = definition.value.sections
	const [section] = sections.splice(index, 1)
	sections.splice(index + offset, 0, section!)
}

/**
 * Remove a section.
 *
 * @param index Index of the section
 */
function removeSection(index: number) {
	definition.value.sections.splice(index, 1)
}

/** Save the form. */
function save() {
	if (canSave.value) {
		emit('save', getInput())
	}
}

/** Summarize a sample meeting with the current form content. */
function preview() {
	spawnDialog(RecordingSummaryTemplatePreviewDialog, {
		name: name.value.trim() || heading.value,
		definition: getInput().definition,
	})
}

/** Mark the current content as saved. */
function markSaved() {
	initialState.value = currentState.value
}

defineExpose({ markSaved, reset })
</script>

<template>
	<form class="template-editor" @submit.prevent="save">
		<div class="template-editor__header">
			<h3 class="template-editor__heading">
				{{ heading }}
			</h3>
			<NcButton
				v-if="template"
				variant="tertiary"
				:pressed="template.isDefault"
				:disabled="template.isDefault"
				@click="emit('setDefault')">
				<template #icon>
					<IconStar v-if="template.isDefault" :size="20" />
					<IconStarOutline v-else :size="20" />
				</template>
				{{ template.isDefault ? t('spreed', 'Your default') : t('spreed', 'Set as default') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="readOnly" type="info">
			{{ template?.source === 'builtin'
				? t('spreed', 'Built-in templates can not be changed. Duplicate it to make your own version.')
				: t('spreed', 'Only administrators can change organization templates. Duplicate it to make your own version.') }}
		</NcNoteCard>

		<fieldset class="template-editor__fields" :disabled="readOnly">
			<NcTextField
				v-model="name"
				:label="t('spreed', 'Name')"
				:maxlength="MAX_NAME_LENGTH"
				required />
			<NcTextField
				v-model="description"
				:label="t('spreed', 'Description (optional)')"
				:helperText="t('spreed', 'Shown when choosing a template')"
				:maxlength="MAX_DESCRIPTION_LENGTH" />

			<section class="template-editor__section">
				<h4 class="template-editor__subheading">
					{{ t('spreed', 'Sections') }}
				</h4>
				<p class="template-editor__hint">
					{{ t('spreed', 'The summary has a heading for each section, in this order. Describe what each section should contain.') }}
				</p>
				<ol class="template-editor__sections">
					<li
						v-for="(section, index) in definition.sections"
						:key="index"
						class="template-editor__section-row">
						<span class="template-editor__section-number" aria-hidden="true">{{ index + 1 }}</span>
						<div class="template-editor__section-fields">
							<NcTextField
								v-model="section.title"
								:label="t('spreed', 'Section title')"
								:maxlength="MAX_SECTION_TITLE_LENGTH" />
							<NcTextField
								v-model="section.description"
								:label="t('spreed', 'What to include (optional)')"
								:maxlength="MAX_SECTION_DESCRIPTION_LENGTH" />
						</div>
						<div v-if="!readOnly" class="template-editor__section-actions">
							<NcButton
								variant="tertiary"
								:aria-label="t('spreed', 'Move section {title} up', { title: section.title })"
								:title="t('spreed', 'Move up')"
								:disabled="index === 0"
								@click="moveSection(index, -1)">
								<template #icon>
									<IconArrowUp :size="20" />
								</template>
							</NcButton>
							<NcButton
								variant="tertiary"
								:aria-label="t('spreed', 'Move section {title} down', { title: section.title })"
								:title="t('spreed', 'Move down')"
								:disabled="index === definition.sections.length - 1"
								@click="moveSection(index, 1)">
								<template #icon>
									<IconArrowDown :size="20" />
								</template>
							</NcButton>
							<NcButton
								variant="tertiary"
								:aria-label="t('spreed', 'Remove section {title}', { title: section.title })"
								:title="t('spreed', 'Remove')"
								@click="removeSection(index)">
								<template #icon>
									<IconDeleteOutline :size="20" />
								</template>
							</NcButton>
						</div>
					</li>
				</ol>
				<NcButton
					v-if="!readOnly"
					variant="secondary"
					:disabled="definition.sections.length >= MAX_SECTIONS"
					@click="addSection">
					<template #icon>
						<IconPlus :size="20" />
					</template>
					{{ t('spreed', 'Add section') }}
				</NcButton>
			</section>

			<section class="template-editor__section">
				<h4 class="template-editor__subheading">
					{{ t('spreed', 'Options') }}
				</h4>
				<NcRadioGroup v-model="definition.length" :label="t('spreed', 'Length')">
					<NcRadioGroupButton :label="t('spreed', 'Brief')" value="brief" />
					<NcRadioGroupButton :label="t('spreed', 'Standard')" value="standard" />
					<NcRadioGroupButton :label="t('spreed', 'Detailed')" value="detailed" />
				</NcRadioGroup>
				<NcRadioGroup v-model="definition.style" :label="t('spreed', 'Style')">
					<NcRadioGroupButton :label="t('spreed', 'Bullet points')" value="bullets" />
					<NcRadioGroupButton :label="t('spreed', 'Paragraphs')" value="paragraphs" />
				</NcRadioGroup>
				<NcSelect
					v-model="selectedLanguage"
					class="template-editor__language"
					:inputLabel="t('spreed', 'Language of the summary')"
					:options="languageOptions"
					:clearable="false"
					:disabled="readOnly"
					label="label" />
				<NcFormBox>
					<NcFormBoxSwitch
						v-model="definition.actionItemsTable"
						:label="t('spreed', 'Action items as a table')"
						:description="t('spreed', 'With columns for the task, the owner and the due date')"
						:disabled="readOnly" />
					<NcFormBoxSwitch
						v-model="definition.transcriptLinks"
						:label="t('spreed', 'Refer to the transcript')"
						:description="t('spreed', 'Adds the time in the call to each point, like [12:34], to look it up in the transcript')"
						:disabled="readOnly" />
				</NcFormBox>
			</section>

			<section class="template-editor__section">
				<NcButton
					variant="tertiary"
					alignment="start"
					:aria-expanded="extraOpen ? 'true' : 'false'"
					@click="extraOpen = !extraOpen">
					<template #icon>
						<IconChevronDown v-if="extraOpen" :size="20" />
						<IconChevronRight v-else :size="20" />
					</template>
					{{ t('spreed', 'Extra instructions') }}
				</NcButton>
				<template v-if="extraOpen">
					<p class="template-editor__hint">
						{{ t('spreed', 'Anything else the summary should follow, for example the terms your team uses or what to leave out.') }}
					</p>
					<NcTextArea
						v-model="definition.extraInstructions"
						:label="t('spreed', 'Extra instructions')"
						:maxlength="MAX_EXTRA_INSTRUCTIONS_LENGTH"
						resize="vertical"
						rows="4" />
				</template>
			</section>
		</fieldset>

		<p v-if="!readOnly && !hasContent" class="template-editor__hint">
			{{ t('spreed', 'Add at least one section or extra instructions.') }}
		</p>

		<div class="template-editor__actions">
			<NcButton :disabled="!hasContent" @click="preview">
				<template #icon>
					<IconEyeOutline :size="20" />
				</template>
				{{ t('spreed', 'Preview') }}
			</NcButton>
			<NcButton v-if="template" :variant="readOnly ? 'primary' : 'secondary'" @click="emit('duplicate', getInput())">
				<template #icon>
					<IconContentCopy :size="20" />
				</template>
				{{ t('spreed', 'Duplicate') }}
			</NcButton>
			<span class="template-editor__spacer" />
			<NcButton v-if="template && !readOnly" variant="error" @click="emit('delete')">
				<template #icon>
					<IconDeleteOutline :size="20" />
				</template>
				{{ t('spreed', 'Delete') }}
			</NcButton>
			<NcButton v-if="isNew" @click="emit('cancel')">
				{{ t('spreed', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!readOnly"
				type="submit"
				variant="primary"
				:disabled="!canSave || (!isNew && !isDirty)">
				{{ t('spreed', 'Save') }}
			</NcButton>
		</div>
	</form>
</template>

<style scoped lang="scss">
.template-editor {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));

	&__header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: var(--default-grid-baseline);
	}

	&__heading {
		margin: 0;
		font-size: var(--default-font-size);
		font-weight: bold;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	&__fields {
		display: flex;
		flex-direction: column;
		gap: calc(2 * var(--default-grid-baseline));
		min-width: 0;
		margin: 0;
		padding: 0;
		border: none;
	}

	&__section {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: calc(2 * var(--default-grid-baseline));
		padding-block-start: calc(2 * var(--default-grid-baseline));
		border-block-start: 1px solid var(--color-border);

		> * {
			align-self: stretch;
		}

		> .button-vue {
			align-self: flex-start;
		}
	}

	&__subheading {
		margin: 0;
		font-weight: bold;
	}

	&__hint {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__sections {
		display: flex;
		flex-direction: column;
		gap: calc(2 * var(--default-grid-baseline));
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__section-row {
		display: flex;
		align-items: flex-start;
		gap: var(--default-grid-baseline);
	}

	&__section-number {
		flex-shrink: 0;
		width: var(--default-clickable-area);
		line-height: var(--default-clickable-area);
		text-align: center;
		color: var(--color-text-maxcontrast);
	}

	&__section-fields {
		display: flex;
		flex: 1;
		flex-direction: column;
		gap: var(--default-grid-baseline);
		min-width: 0;
	}

	&__section-actions {
		display: flex;
		flex-shrink: 0;
	}

	&__language {
		width: 100%;
	}

	&__actions {
		position: sticky;
		inset-block-end: 0;
		display: flex;
		flex-wrap: wrap;
		gap: var(--default-grid-baseline);
		padding-block: calc(2 * var(--default-grid-baseline));
		background-color: var(--color-main-background);
	}

	&__spacer {
		flex: 1;
	}
}
</style>
