<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script lang="ts" setup>
import type { WatchStopHandle } from 'vue'
import type { Conversation } from '../types/index.ts'

import { emit } from '@nextcloud/event-bus'
import { computed, onMounted, onUnmounted, ref, watch, watchEffect } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useStore } from 'vuex'
import CallFailedDialog from '../components/CallView/CallFailedDialog.vue'
import CallView from '../components/CallView/CallView.vue'
import ChatView from '../components/ChatView.vue'
import ExternalCallView from '../components/ExternalCallView.vue'
import LobbyScreen from '../components/LobbyScreen.vue'
import PollViewer from '../components/PollViewer/PollViewer.vue'
import RecordingArtifactReviewDialog from '../components/RecordingArtifactReviewDialog.vue'
import TopBar from '../components/TopBar/TopBar.vue'
import { useIsInCall } from '../composables/useIsInCall.js'
import { useJoinCall } from '../composables/useJoinCall.ts'
import { watchJoinedConversation } from '../composables/useJoinedConversation.ts'
import { CALL, CONVERSATION, PARTICIPANT } from '../constants.ts'
import { getTalkConfig } from '../services/CapabilitiesManager.ts'
import { useActorStore } from '../stores/actor.ts'
import { useSettingsStore } from '../stores/settings.ts'

const props = defineProps<{
	token: string
}>()

const store = useStore()
const isInCall = useIsInCall()
const { joinCall } = useJoinCall()
const router = useRouter()
const route = useRoute()
const actorStore = useActorStore()
const settingsStore = useSettingsStore()
let reviewRequest = 0
const reviewDialog = ref<{
	token: string
	artifactId: string
	notificationTimestamp: number
} | null>(null)

/** Internal handlers for 'joined-conversation' watcher (direct-call) */
let unwatchJoinedConversation: WatchStopHandle | undefined
let watchedJoinedConversationToken: string | undefined
/**
 * Release the listener for joined conversation
 */
function stopWatchingJoinedConversation() {
	unwatchJoinedConversation?.()
	unwatchJoinedConversation = undefined
	watchedJoinedConversationToken = undefined
}

const isInLobby = computed(() => store.getters.isInLobby)
const connectionFailed = computed(() => store.getters.connectionFailed(props.token))
const isVoiceRoom = computed(() => Boolean(store.getters.conversation(props.token)?.attributes & CONVERSATION.ATTRIBUTE.VOICE_ROOM))
const canPublishRecordingArtifact = computed(() => {
	const conversation = store.getters.conversation(props.token) as Conversation | undefined
	return store.getters.isModerator
		&& conversation?.readOnly === CONVERSATION.STATE.READ_WRITE
		&& (conversation.permissions & PARTICIPANT.PERMISSIONS.CHAT) !== 0
})
const isInExternalCall = computed(() => {
	const conversation = store.getters.conversation(props.token) as Conversation | undefined
	return conversation?.objectType === CONVERSATION.OBJECT_TYPE.EXTERNAL_CALL && isInCall.value
		&& !getTalkConfig('local', 'call', 'enabled')
		&& getTalkConfig('local', 'call', 'external-call-service')
})

watch([() => props.token, isVoiceRoom], ([newToken, newIsVoiceRoom]) => {
	// Release a stale joined-conversation listener when navigating away
	if (watchedJoinedConversationToken && watchedJoinedConversationToken !== newToken) {
		stopWatchingJoinedConversation()
	}
	if (newIsVoiceRoom && newToken) {
		handleDirectCall(newToken)
	}
}, { immediate: true })

watch(isInLobby, (isInLobby) => {
	// User is now blocked by the lobby
	if (isInLobby && isInCall.value) {
		store.dispatch('leaveCall', {
			token: props.token,
			participantIdentifier: actorStore.participantIdentifier,
		})
	}
})

watch([
	() => route.query.reviewArtifact,
	() => route.query.notificationTimestamp,
	() => props.token,
], processReviewQuery, { immediate: true })

/** Open or clean up the recording review identified by the current route. */
async function processReviewQuery() {
	const reviewArtifact = route.query.reviewArtifact
	const notificationTimestamp = route.query.notificationTimestamp
	const token = props.token
	const request = ++reviewRequest
	const isArtifactIdValid = typeof reviewArtifact === 'string'
		&& /^[1-9]\d*$/.test(reviewArtifact)
	const isTimestampValid = typeof notificationTimestamp === 'string'
		&& /^\d+$/.test(notificationTimestamp)
		&& Number.isSafeInteger(Number(notificationTimestamp))
	if (!isArtifactIdValid || !isTimestampValid) {
		reviewDialog.value = null
		if (reviewArtifact !== undefined || notificationTimestamp !== undefined) {
			await removeReviewQuery(request)
		}
		return
	}
	reviewDialog.value = {
		token,
		artifactId: reviewArtifact,
		notificationTimestamp: Number(notificationTimestamp),
	}
}

