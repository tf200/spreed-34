<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { RecordingArtifact } from '../services/recordingArtifactService.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { isAxiosError } from 'axios'
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import ConfirmDialog from './UIShared/ConfirmDialog.vue'
import {
	getRecordingArtifact,
	publishRecordingArtifact,
	updateRecordingArtifact,
} from '../services/recordingArtifactService.ts'

const props = withDefaults(defineProps<{
	token: string
	artifactId: string
	notificationTimestamp: number
	canPublish?: boolean
}>(), {
	canPublish: true,
})

const emit = defineEmits<{
	close: []
}>()

const artifact = ref<RecordingArtifact | null>(null)
const content = ref('')
const savedContent = ref('')
const isLoading = ref(true)
const isSaving = ref(false)
const isPublishing = ref(false)
const hasConflict = ref(false)
const loadError = ref(false)
const operationError = ref<'save' | 'publish' | null>(null)
const conflictReason = ref<'stale_revision' | 'editing' | 'publishing' | 'published' | null>(null)
const editorElement = ref<HTMLElement | null>(null)
const textEditorAvailable = ref(!!window.OCA.Text?.createEditor)
let textEditor: Awaited<ReturnType<NonNullable<typeof window.OCA.Text>['createEditor']>> | null = null
let editorReadOnly = false
let loadRequest = 0

const isBusy = computed(() => isLoading.value || isSaving.value || isPublishing.value)
const isDirty = computed(() => content.value !== savedContent.value)
const isPublished = computed(() => artifact.value?.state === 'published')
const isDraft = computed(() => artifact.value?.state === 'draft')
const title = computed(() => artifact.value?.type === 'summary'
	? t('spreed', 'Review call summary')
	: t('spreed', 'Review transcript'))

onMounted(loadArtifact)
onBeforeUnmount(destroyTextEditor)

/** Destroy the editor before its host element is removed. */
function destroyTextEditor() {
	textEditor?.destroy()
	textEditor = null
}

/** Load the latest artifact draft. */
async function loadArtifact() {
	const request = ++loadRequest
	destroyTextEditor()
	isLoading.value = true
	loadError.value = false
	try {
		const response = await getRecordingArtifact(props.token, props.artifactId)
		if (request !== loadRequest) {
			return
		}
		artifact.value = response.data.ocs.data
		content.value = response.data.ocs.data.content
		savedContent.value = response.data.ocs.data.content
		hasConflict.value = false
		conflictReason.value = null
		operationError.value = null
	} catch (error) {
		if (request !== loadRequest) {
			return
		}
		console.error(error)
		loadError.value = true
	} finally {
		if (request === loadRequest) {
			isLoading.value = false
		}
	}
	if (request !== loadRequest || loadError.value) {
		return
	}
	await nextTick()
	if (request !== loadRequest) {
		return
	}
	await setupTextEditor()
}

/** Create the native Nextcloud Text editor, or refresh it after a reload. */
async function setupTextEditor() {
	if (!textEditorAvailable.value || !editorElement.value || !artifact.value) {
		return
	}
	const readOnly = !isDraft.value
	if (textEditor && editorReadOnly === readOnly) {
		textEditor.setContent(content.value)
		return
	}
	textEditor?.destroy()
	textEditor = null
	const element = editorElement.value
	try {
		const editor = await window.OCA.Text!.createEditor({
			el: element,
			content: content.value,
			readOnly,
			placeholder: t('spreed', 'Review and correct the recording text'),
			onUpdate: ({ markdown }) => {
				content.value = markdown
			},
		})
		if (editorElement.value !== element) {
			editor.destroy()
			return
		}
		textEditor = editor
		editorReadOnly = readOnly
	} catch (error) {
		if (editorElement.value !== element) {
			return
		}
		console.error('Could not initialize the Nextcloud Text editor', error)
		textEditorAvailable.value = false
	}
}

