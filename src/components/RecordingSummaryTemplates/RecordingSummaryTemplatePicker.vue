<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { RecordingSummaryTemplate } from '../../services/recordingSummaryTemplateService.ts'

import { t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconCog from 'vue-material-design-icons/CogOutline.vue'
import IconMagnify from 'vue-material-design-icons/Magnify.vue'
import IconStar from 'vue-material-design-icons/Star.vue'
import RecordingSummaryTemplatesManager from './RecordingSummaryTemplatesManager.vue'
import { getRecordingSummaryTemplates } from '../../services/recordingSummaryTemplateService.ts'
import { getSectionsSummary, getSourceLabel, getTemplateIcon, SOURCE_ORDER } from './summaryTemplates.ts'

export type RecordingSummaryTemplatePickerResult = {
	templateId: string
	/** Whether to use the template for the recordings of the conversation */
	rememberForConversation: boolean
}

const props = withDefaults(defineProps<{
	name?: string
	description?: string
	confirmLabel?: string
	/** Template to select first, the default template otherwise */
	currentId?: string | null
	/** Whether the template can be remembered for the conversation */
	canRememberForConversation?: boolean
}>(), {
	name: undefined,
	description: undefined,
	confirmLabel: undefined,
	currentId: null,
	canRememberForConversation: false,
})

const emit = defineEmits<{ close: [RecordingSummaryTemplatePickerResult | undefined] }>()

const SEARCH_THRESHOLD = 9

const templates = ref<RecordingSummaryTemplate[]>([])
const loading = ref(true)
const loadError = ref(false)
const selectedId = ref<string | null>(null)
const rememberForConversation = ref(false)
const search = ref('')

const groups = computed(() => {
	const query = search.value.trim().toLocaleLowerCase()
	return SOURCE_ORDER.map((source) => ({
		source,
		label: getSourceLabel(source),
		templates: templates.value.filter((template) => template.source === source
			&& (query === '' || `${template.name} ${template.description}`.toLocaleLowerCase().includes(query))),
	})).filter(({ templates }) => templates.length > 0)
})

onMounted(async () => {
	await load()
	selectedId.value = templates.value.find(({ id }) => id === props.currentId)?.id
		?? templates.value.find(({ isDefault }) => isDefault)?.id
		?? null
})

/** Load the templates. */
async function load() {
	loading.value = true
	loadError.value = false
	try {
		templates.value = (await getRecordingSummaryTemplates()).data.ocs.data
		if (!templates.value.some(({ id }) => id === selectedId.value)) {
			selectedId.value = templates.value.find(({ isDefault }) => isDefault)?.id ?? null
		}
	} catch {
		loadError.value = true
	} finally {
		loading.value = false
	}
}

/** Open the template manager, and show its changes afterwards. */
async function manage() {
	await spawnDialog(RecordingSummaryTemplatesManager, { templateId: selectedId.value ?? undefined })
	await load()
}

/** Confirm the selected template. */
function confirm() {
	if (selectedId.value) {
		emit('close', { templateId: selectedId.value, rememberForConversation: rememberForConversation.value })
	}
}
</script>

<template>
	<NcDialog
		:name="name ?? t('spreed', 'Choose a summary template')"
		size="normal"
		@closing="emit('close', undefined)">
		<p v-if="description" class="template-picker__description">
			{{ description }}
		</p>
		<div v-if="loading" class="template-picker__loading">
			<NcLoadingIcon :size="32" />
		</div>
		<NcEmptyContent v-else-if="loadError" :name="t('spreed', 'Could not load the summary templates')">
			<template #action>
				<NcButton @click="load">
					{{ t('spreed', 'Retry') }}
				</NcButton>
			</template>
		</NcEmptyContent>
		<template v-else>
			<NcTextField
				v-if="templates.length >= SEARCH_THRESHOLD"
				v-model="search"
				class="template-picker__search"
				:label="t('spreed', 'Search templates')">
				<template #icon>
					<IconMagnify :size="20" />
				</template>
			</NcTextField>
			<div
				v-for="group in groups"
				:key="group.source"
				class="template-picker__group"
				role="radiogroup"
				:aria-label="group.label">
				<h3 class="template-picker__caption">
					{{ group.label }}
				</h3>
				<div class="template-picker__grid">
					<button
						v-for="template in group.templates"
						:key="template.id"
						type="button"
						role="radio"
						class="template-picker__card"
						:class="{ 'template-picker__card--selected': template.id === selectedId }"
						:aria-checked="template.id === selectedId ? 'true' : 'false'"
						@click="selectedId = template.id"
						@dblclick="selectedId = template.id; confirm()">
						<span class="template-picker__card-header">
							<component :is="getTemplateIcon(template)" :size="20" />
							<span class="template-picker__card-name">{{ template.name }}</span>
							<IconStar
								v-if="template.isDefault"
								:size="16"
								:title="t('spreed', 'Your default')"
								fillColor="var(--color-warning)" />
						</span>
						<span class="template-picker__card-summary">{{ getSectionsSummary(template) }}</span>
					</button>
				</div>
			</div>
		</template>

		<template #actions>
			<NcCheckboxRadioSwitch
				v-if="canRememberForConversation"
				v-model="rememberForConversation"
				class="template-picker__remember">
				{{ t('spreed', 'Use for this conversation from now on') }}
			</NcCheckboxRadioSwitch>
			<NcButton variant="tertiary" @click="manage">
				<template #icon>
					<IconCog :size="20" />
				</template>
				{{ t('spreed', 'Manage templates') }}
			</NcButton>
			<NcButton variant="primary" :disabled="loading || !selectedId" @click="confirm">
				{{ confirmLabel ?? t('spreed', 'Choose') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped lang="scss">
.template-picker {
	&__description {
		margin-block-end: calc(2 * var(--default-grid-baseline));
		color: var(--color-text-maxcontrast);
	}

	&__loading {
		display: flex;
		justify-content: center;
		padding: calc(8 * var(--default-grid-baseline));
	}

	&__search {
		margin-block-end: calc(2 * var(--default-grid-baseline));
	}

	&__caption {
		margin: calc(2 * var(--default-grid-baseline)) 0 var(--default-grid-baseline);
		font-size: var(--default-font-size);
		font-weight: bold;
		color: var(--color-primary-element);
	}

	&__grid {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
		gap: calc(2 * var(--default-grid-baseline));
	}

	&__card {
		display: flex;
		flex-direction: column;
		align-items: stretch;
		gap: var(--default-grid-baseline);
		min-height: 0;
		height: auto;
		margin: 0;
		padding: calc(2 * var(--default-grid-baseline)) calc(3 * var(--default-grid-baseline));
		border: 2px solid var(--color-border);
		border-radius: var(--border-radius-large);
		background-color: var(--color-main-background);
		color: var(--color-main-text);
		font-weight: normal;
		text-align: start;
		white-space: normal;
		cursor: pointer;

		&:hover {
			background-color: var(--color-background-hover);
		}

		&:focus-visible {
			outline: 2px solid var(--color-main-text);
			outline-offset: 2px;
		}

		&--selected {
			border-color: var(--color-primary-element);
			background-color: var(--color-primary-element-light);

			&:hover {
				background-color: var(--color-primary-element-light-hover);
			}
		}
	}

	&__card-header {
		display: flex;
		align-items: center;
		gap: var(--default-grid-baseline);
	}

	&__card-name {
		flex: 1;
		font-weight: bold;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	&__card-summary {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
		display: -webkit-box;
		-webkit-line-clamp: 2;
		-webkit-box-orient: vertical;
		overflow: hidden;
	}

	&__remember {
		margin-inline-end: auto;
	}
}
</style>
