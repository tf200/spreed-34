<!--
  - SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcActions
		forceMenu
		:title="t('spreed', 'Conversation actions')"
		:aria-label="t('spreed', 'Conversation actions')"
		variant="tertiary">
		<!-- Menu icon: white if in call -->
		<template v-if="isInCall" #icon>
			<IconDotsHorizontal :size="20" />
		</template>

		<template v-if="isInCall && canFullModerate">
			<!-- Moderator actions -->
			<template v-if="!isOneToOneConversation">
				<NcActionButton
					closeAfterClick
					@click="forceMuteOthers">
					<template #icon>
						<NcIconSvgWrapper :svg="IconMicrophoneOffOutline" :size="20" />
					</template>
					{{ t('spreed', 'Mute others') }}
				</NcActionButton>
			</template>

			<!-- Call recording -->
			<template v-if="canModerateRecording">
				<NcActionButton
					v-if="!isRecording && !isStartingRecording && isInCall"
					closeAfterClick
					@click="startRecording">
					<template #icon>
						<NcIconSvgWrapper
							:svg="IconScreenRecordOutline"
							:size="20" />
					</template>
					{{ t('spreed', 'Start recording') }}
				</NcActionButton>
				<NcActionButton
					v-else-if="isStartingRecording && isInCall"
					closeAfterClick
					@click="stopRecording">
					<template #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('spreed', 'Cancel recording start') }}
				</NcActionButton>
				<template v-else-if="isRecording && isInCall">
					<NcActionButton
						closeAfterClick
						@click="stopRecording">
						<template #icon>
							<IconStop :size="20" />
						</template>
						{{ t('spreed', 'Stop recording') }}
					</NcActionButton>
					<NcActionButton
						v-if="isRecordingSummaryEnabled"
						closeAfterClick
						@click="changeSummaryTemplate">
						<template #icon>
							<IconTextBoxEditOutline :size="20" />
						</template>
						{{ t('spreed', 'Change summary template') }}
					</NcActionButton>
				</template>
			</template>
			<template v-else-if="hintRecording">
				<NcActionButton
					disabled
					:description="t('spreed', 'Recording backend is not installed')">
					<template #icon>
						<NcIconSvgWrapper
							:svg="IconScreenRecordOutline"
							:size="20" />
					</template>
					{{ t('spreed', 'Start recording') }}
				</NcActionButton>
			</template>

			<NcActionSeparator v-if="!isOneToOneConversation || canModerateRecording" />
		</template>

		<!-- Go to file -->
		<NcActionLink
			v-if="isFileConversation"
			target="_blank"
			rel="noopener noreferrer"
			:href="linkToFile">
			<template #icon>
				<IconFileOutline :size="20" />
			</template>
			{{ t('spreed', 'Go to file') }}
		</NcActionLink>

		<!-- Device settings -->
		<NcActionButton
			v-if="isInCall"
			closeAfterClick
			@click="showMediaSettingsDialog">
			<template #icon>
				<IconVideoOutline :size="20" />
			</template>
			{{ t('spreed', 'Check devices') }}
		</NcActionButton>

		<!-- Breakout rooms -->
		<NcActionButton
			v-if="canConfigureBreakoutRooms"
			closeAfterClick
			@click="$emit('openBreakoutRoomsEditor')">
			<template #icon>
				<IconDotsCircle :size="20" />
			</template>
			{{ t('spreed', 'Set up breakout rooms') }}
		</NcActionButton>

		<NcActionLink
			v-if="isInCall && canDownloadCallParticipants"
			:href="downloadCallParticipantsLink"
			target="_blank">
			<template #icon>
				<NcIconSvgWrapper :svg="IconFileDownload" :size="20" />
			</template>
			{{ t('spreed', 'Download attendance list') }}
		</NcActionLink>
		<!-- Fullscreen -->
		<NcActionButton
			v-if="!isInCall"
			:aria-label="t('spreed', 'Toggle full screen')"
			closeAfterClick
			@click="toggleFullscreen">
			<template #icon>
				<IconFullscreen v-if="!isFullscreen" :size="20" />
				<IconFullscreenExit v-else :size="20" />
			</template>
			{{ labelFullscreen }}
		</NcActionButton>

		<!-- Conversation settings -->
		<NcActionButton
			closeAfterClick
			@click="openConversationSettings">
			<template #icon>
				<IconCogOutline :size="20" />
			</template>
			{{ t('spreed', 'Conversation settings') }}
		</NcActionButton>
	</NcActions>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionLink from '@nextcloud/vue/components/NcActionLink'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import IconCogOutline from 'vue-material-design-icons/CogOutline.vue'
