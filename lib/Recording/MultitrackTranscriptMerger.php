<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Recording;

/**
 * Merges the words transcribed from each participant track in a transcript.
 *
 * The speaker of every word is known, as each track contains a single
 * participant, so the merge only decides how the words are grouped in turns:
 * - The words of a speaker are split in utterances at pauses (and long
 *   monologues at sentence ends).
 * - Utterances that only repeat words said at the same time by another
 *   participant (echo picked up by a microphone) are dropped.
 * - When another participant starts a real utterance (not just a short
 *   backchannel like "yes") while someone is talking, the ongoing utterance is
 *   split at the next sentence end, so the transcript follows the
 *   conversation. Backchannels never split a sentence.
 * - Utterances are sorted by start, and consecutive utterances of the same
 *   speaker are joined.
 *
 * All the times are in seconds since the start of the recording file.
 */
class MultitrackTranscriptMerger {
	public const PAUSE = 1.5;
	public const JOIN_GAP = 3.0;
	public const BACKCHANNEL_WORDS = 3;
	public const ECHO_TOLERANCE = 0.3;
	public const MAX_TURN_DURATION = 120.0;

	/**
	 * Converts the times of the words of a chunk to recording times.
	 *
	 * @param list<array{text: string, start: float, end: float}> $words times since the start of the chunk
	 * @param list<array{chunkStart: float, recordingStart: float, duration: float}> $cutMap
	 * @return list<array{text: string, start: float, end: float}>
	 */
	public function toRecordingTime(array $words, array $cutMap): array {
		$converted = [];
		foreach ($words as $word) {
			$piece = $this->findPiece($cutMap, $word['start']);
			$offset = $piece['recordingStart'] - $piece['chunkStart'];
			// Words in the silence between pieces (or slightly outside the
			// audio) are clamped to the nearest piece.
			$start = min(max($word['start'], $piece['chunkStart']), $piece['chunkStart'] + $piece['duration']);
			$end = max($start, min($word['end'], $piece['chunkStart'] + $piece['duration']));
			$converted[] = [
				'text' => $word['text'],
				'start' => round($start + $offset, 3),
				'end' => round($end + $offset, 3),
			];
		}
		return $converted;
	}

	/**
	 * @param list<array{chunkStart: float, recordingStart: float, duration: float}> $cutMap
	 * @return array{chunkStart: float, recordingStart: float, duration: float}
	 */
	private function findPiece(array $cutMap, float $time): array {
		$best = $cutMap[0];
		$bestDistance = PHP_FLOAT_MAX;
		foreach ($cutMap as $piece) {
			if ($time < $piece['chunkStart']) {
				$distance = $piece['chunkStart'] - $time;
			} elseif ($time > $piece['chunkStart'] + $piece['duration']) {
				$distance = $time - $piece['chunkStart'] - $piece['duration'];
			} else {
				return $piece;
			}
			if ($distance < $bestDistance) {
				$best = $piece;
				$bestDistance = $distance;
			}
		}
		return $best;
	}

