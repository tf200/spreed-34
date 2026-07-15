<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { RecordingArtifactListItem } from '../../../services/recordingArtifactService.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import IconFileDocumentEditOutline from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import { getRecordingArtifacts } from '../../../services/recordingArtifactService.ts'

const props = defineProps<{
	token: string
	active: boolean
}>()

const route = useRoute()
const router = useRouter()
const artifacts = ref<RecordingArtifactListItem[]>([])
const loading = ref(false)
const loadError = ref(false)
let loadRequest = 0
const empty = computed(() => !loading.value && !loadError.value && artifacts.value.length === 0)

watch(() => [props.active, props.token, route.query.reviewArtifact] as const, ([active, , reviewArtifact], previous) => {
	if (active) {
		const reviewClosed = previous?.[2] !== undefined && reviewArtifact === undefined
		if (!previous || previous[0] !== active || previous[1] !== props.token || reviewClosed) {
			loadArtifacts()
		}
	}
}, { immediate: true })

/** Load private drafts for the active conversation. */
async function loadArtifacts() {
	const request = ++loadRequest
	const token = props.token
	loading.value = true
	loadError.value = false
	try {
		const response = await getRecordingArtifacts(token)
		if (request !== loadRequest || token !== props.token) {
			return
		}
		artifacts.value = response.data.ocs.data
	} catch (error) {
		if (request !== loadRequest || token !== props.token) {
			return
		}
		console.error(error)
		loadError.value = true
	} finally {
		if (request === loadRequest && token === props.token) {
			loading.value = false
		}
	}
}

/**
 * Open an artifact while retaining unrelated route query state.
 *
 * @param artifact Artifact to review
 */
function openArtifact(artifact: RecordingArtifactListItem) {
	return router.push({
		query: {
			...route.query,
			reviewArtifact: artifact.id,
			notificationTimestamp: String(artifact.notificationTimestamp),
		},
	})
}
</script>

<template>
	<div class="recording-drafts">
		<NcLoadingIcon
			v-if="loading"
			class="recording-drafts__loading"
			:size="32"
			:name="t('spreed', 'Loading recording drafts')" />
		<NcEmptyContent
			v-else-if="loadError"
			:name="t('spreed', 'Could not load recording drafts')">
			<template #action>
				<NcButton @click="loadArtifacts">
					{{ t('spreed', 'Retry') }}
				</NcButton>
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="empty" :name="t('spreed', 'No recording drafts')">
			<template #icon>
				<IconFileDocumentEditOutline />
			</template>
		</NcEmptyContent>
		<ul v-else class="recording-drafts__list">
			<li v-for="artifact in artifacts" :key="artifact.id">
				<NcButton
					alignment="start"
					variant="tertiary"
					wide
					@click="openArtifact(artifact)">
					<template #icon>
						<IconFileDocumentEditOutline :size="20" />
					</template>
					{{ artifact.type === 'summary' ? t('spreed', 'Call summary') : t('spreed', 'Call transcript') }}
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<style lang="scss" scoped>
.recording-drafts {
	&__loading {
		display: block;
		margin: 48px auto;
	}

	&__list {
		margin: 0;
		padding: var(--default-grid-baseline) 0;
		list-style: none;
	}
}
</style>
