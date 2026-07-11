<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<section id="recording_server" class="recording-servers section">
		<h2>
			{{ t('spreed', 'Recording backend') }}
		</h2>

		<NcNoteCard
			v-if="!hasSignalingServers"
			type="warning"
			:text="t('spreed', 'Recording backend configuration is only possible with a High-performance backend.')" />

		<template v-else>
			<NcNoteCard v-if="showUploadLimitWarning" type="warning" :text="uploadLimitWarning" />

			<TransitionWrapper
				v-if="servers.length"
				name="fade"
				tag="ul"
				group>
				<RecordingServer
					v-for="(server, index) in servers"
					:key="`server${index}`"
					v-model:server="servers[index].server"
					v-model:verify="servers[index].verify"
					:index="index"
					:loading="loading"
					@removeServer="removeServer"
					@update:server="debounceUpdateServers"
					@update:verify="debounceUpdateServers" />
			</TransitionWrapper>

			<NcButton
				v-else
				class="additional-top-margin"
				:disabled="loading"
				@click="newServer">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<IconPlus v-else :size="20" />
				</template>
				{{ t('spreed', 'Add a new recording backend server') }}
			</NcButton>

			<NcPasswordField
				v-model="secret"
				class="form__textfield additional-top-margin"
				name="recording_secret"
				asText
				:disabled="loading"
				:placeholder="t('spreed', 'Shared secret')"
				:label="t('spreed', 'Shared secret')"
				labelVisible
				@update:modelValue="debounceUpdateServers" />

			<template v-if="servers.length && recordingConsentCapability">
				<h3>{{ t('spreed', 'Recording consent') }}</h3>

				<template v-for="level in recordingConsentOptions" :key="level.value">
					<NcCheckboxRadioSwitch
						v-model="recordingConsentSelected"
						:value="level.value.toString()"
						name="recording-consent"
						type="radio"
						:disabled="loading"
						@update:modelValue="setRecordingConsent">
						{{ level.label }}
					</NcCheckboxRadioSwitch>

					<p class="consent-description">
						{{ getRecordingConsentDescription(level.value) }}
					</p>
				</template>
			</template>

			<template v-if="servers.length">
				<h3>{{ t('spreed', 'Recording transcription') }}</h3>

				<!-- FIXME hidden until transcription quality is appropriate -->
				<NcCheckboxRadioSwitch
					v-if="false"
					v-model="recordingTranscriptionEnabled"
					type="switch"
					:disabled="loading"
					@update:modelValue="setRecordingTranscription">
					{{ t('spreed', 'Automatically transcribe call recordings with a transcription provider') }}
				</NcCheckboxRadioSwitch>

				<NcCheckboxRadioSwitch
					v-model="recordingSummaryEnabled"
					type="switch"
					:disabled="loading"
					@update:modelValue="setRecordingSummary">
					{{ t('spreed', 'Automatically summarize call recordings with transcription and summary providers') }}
				</NcCheckboxRadioSwitch>

				<h3>{{ t('spreed', 'Google Cloud transcription and summaries') }}</h3>
				<NcCheckboxRadioSwitch v-model="google.enabled" type="switch" :disabled="loading">
					{{ t('spreed', 'Use Google Cloud for recording transcription and summaries') }}
				</NcCheckboxRadioSwitch>
				<NcTextField
					v-model="google.project"
					class="form__textfield"
					:label="t('spreed', 'Google Cloud project ID')"
					labelVisible />
				<NcTextField
					v-model="google.location"
					class="form__textfield"
					:label="t('spreed', 'Google Cloud location')"
					labelVisible />
				<NcTextField
					v-model="google.bucket"
					class="form__textfield"
					:label="t('spreed', 'Cloud Storage bucket')"
					labelVisible />
				<NcTextField
					v-model="google.language"
					class="form__textfield"
					:label="t('spreed', 'Transcription language')"
					labelVisible />
				<NcTextField
					v-model="google.speechModel"
					class="form__textfield"
					:label="t('spreed', 'Speech model')"
					labelVisible />
				<NcTextField
					v-model="google.geminiLocation"
					class="form__textfield"
					:label="t('spreed', 'Gemini location')"
					labelVisible />
				<NcTextField
					v-model="google.geminiModel"
					class="form__textfield"
					:label="t('spreed', 'Gemini model')"
					labelVisible />
				<NcTextArea
					v-model="serviceAccountJson"
					class="form__textfield"
					:label="t('spreed', 'Service account JSON')"
					:placeholder="google.serviceAccountConfigured ? t('spreed', 'A service account is configured. Leave empty to keep it.') : t('spreed', 'Paste service account JSON')" />
				<NcCheckboxRadioSwitch v-if="google.serviceAccountConfigured" v-model="removeServiceAccount" type="checkbox">
					{{ t('spreed', 'Remove configured service account') }}
				</NcCheckboxRadioSwitch>
				<NcButton variant="primary" :disabled="loading" @click="saveGoogleSettings">
					{{ t('spreed', 'Save Google Cloud settings') }}
				</NcButton>
			</template>
		</template>
	</section>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { formatFileSize } from '@nextcloud/files'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import debounce from 'debounce'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import RecordingServer from '../../components/AdminSettings/RecordingServer.vue'