/** Save the current draft with optimistic concurrency control. */
async function save() {
	if (!artifact.value || !isDraft.value || !isDirty.value || isBusy.value || hasConflict.value) {
		return
	}
	isSaving.value = true
	operationError.value = null
	try {
		const response = await updateRecordingArtifact(props.token, props.artifactId, content.value, artifact.value.etag)
		artifact.value = response.data.ocs.data
		savedContent.value = response.data.ocs.data.content
		showSuccess(t('spreed', 'Recording text saved'))
	} catch (error: unknown) {
		if (setConflict(error)) {
			showError(conflictMessage.value)
		} else {
			operationError.value = 'save'
			showError(t('spreed', 'Could not save the recording text'))
		}
	} finally {
		isSaving.value = false
	}
}

/** Save pending changes and publish a detached snapshot to chat. */
async function publish() {
	if (!artifact.value || !isDraft.value || !props.canPublish || isBusy.value || hasConflict.value) {
		return
	}
	if (isDirty.value) {
		await save()
		if (isDirty.value || hasConflict.value) {
			return
		}
	}

	const confirmed = await spawnDialog(ConfirmDialog, {
		name: t('spreed', 'Publish reviewed text?'),
		message: t('spreed', 'A snapshot will be shared with everyone in this conversation. Later draft edits will not change it.'),
		buttons: [
			{ label: t('spreed', 'Cancel') },
			{ label: t('spreed', 'Publish to chat'), variant: 'primary', callback: () => true },
		],
	})
	if (!confirmed || !artifact.value) {
		return
	}

	isPublishing.value = true
	operationError.value = null
	try {
		const response = await publishRecordingArtifact(props.token, props.artifactId, artifact.value.etag, props.notificationTimestamp)
		artifact.value = response.data.ocs.data
		content.value = response.data.ocs.data.content
		savedContent.value = response.data.ocs.data.content
		await nextTick()
		await setupTextEditor()
		showSuccess(t('spreed', 'Reviewed text published to the conversation'))
	} catch (error: unknown) {
		if (setConflict(error)) {
			showError(conflictMessage.value)
		} else {
			operationError.value = 'publish'
			showError(t('spreed', 'Could not publish the recording text'))
		}
	} finally {
		isPublishing.value = false
	}
}

const conflictMessage = computed(() => {
	if (conflictReason.value === 'editing') {
		return t('spreed', 'This recording text is currently being edited. Try again after reloading.')
	}
	if (conflictReason.value === 'publishing') {
		return t('spreed', 'This recording text is currently being published. Reload to check its status.')
	}
	if (conflictReason.value === 'published') {
		return t('spreed', 'This recording text has already been published. Reload the published version.')
	}
	return t('spreed', 'This text changed elsewhere. Reload it before continuing.')
})

/**
 * Record a structured conflict from an API response.
 *
 * @param error API error
 */
function setConflict(error: unknown) {
	if (!isAxiosError(error) || error.response?.status !== 409) {
		return false
	}
	const reason = error.response.data?.ocs?.data?.error ?? error.response.data?.error
	conflictReason.value = ['stale_revision', 'editing', 'publishing', 'published'].includes(reason)
		? reason
		: null
	hasConflict.value = true
	return true
}

/** Confirm before replacing potentially unsaved local content. */
async function reloadAfterConflict() {
	if (isDirty.value) {
		const confirmed = await spawnDialog(ConfirmDialog, {
			name: t('spreed', 'Reload the latest version?'),
			message: t('spreed', 'Your unsaved corrections will be replaced. You can cancel to keep them in the editor.'),
			buttons: [
				{ label: t('spreed', 'Keep editing') },
				{ label: t('spreed', 'Reload latest'), variant: 'primary', callback: () => true },
			],
		})
		if (!confirmed) {
			return
		}
	}
	await loadArtifact()
}

/**
 * Confirm before closing a dirty editor.
 *
 * @param open Whether the dialog is being opened
 */
async function close(open = false) {
	if (open) {
		return
	}
	if (isBusy.value) {
		return
	}
	if (isDirty.value) {
		const confirmed = await spawnDialog(ConfirmDialog, {
			name: t('spreed', 'Discard unsaved changes?'),
			message: t('spreed', 'Your unsaved corrections will be lost.'),
			buttons: [
				{ label: t('spreed', 'Keep editing') },
				{ label: t('spreed', 'Discard changes'), variant: 'error', callback: () => true },
			],
		})
		if (!confirmed) {
			return
		}
	}
	emit('close')
}
</script>

