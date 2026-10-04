<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\Recording;

use OCA\Talk\Recording\MultitrackTranscriptMerger;
use Test\TestCase;

class MultitrackTranscriptMergerTest extends TestCase {
	private MultitrackTranscriptMerger $merger;

	protected function setUp(): void {
		parent::setUp();
		$this->merger = new MultitrackTranscriptMerger();
	}

	/**
	 * Words of a sentence said from the given time, 0.4 s per word.
	 *
	 * @return list<array{text: string, start: float, end: float}>
	 */
	private static function say(float $start, string $text): array {
		$words = [];
		foreach (explode(' ', $text) as $index => $word) {
			$words[] = ['text' => $word, 'start' => $start + $index * 0.4, 'end' => $start + $index * 0.4 + 0.3];
		}
		return $words;
	}

	/** @return list<array{string, string}> speaker and text of each turn */
	private static function summary(array $turns): array {
		return array_map(static fn (array $turn): array => [$turn['speaker'], $turn['text']], $turns);
	}

	public function testToRecordingTimeAppliesCutMap(): void {
		$cutMap = [
			['chunkStart' => 0.0, 'recordingStart' => 7.6, 'duration' => 5.0],
			['chunkStart' => 6.0, 'recordingStart' => 21.6, 'duration' => 4.0],
		];
		$words = $this->merger->toRecordingTime([
			['text' => 'a', 'start' => 1.0, 'end' => 1.5],
			['text' => 'b', 'start' => 6.5, 'end' => 7.0],
			// Inside the silence between the pieces: clamped to the first one.
			['text' => 'c', 'start' => 5.2, 'end' => 5.4],
			// Beyond the end of the audio.
			['text' => 'd', 'start' => 11.0, 'end' => 11.5],
		], $cutMap);

		$this->assertSame([
			['text' => 'a', 'start' => 8.6, 'end' => 9.1],
			['text' => 'b', 'start' => 22.1, 'end' => 22.6],
			['text' => 'c', 'start' => 12.6, 'end' => 12.6],
			['text' => 'd', 'start' => 25.6, 'end' => 25.6],
		], $words);
	}

	public function testInterleavedTurns(): void {
		$turns = $this->merger->merge([
			'alice' => array_merge(self::say(1, 'Good morning everyone.'), self::say(10, 'Right.')),
			'bob' => self::say(5, 'Thanks Alice, let us start.'),
		]);

		$this->assertSame([
			['alice', 'Good morning everyone.'],
			['bob', 'Thanks Alice, let us start.'],
			['alice', 'Right.'],
		], self::summary($turns));
		$this->assertSame(1.0, $turns[0]['start']);
		$this->assertSame(5.0, $turns[1]['start']);
	}

	public function testBackchannelDoesNotSplitSentence(): void {
		$turns = $this->merger->merge([
			'alice' => self::say(0, 'We already committed half of the budget to the migration project.'),
			'bob' => self::say(2, 'Yes.'),
		]);

		$this->assertSame([
			['alice', 'We already committed half of the budget to the migration project.'],
			['bob', 'Yes.'],
		], self::summary($turns));
	}

	public function testInterruptionSplitsAtNextSentenceEnd(): void {
		$turns = $this->merger->merge([
			'alice' => self::say(0, 'How much more? Because the budget has to cover monitoring. And that is not optional.'),
			'bob' => self::say(2.5, 'Sorry to interrupt, monitoring is covered.'),
		]);

		// Bob starts during "Because …", so Alice's turn is split after
		// "monitoring." and her last sentence follows Bob's interruption.
		$this->assertSame([
			['alice', 'How much more? Because the budget has to cover monitoring.'],
			['bob', 'Sorry to interrupt, monitoring is covered.'],
			['alice', 'And that is not optional.'],
		], self::summary($turns));
	}

	public function testPausesSplitUtterancesButJoinConsecutiveTurns(): void {
		$turns = $this->merger->merge([
			'alice' => array_merge(self::say(0, 'First part.'), self::say(3, 'Second part.'), self::say(20, 'Much later.')),
			'bob' => self::say(10, 'Something else entirely.'),
		]);

		$this->assertSame([
			['alice', 'First part. Second part.'],
			['bob', 'Something else entirely.'],
			['alice', 'Much later.'],
		], self::summary($turns));
	}

	public function testEchoIsDropped(): void {
		$turns = $this->merger->merge([
			'alice' => self::say(0, 'The numbers are ready by Friday morning.'),
			// Alice's voice leaking into Bob's microphone.
			'bob' => [['text' => 'Friday', 'start' => 2.05, 'end' => 2.3], ['text' => 'morning.', 'start' => 2.45, 'end' => 2.7]],
		]);

		$this->assertSame([['alice', 'The numbers are ready by Friday morning.']], self::summary($turns));
	}

	public function testShortAnswerWithSameWordIsNotEcho(): void {
		$turns = $this->merger->merge([
			'alice' => self::say(0, 'Can you send them by Friday?'),
			'bob' => self::say(3, 'Friday.'),
		]);

		$this->assertCount(2, $turns);
	}

	public function testRejoinedParticipantKeepsSpeaker(): void {
		$turns = $this->merger->merge([
			'actor:users:alice' => array_merge(self::say(0, 'Before reconnecting.'), self::say(60, 'After reconnecting.')),
			'actor:users:bob' => self::say(30, 'Alice dropped out.'),
		]);

		$this->assertSame(['actor:users:alice', 'actor:users:bob', 'actor:users:alice'], array_column($turns, 'speaker'));
	}

	public function testLongMonologueIsSplitInTurns(): void {
		$words = [];
		for ($second = 0.0; $second < 300; $second += 2.5) {
			$words = array_merge($words, self::say($second, 'This is one more sentence.'));
		}

		$turns = $this->merger->merge(['alice' => $words]);

		$this->assertGreaterThan(2, count($turns));
		foreach ($turns as $turn) {
			$this->assertLessThanOrEqual(MultitrackTranscriptMerger::MAX_TURN_DURATION, $turn['end'] - $turn['start']);
		}
	}

	public function testNoWords(): void {
		$this->assertSame([], $this->merger->merge(['alice' => []]));
	}
}