/** Close the route-owned review dialog without clearing a newer route. */
async function closeReviewDialog() {
	const dialog = reviewDialog.value
	reviewDialog.value = null
	if (dialog !== null) {
		await removeReviewQuery(reviewRequest, dialog.artifactId, dialog.token)
	}
}

/**
 * Remove only the review query that belongs to this dialog invocation.
 *
 * @param request Dialog request generation
 * @param artifactId Artifact ID opened by the dialog
 * @param token Conversation token opened by the dialog
 */
async function removeReviewQuery(request: number, artifactId?: string, token?: string) {
	if (request !== reviewRequest
		|| (artifactId !== undefined && route.query.reviewArtifact !== artifactId)
		|| (token !== undefined && props.token !== token)) {
		return
	}
	const query = { ...route.query }
	delete query.reviewArtifact
	delete query.notificationTimestamp
	await router.replace({ query })
}

onMounted(() => {
	watchEffect(() => {
		if (route.hash === '#direct-call') {
			handleDirectCall(route.params.token as string)
			router.replace({ hash: '' })
		} else if (route.hash === '#settings') {
			emit('show-conversation-settings', { token: props.token })
			router.replace({ hash: '' })
		}
	})
})

onUnmounted(() => {
	reviewRequest++
	reviewDialog.value = null
	stopWatchingJoinedConversation()
})

/**
 * Check if the user should join the call directly or show MediaSettings
 *
 * @param routeToken token of conversation to join
 */
function handleDirectCall(routeToken: string) {
	stopWatchingJoinedConversation()

	const conversation = store.getters.conversation(routeToken)
	if ([CONVERSATION.TYPE.CHANGELOG, CONVERSATION.TYPE.NOTE_TO_SELF].includes(conversation.type)) {
		// Do not allow calls in these conversations
		return
	}

	const showRecordingWarning = [
		CALL.RECORDING.VIDEO_STARTING,
		CALL.RECORDING.AUDIO_STARTING,
		CALL.RECORDING.VIDEO,
		CALL.RECORDING.AUDIO,
	].includes(conversation.callRecording)
	|| conversation.recordingConsent === CALL.RECORDING_CONSENT.ENABLED
	const isConversationPhoneRoom = [
		CONVERSATION.OBJECT_TYPE.PHONE_LEGACY,
		CONVERSATION.OBJECT_TYPE.PHONE_PERSISTENT,
		CONVERSATION.OBJECT_TYPE.PHONE_TEMPORARY,
	].includes(conversation.objectType)
	&& conversation.objectId === CONVERSATION.OBJECT_ID.PHONE_OUTGOING

	// Verify conditions for showing MediaSettings (required or user opted out)
	if (showRecordingWarning || settingsStore.showMediaSettings || isConversationPhoneRoom) {
		emit('talk:media-settings:show')
		return
	}

	watchedJoinedConversationToken = routeToken
	unwatchJoinedConversation = watchJoinedConversation(routeToken, () => {
		stopWatchingJoinedConversation()
		void joinCall(routeToken, { directCall: true })
	}, { immediate: true })
}
</script>

<template>
	<div class="main-view">
		<LobbyScreen v-if="isInLobby" />
		<template v-else>
			<TopBar v-if="!isInExternalCall" :isInCall="isInCall" />
			<ExternalCallView v-if="isInExternalCall" :token="token" />
			<CallView v-else-if="isInCall" :token="token" />
			<ChatView v-else />
			<PollViewer />
			<CallFailedDialog v-if="connectionFailed" :token="token" />
			<RecordingArtifactReviewDialog
				v-if="reviewDialog"
				:key="`${reviewDialog.token}-${reviewDialog.artifactId}`"
				:token="reviewDialog.token"
				:artifactId="reviewDialog.artifactId"
				:notificationTimestamp="reviewDialog.notificationTimestamp"
				:canPublish="canPublishRecordingArtifact"
				@close="closeReviewDialog" />
		</template>
	</div>
</template>

<style lang="scss" scoped>
.main-view {
	height: 100%;
	width: 100%;
	display: flex;
	flex-grow: 1;
	flex-direction: column;
	align-content: space-between;
	position: relative;
}
</style>
