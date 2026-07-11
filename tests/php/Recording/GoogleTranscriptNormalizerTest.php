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
}
