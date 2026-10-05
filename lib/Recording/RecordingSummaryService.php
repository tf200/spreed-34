<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Recording;

use OCA\Talk\AppInfo\Application;
use OCA\Talk\Exceptions\RecordingArtifactException;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Manager;
use OCA\Talk\Model\RecordingArtifact;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\RecordingArtifactService;
use OCA\Talk\Service\RecordingSummaryTemplateService;
use OCP\Config\IUserConfig;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Generates the summaries of recordings.
 *
 * @psalm-import-type SummaryTemplateDefinitionArray from SummaryTemplateDefinition
 */
class RecordingSummaryService {
	// MM:SS or H:MM:SS, as rendered in the transcript.
	private const HEADER = '/^\*\*(.+)\*\* · ((?:[0-9]+:)?[0-9]{2}:[0-9]{2})$/mu';

	private const SAMPLE_TRANSCRIPT = <<<'TRANSCRIPT'
**Alice** · 00:04
Good morning everyone. Today we need to agree on the budget for the website relaunch and on the launch date.

**Bob** · 00:15
I went through the agency offer. Design and development come to forty-two thousand euros, which is eight thousand over our plan.

**Carol** · 00:31
Most of the difference is the custom booking module. We could use the booking plugin we already license instead.

**Alice** · 00:44
Does the plugin cover the multi-language requirement?

**Carol** · 00:49
Only German and English. Dutch would still be missing, I need to check with the vendor.

**Bob** · 01:02
If we drop the custom module we end up at thirty-five thousand, so we would be under budget.

**Alice** · 01:12
Then let's go with the plugin, provided Dutch is supported. Carol, can you ask the vendor by Friday?

**Carol** · 01:20
Yes, I will send them an email today.

**Bob** · 01:25
About the launch date: the agency proposes the first of March, but marketing wanted it before the trade fair in mid February.

**Alice** · 01:38
I don't think we can move it. Let's keep the first of March and tell marketing.

**Bob** · 01:45
Okay. I will update the project plan and send it to marketing tomorrow.

**Carol** · 01:52
One open point is hosting. We have not decided if we stay with the current provider.

**Alice** · 02:01
Let's discuss hosting next week when we have the offers. Thanks everyone.
TRANSCRIPT;

