<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\Recording;

use OCA\Talk\Recording\GoogleTranscriptNormalizer;
use Test\TestCase;

class GoogleTranscriptNormalizerTest extends TestCase {
	public function testCorrelatesDiarizedWordsWithTalkTimeline(): void {
		$response = [
			'results' => [
				'gs://bucket/file' => [
					'transcript' => [
						'results' => [[
							'alternatives' => [[
								'words' => [
									['word' => 'Hello', 'startOffset' => '1s', 'endOffset' => '1.5s', 'speakerLabel' => '1'],
									['word' => 'there', 'startOffset' => '1.5s', 'endOffset' => '2s', 'speakerLabel' => '1'],
									['word' => 'Hi', 'startOffset' => '3s', 'endOffset' => '3.5s', 'speakerLabel' => '2'],
								],
							]],
						]],
					],
				],
			],
		];
		$timeline = ['version' => 1, 'events' => [
			['time' => 0.5, 'speaking' => true, 'peerId' => 'a', 'displayName' => 'Alice'],
			['time' => 2.5, 'speaking' => false, 'peerId' => 'a'],
			['time' => 2.8, 'speaking' => true, 'peerId' => 'b', 'displayName' => 'Bob'],
			['time' => 4.0, 'speaking' => false, 'peerId' => 'b'],
		]];

		$this->assertSame("**Alice** · 00:01\nHello there\n\n**Bob** · 00:03\nHi", (new GoogleTranscriptNormalizer())->toMarkdown($response, $timeline));
	}

	public function testKeepsAnonymousLabelWhenTimelineIsAmbiguous(): void {
		$response = ['results' => [['results' => [['alternatives' => [['words' => [
			['word' => 'Hello', 'startOffset' => '1s', 'endOffset' => '2s', 'speakerLabel' => '3'],
		]]]]]]]];
		$this->assertSame("**Speaker 3** · 00:01\nHello", (new GoogleTranscriptNormalizer())->toMarkdown($response, null));
	}

	public function testAttributesWordsIndividuallyWhenDiarizationLabelDoesNotChange(): void {
		$response = ['results' => [['results' => [['alternatives' => [['words' => [
			['word' => 'Hello', 'startOffset' => '1s', 'endOffset' => '1.5s', 'speakerLabel' => '1'],
			['word' => 'Hi', 'startOffset' => '3s', 'endOffset' => '3.5s', 'speakerLabel' => '1'],
		]]]]]]]];
		$timeline = ['version' => 1, 'events' => [
			['time' => 0.8, 'speaking' => true, 'peerId' => 'a', 'sessionId' => 'session-a', 'displayName' => 'Alice'],
			['time' => 1.7, 'speaking' => false, 'peerId' => 'a'],
			['time' => 2.8, 'speaking' => true, 'peerId' => 'b', 'sessionId' => 'session-b', 'displayName' => 'Bob'],
			['time' => 3.7, 'speaking' => false, 'peerId' => 'b'],
		]];

		$this->assertSame("**Alice** · 00:01\nHello\n\n**Bob** · 00:03\nHi", (new GoogleTranscriptNormalizer())->toMarkdown($response, $timeline));
	}

	public function testAppliesRecordingClockOffset(): void {
		$response = ['results' => [['results' => [['alternatives' => [['words' => [
			['word' => 'Hello', 'startOffset' => '2.2s', 'endOffset' => '2.6s', 'speakerLabel' => '1'],
		]]]]]]]];
		$timeline = ['version' => 1, 'recordingOffset' => 2.0, 'clockUncertainty' => 0.1, 'events' => [
			['time' => 0.0, 'speaking' => true, 'peerId' => 'a', 'sessionId' => 'session-a', 'displayName' => 'Alice'],
			['time' => 1.0, 'speaking' => false, 'peerId' => 'a'],
		]];

		$this->assertSame("**Alice** · 00:02\nHello", (new GoogleTranscriptNormalizer())->toMarkdown($response, $timeline));
	}

	public function testDoesNotCombineDifferentParticipantsWithSameDisplayName(): void {
		$response = ['results' => [['results' => [['alternatives' => [['words' => [
			['word' => 'Hello', 'startOffset' => '1s', 'endOffset' => '2s', 'speakerLabel' => '4'],
		]]]]]]]];
		$timeline = ['version' => 1, 'events' => [
			['time' => 1.0, 'speaking' => true, 'peerId' => 'a', 'sessionId' => 'session-a', 'displayName' => 'Alex'],
			['time' => 2.0, 'speaking' => false, 'peerId' => 'a'],
			['time' => 1.0, 'speaking' => true, 'peerId' => 'b', 'sessionId' => 'session-b', 'displayName' => 'Alex'],
			['time' => 2.0, 'speaking' => false, 'peerId' => 'b'],
		]];

		$this->assertSame("**Speaker 4** · 00:01\nHello", (new GoogleTranscriptNormalizer())->toMarkdown($response, $timeline));
	}
}
