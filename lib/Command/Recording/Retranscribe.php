<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Command\Recording;

use OC\Core\Command\Base;
use OCA\Talk\BackgroundJob\SubmitRecordingAi;
use OCA\Talk\Model\RecordingAiOperation;
use OCA\Talk\Model\RecordingAiOperationMapper;
use OCA\Talk\Recording\GoogleAiConfig;
use OCA\Talk\Recording\MultitrackTranscriptService;
use OCA\Talk\Recording\ParticipantTracksStore;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Transcribes a recording again, from the kept participant tracks if there
 * are any, and otherwise from the mixed recording.
 */
class Retranscribe extends Base {
	private const FINISHED_STATES = [
		RecordingAiOperation::STATE_COMPLETED,
		RecordingAiOperation::STATE_FAILED,
		RecordingAiOperation::STATE_CANCELLED,
	];

	public function __construct(
		private readonly RecordingAiOperationMapper $mapper,
		private readonly ParticipantTracksStore $tracksStore,
		private readonly MultitrackTranscriptService $multitrack,
		private readonly GoogleAiConfig $config,
		private readonly ITimeFactory $timeFactory,
		private readonly IJobList $jobList,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		parent::configure();
		$this
			->setName('talk:recording:retranscribe')
			->setDescription('Transcribe (and summarize) a call recording again, from the kept audio of the participants if available')
			->addArgument(
				'recording-file-id',
				InputArgument::REQUIRED,
				'File id of the recording, as shown in the "Failed to transcript call recording" notification or the files app'
			)
			->addOption(
				'mixed',
				null,
				InputOption::VALUE_NONE,
				'Transcribe the mixed recording even if the audio of the participants is kept'
			)
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$recordingFileId = (int)$input->getArgument('recording-file-id');
		$mixed = (bool)$input->getOption('mixed');

		try {
			$operation = $this->mapper->findByRecordingFileId($recordingFileId);
		} catch (DoesNotExistException) {
			$output->writeln('<error>The recording was never transcribed, so it can not be transcribed again</error>');
			return 1;
		}

		if (!in_array($operation->getState(), self::FINISHED_STATES, true)) {
			$output->writeln('<error>The recording is still being processed (' . $operation->getState() . '), try again once it finished</error>');
			return 1;
		}

		if (!$mixed && $this->tracksStore->hasRejectedUpload($recordingFileId)) {
			try {
				$skippedPieces = $this->tracksStore->importRejectedUpload($recordingFileId);
				$output->writeln('<info>Imported the participant tracks that were rejected at upload' . ($skippedPieces > 0 ? ' (' . $skippedPieces . ' invalid pieces skipped)' : '') . '</info>');
			} catch (\Throwable $e) {
				$output->writeln('<comment>The participant tracks that were rejected at upload still can not be imported: ' . $e->getMessage() . '</comment>');
			}
		}

		$useTracks = !$mixed && $this->tracksStore->has($recordingFileId);
		if ($useTracks && !$this->config->isMultitrackEnabled()) {
			$output->writeln('<comment>Transcription per participant is disabled in the admin settings, so the mixed recording is transcribed</comment>');
			$useTracks = false;
		} elseif (!$mixed && !$useTracks) {
			$output->writeln('<comment>No participant tracks are kept for this recording, so the mixed recording is transcribed</comment>');
		}

		$this->multitrack->reset($operation);

		$now = $this->timeFactory->getDateTime();
		$deadline = clone $now;
		$deadline->add(new \DateInterval('P1D'));
		$operation->setState(RecordingAiOperation::STATE_QUEUED);
		$operation->setSkipTracks(!$useTracks);
		if ($useTracks) {
			// A stored mixed upload would make the processing skip the tracks;
			// the bucket lifecycle deletes the object.
			$operation->setGcsObject(null);
		}
		$operation->setSpeechOperation(null);
		$operation->setSpeechResponse(null);
		$operation->setTranscript(null);
		$operation->setAttempts(0);
		$operation->setLastErrorCode(null);
		$operation->setLastErrorMessage(null);
		$operation->setClaimToken(null);
		$operation->setClaimUntil(null);
		$operation->setNextAttemptAt($now);
		$operation->setDeadlineAt($deadline);
		$operation->setUpdatedAt($now);
		$this->mapper->update($operation);
		$this->jobList->add(SubmitRecordingAi::class, ['operationId' => (int)$operation->getId()]);

		$output->writeln('<info>The recording is transcribed again from ' . ($useTracks ? 'the audio of the participants' : 'the mixed recording') . ' by the next background job run. The transcript and the summary are replaced, and the owner is notified.</info>');
		return 0;
	}
}
