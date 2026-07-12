<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Recording;

class GoogleTranscriptNormalizer {
	private const SPEAKING_ONSET_PADDING = 0.25;
	private const SPEAKING_RELEASE_PADDING = 0.5;
	private const MAX_MERGE_GAP = 1.5;

	/**
	 * @param array<string, mixed> $response
	 * @param array<string, mixed>|null $timeline
	 */
	public function toMarkdown(array $response, ?array $timeline): string {
		$words = $this->getWords($response);
		$intervals = $this->getSpeakerIntervals($timeline);
		$segments = [];
		foreach ($words as $word) {
			$match = $this->matchSpeaker($word, $intervals);
			$identity = $match['identity'] ?? 'diarized:' . $word['speaker'];
			$name = $match['name'] ?? 'Speaker ' . $word['speaker'];
			$last = array_key_last($segments);
			if ($last === null
				|| $segments[$last]['identity'] !== $identity
				|| $word['start'] - $segments[$last]['end'] > self::MAX_MERGE_GAP) {
				$segments[] = [
					'start' => $word['start'],
					'end' => $word['end'],
					'identity' => $identity,
					'name' => $name,
					'text' => $word['text'],
				];
				continue;
			}
			$segments[$last]['end'] = $word['end'];
			$segments[$last]['text'] = trim($segments[$last]['text'] . ' ' . $word['text']);
		}
		$blocks = [];
		foreach ($segments as $segment) {
			$minutes = intdiv((int)$segment['start'], 60);
			$seconds = (int)$segment['start'] % 60;
			$blocks[] = sprintf("**%s** · %02d:%02d\n%s", $this->escape($segment['name']), $minutes, $seconds, $this->escape($segment['text']));
		}
		return implode("\n\n", $blocks);
	}

	/** @return list<array{start: float, end: float, speaker: string, text: string}> */
	private function getWords(array $response): array {
		$normalizedWords = [];
		foreach (($response['results'] ?? []) as $fileResult) {
			$results = $fileResult['transcript']['results'] ?? $fileResult['results'] ?? [];
			foreach ($results as $result) {
				$alternative = $result['alternatives'][0] ?? null;
				if (!is_array($alternative)) {
					continue;
				}
				$words = $alternative['words'] ?? [];
				if ($words === []) {
					$text = trim((string)($alternative['transcript'] ?? ''));
					if ($text !== '') {
						$normalizedWords[] = ['start' => 0.0, 'end' => 0.0, 'speaker' => '1', 'text' => $text];
					}
					continue;
				}
				foreach ($words as $word) {
					$text = trim((string)($word['word'] ?? ''));
					if ($text === '') {
						continue;
					}
					$normalizedWords[] = [
						'start' => $this->seconds($word['startOffset'] ?? '0s'),
						'end' => $this->seconds($word['endOffset'] ?? $word['startOffset'] ?? '0s'),
						'speaker' => (string)($word['speakerLabel'] ?? '1'),
						'text' => $text,
					];
				}
			}
		}
		return $normalizedWords;
	}

	/** @return list<array{start: float, end: float, identity: string, name: string}> */
	private function getSpeakerIntervals(?array $timeline): array {
		if (($timeline['version'] ?? null) !== 1 || !is_array($timeline['events'] ?? null)) {
			return [];
		}
		$active = [];
		$intervals = [];
		$offset = max(0.0, (float)($timeline['recordingOffset'] ?? 0.0));
		$uncertainty = max(0.0, (float)($timeline['clockUncertainty'] ?? 0.0));
		foreach ($timeline['events'] as $event) {
			if (!is_array($event) || !is_string($event['peerId'] ?? null) || !is_bool($event['speaking'] ?? null) || !is_numeric($event['time'] ?? null)) {
				continue;
			}
			$peerId = $event['peerId'];
			if ($event['speaking']) {
				$active[$peerId] = $event;
			} elseif (isset($active[$peerId])) {
				$start = $active[$peerId];
				$intervals[] = [
					'start' => max(0.0, (float)$start['time'] + $offset - self::SPEAKING_ONSET_PADDING - $uncertainty),
					'end' => (float)$event['time'] + $offset + self::SPEAKING_RELEASE_PADDING + $uncertainty,
					'identity' => $this->getIdentity($start, $peerId),
					'name' => (string)($start['displayName'] ?? $start['userId'] ?? $start['actorId'] ?? $peerId),
				];
				unset($active[$peerId]);
			}
		}
		return $intervals;
	}

	/** @param array<string, mixed> $event */
	private function getIdentity(array $event, string $peerId): string {
		if (is_string($event['sessionId'] ?? null) && $event['sessionId'] !== '') {
			return 'session:' . $event['sessionId'];
		}
		if (is_string($event['actorType'] ?? null) && is_string($event['actorId'] ?? null) && $event['actorId'] !== '') {
			return 'actor:' . $event['actorType'] . ':' . $event['actorId'];
		}
		return 'peer:' . $peerId;
	}

	/**
	 * @param array{start: float, end: float, speaker: string, text: string} $word
	 * @param list<array{start: float, end: float, identity: string, name: string}> $intervals
	 * @return array{identity: string, name: string}|null
	 */
	private function matchSpeaker(array $word, array $intervals): ?array {
		$overlap = [];
		$names = [];
		foreach ($intervals as $interval) {
			$seconds = max(0.0, min($word['end'], $interval['end']) - max($word['start'], $interval['start']));
			$overlap[$interval['identity']] = ($overlap[$interval['identity']] ?? 0.0) + $seconds;
			$names[$interval['identity']] = $interval['name'];
		}
		arsort($overlap, SORT_NUMERIC);
		$identities = array_keys($overlap);
		$identity = $identities[0] ?? null;
		$best = $overlap[$identity ?? ''] ?? 0.0;
		$second = $overlap[$identities[1] ?? ''] ?? 0.0;
		$duration = max(0.1, $word['end'] - $word['start']);
		return $identity !== null && $best / $duration >= 0.5 && $best >= $second * 1.5
			? ['identity' => $identity, 'name' => $names[$identity]]
			: null;
	}

	private function seconds(mixed $offset): float {
		return is_string($offset) && preg_match('/^([0-9]+(?:\.[0-9]+)?)s$/', $offset, $matches)
			? (float)$matches[1]
			: 0.0;
	}

	private function escape(string $value): string {
		return str_replace(['\\', '*', '_', '`'], ['\\\\', '\\*', '\\_', '\\`'], $value);
	}
}