import IconDotsCircle from 'vue-material-design-icons/DotsCircle.vue'
import IconDotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import IconFileOutline from 'vue-material-design-icons/FileOutline.vue'
import IconFullscreen from 'vue-material-design-icons/Fullscreen.vue'
import IconFullscreenExit from 'vue-material-design-icons/FullscreenExit.vue'
import IconStop from 'vue-material-design-icons/Stop.vue'
import IconTextBoxEditOutline from 'vue-material-design-icons/TextBoxEditOutline.vue'
import IconVideoOutline from 'vue-material-design-icons/VideoOutline.vue'
import RecordingSummaryTemplatePicker from '../RecordingSummaryTemplates/RecordingSummaryTemplatePicker.vue'
import IconFileDownload from '../../../img/material-icons/file-download.svg?raw'
import IconMicrophoneOffOutline from '../../../img/material-icons/microphone-off-outline.svg?raw'
import IconScreenRecordOutline from '../../../img/material-icons/screen-record-outline.svg?raw'
import {
	toggleFullscreen,
	useDocumentFullscreen,
} from '../../composables/useDocumentFullscreen.ts'
import { useIsInCall } from '../../composables/useIsInCall.js'
import { CALL, CONVERSATION, PARTICIPANT } from '../../constants.ts'
import {
	getTalkConfig,
	hasTalkFeature,
	showTalkFeatureHint,
} from '../../services/CapabilitiesManager.ts'
import {
	getConversationSummaryTemplate,
	setActiveSummaryTemplate,
	setConversationSummaryTemplate,
} from '../../services/recordingSummaryTemplateService.ts'
import { generateAbsoluteUrl } from '../../utils/handleUrl.ts'
import { callParticipantCollection } from '../../utils/webrtc/index.js'