	public function __construct(
		private readonly GoogleGeminiClient $gemini,
		private readonly ParticipantTracksStore $tracksStore,
		private readonly RecordingSummaryTemplateService $templateService,
		private readonly RecordingArtifactService $artifactService,
		private readonly Manager $manager,
		private readonly IRootFolder $rootFolder,
		private readonly IUserConfig $userConfig,
		private readonly IUserManager $userManager,
		private readonly IFactory $l10nFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Keeps the cleaned transcript, so the summary can be generated again
	 * with another template.
	 */
	public function storeTranscript(int $recordingFileId, string $transcript): void {
		$this->tracksStore->storeTranscriptMarkdown($recordingFileId, $transcript);
	}

	/**
	 * @throws GoogleApiException
	 */
	public function generate(string $ownerId, string $roomToken, int $recordingFileId, string $transcript, string $instructions): string {
		return $this->summarize(
			$transcript,
			$instructions,
			$this->getMeetingDetails($ownerId, $roomToken, $recordingFileId, $transcript),
		);
	}

	/**
	 * @throws GoogleApiException
	 */
	private function summarize(string $transcript, string $instructions, string $meeting): string {
		try {
			return $this->gemini->summarize($transcript, $instructions, $meeting);
		} catch (\InvalidArgumentException) {
			throw new GoogleApiException('Google AI is not configured');
		}
	}

	/**
	 * Generates the summary draft again with another template.
	 *
	 * @return array{id: string, type: string, state: string, fileName: string, content: string, etag: string, publishedFileId: ?string, publishedMessageId: ?string}
	 * @throws RecordingArtifactException
	 * @throws \InvalidArgumentException when the user can not use the template
	 */
	public function regenerate(Room $room, Participant $participant, string $artifactId, string $etag, string $templateId): array {
		$ownerId = $participant->getAttendee()->getActorId();
		$snapshot = $this->templateService->snapshot($templateId, $ownerId);

		return $this->artifactService->regenerate($room, $participant, $artifactId, $etag, function (RecordingArtifact $artifact) use ($ownerId, $snapshot): string {
			try {
				$transcript = $this->tracksStore->getTranscriptMarkdown($artifact->getRecordingFileId());
			} catch (NotFoundException) {
				throw new RecordingArtifactException(RecordingArtifactException::TRANSCRIPT_UNAVAILABLE);
			}

			try {
				$summary = $this->generate($ownerId, $artifact->getRoomToken(), $artifact->getRecordingFileId(), $transcript, $snapshot['instructions']);
			} catch (GoogleApiException $e) {
				$this->logger->warning('Recording summary could not be regenerated', ['exception' => $e]);
				throw new RecordingArtifactException(RecordingArtifactException::GENERATION);
			}

			$this->templateService->replaceSnapshot($artifact->getRecordingFileId(), $ownerId, $snapshot);
			return $summary . "\n\n" . $this->getWarning($ownerId) . "\n";
		});
	}

	/**
	 * Summarizes a sample meeting with the template, to try it before using it.
	 *
	 * @param array<string, mixed> $definition
	 * @throws \InvalidArgumentException with the name of the invalid field
	 * @throws GoogleApiException
	 */
	public function preview(array $definition): string {
		$instructions = SummaryTemplateDefinition::compile(SummaryTemplateDefinition::normalize($definition));
		$meeting = implode("\n", [
			'Conversation: Website relaunch',
			'Date: ' . (new \DateTimeImmutable('monday this week'))->format('Y-m-d (l)'),
			'Duration: about 2 minutes',
			'Speakers: Alice, Bob, Carol',
		]);
		return $this->summarize(self::SAMPLE_TRANSCRIPT, $instructions, $meeting);
	}

	public function getSampleTranscript(): string {
		return self::SAMPLE_TRANSCRIPT;
	}

	private function getMeetingDetails(string $ownerId, string $roomToken, int $recordingFileId, string $transcript): string {
		$details = [];
		try {
			$room = $this->manager->getRoomForUserByToken($roomToken, $ownerId);
			$details[] = 'Conversation: ' . $room->getDisplayName($ownerId);
		} catch (RoomNotFoundException) {
			// The conversation name is optional.
		}

		try {
			$recording = $this->rootFolder->getUserFolder($ownerId)->getFirstNodeById($recordingFileId);
		} catch (\Exception) {
			// The date is optional.
			$recording = null;
		}
		if ($recording !== null) {
			$timeZone = $this->userConfig->getValueString($ownerId, 'core', 'timezone', 'UTC');
			try {
				$date = (new \DateTimeImmutable('@' . $recording->getMTime()))->setTimezone(new \DateTimeZone($timeZone));
			} catch (\Exception) {
				$date = new \DateTimeImmutable('@' . $recording->getMTime());
			}
			$details[] = 'Date: ' . $date->format('Y-m-d (l)');
		}

		preg_match_all(self::HEADER, $transcript, $headers, PREG_SET_ORDER);
		if ($headers !== []) {
			$seconds = array_map(static function (array $header): int {
				$parts = array_map('intval', explode(':', $header[2]));
				return array_reduce($parts, static fn (int $carry, int $part): int => $carry * 60 + $part, 0);
			}, $headers);
			$details[] = 'Duration: about ' . max(1, (int)round(max($seconds) / 60)) . ' minutes';
			$speakers = array_unique(array_map(static fn (array $header): string => stripslashes($header[1]), $headers));
			$details[] = 'Speakers: ' . implode(', ', $speakers);
		}

		return implode("\n", $details);
	}

	private function getWarning(string $ownerId): string {
		$user = $this->userManager->get($ownerId);
		$l = $this->l10nFactory->get(Application::APP_ID, $this->l10nFactory->getUserLanguage($user));
		return $l->t('Summary is AI generated and may contain mistakes');
	}
}