import TransitionWrapper from '../UIShared/TransitionWrapper.vue'
import { CONFIG } from '../../constants.ts'
import { saveRecordingGoogleSettings } from '../../services/adminSettingsService.js'
import { hasTalkFeature } from '../../services/CapabilitiesManager.ts'

const recordingConsentCapability = hasTalkFeature('local', 'recording-consent')
const recordingConsentOptions = [
	{ value: CONFIG.RECORDING_CONSENT.OFF, label: t('spreed', 'Disabled for all calls') },
	{ value: CONFIG.RECORDING_CONSENT.REQUIRED, label: t('spreed', 'Enabled for all calls') },
	{ value: CONFIG.RECORDING_CONSENT.OPTIONAL, label: t('spreed', 'Configurable on conversation level by moderators') },
]

export default {
	name: 'RecordingServers',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcPasswordField,
		NcTextArea,
		NcTextField,
		IconPlus,
		RecordingServer,
		TransitionWrapper,
	},

	props: {
		hasSignalingServers: {
			type: Boolean,
			required: true,
		},
	},

	setup() {
		return {
			recordingConsentCapability,
			recordingConsentOptions,
		}
	},

	data() {
		return {
			servers: [],
			secret: '',
			uploadLimit: 0,
			loading: false,
			saved: false,
			recordingConsentSelected: loadState('spreed', 'recording_consent').toString(),
			recordingTranscriptionEnabled: loadState('spreed', 'call_recording_transcription'),
			recordingSummaryEnabled: loadState('spreed', 'call_recording_summary'),
			debounceUpdateServers: () => {},
			google: loadState('spreed', 'recording_google_settings'),
			serviceAccountJson: '',
			removeServiceAccount: false,
		}
	},

	computed: {
		showUploadLimitWarning() {
			return this.uploadLimit !== 0 && this.uploadLimit < 512 * (1024 ** 2)
		},

		uploadLimitWarning() {
			return t('spreed', 'The PHP settings "upload_max_filesize" or "post_max_size" only will allow to upload files up to {maxUpload}.', {
				maxUpload: formatFileSize(this.uploadLimit, true, true),
			})
		},
	},

	beforeMount() {
		this.debounceUpdateServers = debounce(this.updateServers, 1000)

		const state = loadState('spreed', 'recording_servers')
		this.servers = state.servers
		this.secret = state.secret
		this.uploadLimit = parseInt(state.uploadLimit, 10)
	},

	beforeUnmount() {
		this.debounceUpdateServers.clear?.()
	},

	methods: {
		t,
		removeServer(index) {
			this.servers.splice(index, 1)
			this.debounceUpdateServers()
		},

		newServer() {
			this.servers.push({
				server: '',
				verify: false,
			})
		},

		async updateServers() {
			this.loading = true

			this.servers = this.servers.filter((server) => server.server.trim() !== '')

			OCP.AppConfig.setValue('spreed', 'recording_servers', JSON.stringify({
				servers: this.servers,
				secret: this.secret,
			}), {
				success: () => {
					showSuccess(t('spreed', 'Recording backend settings saved'))
					this.loading = false
					this.toggleSave()
				},
			})
		},

		async saveGoogleSettings() {
			this.loading = true
			try {
				await saveRecordingGoogleSettings({
					...this.google,
					serviceAccountJson: this.serviceAccountJson,
					removeServiceAccount: this.removeServiceAccount,
				})
				this.google.serviceAccountConfigured = this.removeServiceAccount ? false : (this.google.serviceAccountConfigured || this.serviceAccountJson !== '')
				this.serviceAccountJson = ''
				this.removeServiceAccount = false
				showSuccess(t('spreed', 'Google Cloud settings saved'))
			} catch (error) {
				showError(t('spreed', 'Failed to save Google Cloud settings'))
			}
			this.loading = false
		},

		setRecordingConsent(value) {
			this.loading = true
			OCP.AppConfig.setValue('spreed', 'recording_consent', value, {
				success: () => {
					this.loading = false
				},
			})
		},

		setRecordingTranscription(value) {
			this.loading = true
			OCP.AppConfig.setValue('spreed', 'call_recording_transcription', value ? 'yes' : 'no', {
				success: () => {
					this.loading = false
				},
			})
		},

		setRecordingSummary(value) {
			this.loading = true
			OCP.AppConfig.setValue('spreed', 'call_recording_summary', value ? 'yes' : 'no', {
				success: () => {
					this.loading = false
				},
			})
		},

		getRecordingConsentDescription(value) {
			switch (value) {
				case CONFIG.RECORDING_CONSENT.OPTIONAL:
					return t('spreed', 'Moderators will be allowed to enable consent on conversation level. The consent to be recorded will be required for each participant before joining every call in this conversation.')
				case CONFIG.RECORDING_CONSENT.REQUIRED:
					return t('spreed', 'The consent to be recorded will be required for each participant before joining every call.')
				case CONFIG.RECORDING_CONSENT.OFF:
				default:
					return t('spreed', 'The consent to be recorded is not required.')
			}
		},

		toggleSave() {
			this.saved = true
			setTimeout(() => {
				this.saved = false
			}, 3000)
		},
	},
}
</script>

<style lang="scss" scoped>
.recording-servers {
	.form__textfield {
		width: 300px;
	}
}

.additional-top-margin {
	margin-top: 10px;
}

h3 {
	margin-top: 24px;
	font-weight: 600;
}

.consent-description {
	margin-bottom: 12px;
	opacity: 0.7;
}
</style>