export default {
	name: 'TopBarMenu',

	components: {
		NcActionButton,
		NcActionLink,
		NcActionSeparator,
		NcActions,
		NcLoadingIcon,
		NcIconSvgWrapper,
		// Icons
		IconCogOutline,
		IconDotsCircle,
		IconDotsHorizontal,
		IconFileOutline,
		IconFullscreen,
		IconFullscreenExit,
		IconStop,
		IconTextBoxEditOutline,
		IconVideoOutline,
	},

	props: {
		/**
		 * The conversation token
		 */
		token: {
			type: String,
			required: true,
		},
	},

	emits: ['openBreakoutRoomsEditor'],

	setup(props) {
		return {
			IconFileDownload,
			IconMicrophoneOffOutline,
			IconScreenRecordOutline,
			isFullscreen: useDocumentFullscreen(),
			isInCall: useIsInCall(),
			toggleFullscreen,
		}
	},

	data() {
		return {
			boundaryElement: document.querySelector('.main-view'),
		}
	},

	computed: {
		conversation() {
			return this.$store.getters.conversation(this.token) || this.$store.getters.dummyConversation
		},

		labelFullscreen() {
			return this.isFullscreen
				? t('spreed', 'Exit full screen (F)')
				: t('spreed', 'Full screen (F)')
		},

		isFileConversation() {
			return this.conversation.objectType === CONVERSATION.OBJECT_TYPE.FILE && this.conversation.objectId
		},

		linkToFile() {
			return this.isFileConversation
				? generateAbsoluteUrl('/f/{objectId}', { objectId: this.conversation.objectId })
				: ''
		},

		isOneToOneConversation() {
			return this.conversation.type === CONVERSATION.TYPE.ONE_TO_ONE
				|| this.conversation.type === CONVERSATION.TYPE.ONE_TO_ONE_FORMER
		},

		participantType() {
			return this.conversation.participantType
		},

		canFullModerate() {
			return this.participantType === PARTICIPANT.TYPE.OWNER || this.participantType === PARTICIPANT.TYPE.MODERATOR
		},

		canModerate() {
			return this.canFullModerate || this.participantType === PARTICIPANT.TYPE.GUEST_MODERATOR
		},

		canModerateRecording() {
			return getTalkConfig(this.token, 'call', 'recording') || false
		},

		hintRecording() {
			return !this.canModerateRecording && showTalkFeatureHint(34)
		},

		canConfigureBreakoutRooms() {
			if (this.conversation.type !== CONVERSATION.TYPE.GROUP || !this.canFullModerate) {
				return false
			}

			if (this.conversation.objectType === CONVERSATION.OBJECT_TYPE.BREAKOUT_ROOM
				|| this.conversation.breakoutRoomMode !== CONVERSATION.BREAKOUT_ROOM_MODE.NOT_CONFIGURED) {
				return false
			}

			return !!getTalkConfig(this.token, 'call', 'breakout-rooms')
		},

		isStartingRecording() {
			return this.conversation.callRecording === CALL.RECORDING.VIDEO_STARTING
				|| this.conversation.callRecording === CALL.RECORDING.AUDIO_STARTING
		},

		isRecordingSummaryEnabled() {
			return !!getTalkConfig(this.token, 'call', 'recording-summary')
		},

		isRecording() {
			return this.conversation.callRecording === CALL.RECORDING.VIDEO
				|| this.conversation.callRecording === CALL.RECORDING.AUDIO
		},

		canDownloadCallParticipants() {
			return hasTalkFeature(this.token, 'download-call-participants') && this.canModerate && !this.isOneToOneConversation
		},

		downloadCallParticipantsLink() {
			return generateOcsUrl('apps/spreed/api/v4/call/{token}/download', { token: this.token })
		},
	},

	methods: {
		t,
		forceMuteOthers() {
			callParticipantCollection.callParticipantModels.forEach((callParticipantModel) => {
				callParticipantModel.forceMute()
			})
		},

		showMediaSettingsDialog() {
			emit('talk:media-settings:show')
		},

		openConversationSettings() {
			emit('show-conversation-settings', { token: this.token })
		},

		startRecording() {
			// The summary template of the conversation, or else the default
			// template of the user, is used.
			this.$store.dispatch('startCallRecording', {
				token: this.token,
				callRecording: CALL.RECORDING.VIDEO,
			})
		},

		async changeSummaryTemplate() {
			let current
			try {
				current = (await getConversationSummaryTemplate(this.token)).data.ocs.data
			} catch {
				showError(t('spreed', 'Could not load the summary template of the recording'))
				return
			}
			const result = await spawnDialog(RecordingSummaryTemplatePicker, {
				name: t('spreed', 'Summary template of this recording'),
				description: t('spreed', 'The summary of this recording is generated with the chosen template.'),
				confirmLabel: t('spreed', 'Use template'),
				currentId: current.active?.id ?? null,
				canRememberForConversation: true,
			})
			if (!result) {
				return
			}
			try {
				await setActiveSummaryTemplate(this.token, result.templateId)
				if (result.rememberForConversation) {
					await setConversationSummaryTemplate(this.token, result.templateId)
				}
				showSuccess(t('spreed', 'Summary template changed'))
			} catch {
				showError(t('spreed', 'The summary template could not be changed. The recording may already be stopped.'))
			}
		},

		stopRecording() {
			this.$store.dispatch('stopCallRecording', {
				token: this.token,
			})
		},
	},
}
</script>