<template>
	<NcDialog
		class="recording-artifact-review"
		:name="title"
		size="large"
		:noClose="isBusy"
		@update:open="close">
		<div v-if="isLoading" class="recording-artifact-review__loading">
			<NcLoadingIcon :size="32" :name="t('spreed', 'Loading recording text')" />
		</div>
		<div v-else-if="loadError" class="recording-artifact-review__error" role="alert">
			<p>{{ t('spreed', 'Could not load the recording text') }}</p>
			<NcButton @click="loadArtifact">
				{{ t('spreed', 'Retry') }}
			</NcButton>
		</div>
		<div v-else-if="artifact" class="recording-artifact-review__content">
			<p class="recording-artifact-review__note">
				{{ t('spreed', 'AI-generated text may contain mistakes. Review names, decisions, and action items before publishing.') }}
			</p>
			<p v-if="isDraft && !canPublish" class="recording-artifact-review__note">
				{{ t('spreed', 'You can review this draft, but only a moderator who can post messages may publish it.') }}
			</p>
			<div v-if="hasConflict" class="recording-artifact-review__conflict" role="status">
				<span>{{ conflictMessage }}</span>
				<NcButton variant="secondary" @click="reloadAfterConflict">
					{{ t('spreed', 'Reload latest') }}
				</NcButton>
			</div>
			<div v-else-if="!isDraft" class="recording-artifact-review__status" role="status">
				<span v-if="isPublished">{{ t('spreed', 'Published') }}</span>
				<span v-else-if="artifact.state === 'editing'">{{ t('spreed', 'This recording text is being edited. Editing is temporarily unavailable.') }}</span>
				<span v-else>{{ t('spreed', 'This recording text is being published.') }}</span>
				<NcButton v-if="!isPublished" variant="secondary" @click="loadArtifact">
					{{ t('spreed', 'Check again') }}
				</NcButton>
			</div>
			<div v-if="operationError" class="recording-artifact-review__error" role="alert">
				<span>{{ operationError === 'save' ? t('spreed', 'Could not save the recording text') : t('spreed', 'Could not publish the recording text') }}</span>
				<NcButton @click="operationError === 'save' ? save() : publish()">
					{{ t('spreed', 'Retry') }}
				</NcButton>
			</div>
			<div class="recording-artifact-review__editor" dir="auto">
				<div v-if="textEditorAvailable" ref="editorElement" class="recording-artifact-review__text-editor" />
				<NcTextArea
					v-else
					v-model="content"
					:label="t('spreed', 'Recording text')"
					:disabled="isBusy || !isDraft"
					labelVisible
					resize="vertical" />
			</div>
		</div>
		<template v-if="artifact && isDraft" #actions>
			<NcButton :disabled="isBusy || !isDirty || hasConflict" @click="save">
				<NcLoadingIcon v-if="isSaving" />
				{{ t('spreed', 'Save draft') }}
			</NcButton>
			<NcButton variant="primary" :disabled="isBusy || hasConflict || !canPublish" @click="publish">
				<NcLoadingIcon v-if="isPublishing" />
				{{ t('spreed', 'Publish to chat') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style lang="scss" scoped>
.recording-artifact-review {
	&__loading {
		display: flex;
		justify-content: center;
		padding: 48px;
	}

	&__error,
	&__status {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		padding: 12px;
		border-radius: var(--border-radius-large);
		background: var(--color-background-dark);
	}

	&__content {
		display: flex;
		flex-direction: column;
		gap: 12px;
	}

	&__note,
	&__conflict {
		padding: 12px;
		border-radius: var(--border-radius-large);
		background: var(--color-background-dark);
	}

	&__conflict {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
	}

	&__editor {
		min-height: min(58vh, 620px);
		padding: 8px;
		overflow: auto;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		background: var(--color-main-background);
	}

	&__editor :deep(textarea) {
		min-height: min(55vh, 560px);
	}

	&__text-editor :deep(.editor__content) {
		min-height: min(54vh, 580px);
		max-width: 100%;
	}
}

@media (max-width: 700px) {
	.recording-artifact-review__editor,
	.recording-artifact-review__editor :deep(textarea),
	.recording-artifact-review__text-editor :deep(.editor__content) {
		min-height: 36vh;
	}
}
</style>