	/**
	 * @param array<string, list<array{text: string, start: float, end: float}>> $wordsBySpeaker recording times
	 * @return list<array{speaker: string, start: float, end: float, text: string, words: list<array{text: string, start: float, end: float}>}>
	 */
	public function merge(array $wordsBySpeaker): array {
		$utterances = [];
		foreach ($wordsBySpeaker as $speaker => $words) {
			usort($words, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
			foreach ($this->splitAtPauses($words) as $utteranceWords) {
				$utterances[] = ['speaker' => (string)$speaker, 'words' => $utteranceWords];
			}
		}

		$utterances = $this->removeEcho($utterances);
		$utterances = $this->splitAtInterruptions($utterances);
		usort($utterances, static fn (array $a, array $b): int => [$a['words'][0]['start'], $a['speaker']] <=> [$b['words'][0]['start'], $b['speaker']]);

		$turns = [];
		foreach ($utterances as $utterance) {
			$last = array_key_last($turns);
			$start = $utterance['words'][0]['start'];
			if ($last !== null
				&& $turns[$last]['speaker'] === $utterance['speaker']
				&& $start - end($turns[$last]['words'])['end'] <= self::JOIN_GAP
				&& end($utterance['words'])['end'] - $turns[$last]['words'][0]['start'] <= self::MAX_TURN_DURATION) {
				$turns[$last]['words'] = array_merge($turns[$last]['words'], $utterance['words']);
				continue;
			}
			$turns[] = $utterance;
		}

		return array_map(static fn (array $turn): array => [
			'speaker' => $turn['speaker'],
			'start' => $turn['words'][0]['start'],
			'end' => max(array_column($turn['words'], 'end')),
			'text' => implode(' ', array_column($turn['words'], 'text')),
			'words' => $turn['words'],
		], $turns);
	}

	/**
	 * @param list<array{text: string, start: float, end: float}> $words sorted by start
	 * @return list<list<array{text: string, start: float, end: float}>>
	 */
	private function splitAtPauses(array $words): array {
		$utterances = [];
		$current = [];
		$currentEnd = 0.0;
		foreach ($words as $word) {
			if ($current !== []
				&& ($word['start'] - $currentEnd > self::PAUSE
					|| $word['end'] - $current[0]['start'] > self::MAX_TURN_DURATION)) {
				$utterances[] = $current;
				$current = [];
			}
			$current[] = $word;
			$currentEnd = max($currentEnd, $word['end']);
			// Long monologues are split at a sentence end.
			if ($currentEnd - $current[0]['start'] >= self::MAX_TURN_DURATION / 2 && $this->endsSentence($word['text'])) {
				$utterances[] = $current;
				$current = [];
			}
		}
		if ($current !== []) {
			$utterances[] = $current;
		}
		return $utterances;
	}

	/**
	 * Drops short utterances whose words were all said at the same time by
	 * another participant in a longer utterance.
	 *
	 * @param list<array{speaker: string, words: list<array{text: string, start: float, end: float}>}> $utterances
	 * @return list<array{speaker: string, words: list<array{text: string, start: float, end: float}>}>
	 */
	private function removeEcho(array $utterances): array {
		$kept = [];
		foreach ($utterances as $index => $utterance) {
			if (count($utterance['words']) > self::BACKCHANNEL_WORDS || !$this->isEcho($utterance, $index, $utterances)) {
				$kept[] = $utterance;
			}
		}
		return $kept;
	}

	/**
	 * @param array{speaker: string, words: list<array{text: string, start: float, end: float}>} $utterance
	 * @param list<array{speaker: string, words: list<array{text: string, start: float, end: float}>}> $utterances
	 */
	private function isEcho(array $utterance, int $index, array $utterances): bool {
		foreach ($utterances as $otherIndex => $other) {
			if ($otherIndex === $index
				|| $other['speaker'] === $utterance['speaker']
				|| count($other['words']) <= count($utterance['words'])) {
				continue;
			}
			$allMatched = true;
			foreach ($utterance['words'] as $word) {
				if (!$this->hasMatchingWord($word, $other['words'])) {
					$allMatched = false;
					break;
				}
			}
			if ($allMatched) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array{text: string, start: float, end: float} $word
	 * @param list<array{text: string, start: float, end: float}> $words
	 */
	private function hasMatchingWord(array $word, array $words): bool {
		$text = $this->normalize($word['text']);
		foreach ($words as $candidate) {
			if (abs($candidate['start'] - $word['start']) <= self::ECHO_TOLERANCE && $this->normalize($candidate['text']) === $text) {
				return true;
			}
		}
		return false;
	}

	private function endsSentence(string $text): bool {
		return preg_match('/[.?!…]["\')\]]*$/u', $text) === 1;
	}

	private function normalize(string $text): string {
		return mb_strtolower((string)preg_replace('/[^\p{L}\p{N}]+/u', '', $text));
	}

	/**
	 * @param list<array{speaker: string, words: list<array{text: string, start: float, end: float}>}> $utterances
	 * @return list<array{speaker: string, words: list<array{text: string, start: float, end: float}>}>
	 */
	private function splitAtInterruptions(array $utterances): array {
		$interruptions = [];
		foreach ($utterances as $utterance) {
			if (count($utterance['words']) > self::BACKCHANNEL_WORDS) {
				$interruptions[] = ['speaker' => $utterance['speaker'], 'start' => $utterance['words'][0]['start']];
			}
		}

		$result = [];
		foreach ($utterances as $utterance) {
			$splitAfter = [];
			foreach ($interruptions as $interruption) {
				if ($interruption['speaker'] === $utterance['speaker']
					|| $interruption['start'] <= $utterance['words'][0]['start']
					|| $interruption['start'] >= end($utterance['words'])['end']) {
					continue;
				}
				foreach ($utterance['words'] as $wordIndex => $word) {
					if ($word['end'] >= $interruption['start'] && $this->endsSentence($word['text'])) {
						$splitAfter[$wordIndex] = true;
						break;
					}
				}
			}

			$current = [];
			$lastIndex = count($utterance['words']) - 1;
			foreach ($utterance['words'] as $wordIndex => $word) {
				$current[] = $word;
				if (isset($splitAfter[$wordIndex]) && $wordIndex < $lastIndex) {
					$result[] = ['speaker' => $utterance['speaker'], 'words' => $current];
					$current = [];
				}
			}
			$result[] = ['speaker' => $utterance['speaker'], 'words' => $current];
		}
		return $result;
	}
}
