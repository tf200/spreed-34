<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { RecordingSummaryTemplateDefinition } from '../../services/recordingSummaryTemplateService.ts'

import { t } from '@nextcloud/l10n'
import { isAxiosError } from 'axios'
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcRichText from '@nextcloud/vue/components/NcRichText'
import IconAlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import { previewRecordingSummaryTemplate } from '../../services/recordingSummaryTemplateService.ts'

const props = defineProps<{
	name: string
	definition: RecordingSummaryTemplateDefinition
}>()

const emit = defineEmits<{ close: [] }>()

const loading = ref(true)
const error = ref('')
const summary = ref('')
const transcript = ref('')
const showTranscript = ref(false)

onMounted(load)

/** Summarize the sample meeting. */
async function load() {
	loading.value = true
	error.value = ''
	try {
		const response = await previewRecordingSummaryTemplate(props.definition)
		summary.value = response.data.ocs.data.summary
		transcript.value = response.data.ocs.data.transcript
	} catch (exception) {
		error.value = isAxiosError(exception) && exception.response?.status === 429
			? t('spreed', 'Too many previews. Please try again later.')
			: t('spreed', 'The preview could not be generated. Please try again later.')
	} finally {
		loading.value = false
	}
}
</script>

<template>
	<NcDialog
		:name="t('spreed', 'Preview of {name}', { name })"
		size="normal"
		@closing="emit('close')">
		<div class="template-preview">
			<p class="template-preview__hint">
				{{ t('spreed', 'This is how the template summarizes a short sample meeting about a website relaunch.') }}
			</p>
			<NcEmptyContent v-if="loading" :name="t('spreed', 'Summarizing the sample meeting …')">
				<template #icon>
					<NcLoadingIcon />
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="error" :name="error">
				<template #icon>
					<IconAlertCircleOutline />
				</template>
				<template #action>
					<NcButton @click="load">
						{{ t('spreed', 'Retry') }}
					</NcButton>
				</template>
			</NcEmptyContent>
			<template v-else>
				<NcRichText class="template-preview__summary" :text="summary" useExtendedMarkdown />
				<NcButton variant="tertiary" @click="showTranscript = !showTranscript">
					{{ showTranscript ? t('spreed', 'Hide sample transcript') : t('spreed', 'Show sample transcript') }}
				</NcButton>
				<NcRichText
					v-if="showTranscript"
					class="template-preview__transcript"
					:text="transcript"
					useMarkdown />
			</template>
		</div>
	</NcDialog>
</template>

<style scoped lang="scss">
.template-preview {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	padding-block-end: calc(2 * var(--default-grid-baseline));

	&__hint {
		color: var(--color-text-maxcontrast);
	}

	&__summary,
	&__transcript {
		padding: calc(2 * var(--default-grid-baseline));
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
	}

	&__transcript {
		color: var(--color-text-maxcontrast);
	}
}
</style>
