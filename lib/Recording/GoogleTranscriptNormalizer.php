<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Recording;

class GoogleTranscriptNormalizer {
	/**
	 * @param array<string, mixed> $response
	 * @param array<string, mixed>|null $timeline
	 */
	public function toMarkdown(array $response, ?array $timeline): string {
		$segments = $this->getSegments($response);
		$intervals = $this->getSpeakerIntervals($timeline);
		$blocks = [];
		foreach ($segments as $segment) {
			$name = $this->matchSpeaker($segment, $intervals) ?? 'Speaker ' . $segment['speaker'];
			$minutes = intdiv((int)$segment['start'], 60);
			$seconds = (int)$segment['start'] % 60;
			$blocks[] = sprintf("**%s** · %02d:%02d\n%s", $this->escape($name), $minutes, $seconds, $this->escape($segment['text']));
		}
		return implode("\n\n", $blocks);
	}

	/** @return list<array{start: float, end: float, speaker: string, text: string}> */
	private function getSegments(array $response): array {
		$segments = [];
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
						$segments[] = ['start' => 0.0, 'end' => 0.0, 'speaker' => '1', 'text' => $text];
					}
					continue;
				}
				foreach ($words as $word) {
					$speaker = (string)($word['speakerLabel'] ?? '1');
					$start = $this->seconds($word['startOffset'] ?? '0s');
					$end = $this->seconds($word['endOffset'] ?? $word['startOffset'] ?? '0s');
					$last = array_key_last($segments);
					if ($last === null || $segments[$last]['speaker'] !== $speaker) {
						$segments[] = ['start' => $start, 'end' => $end, 'speaker' => $speaker, 'text' => ''];
						$last = array_key_last($segments);
					}
					$segments[$last]['end'] = $end;
					$segments[$last]['text'] = trim($segments[$last]['text'] . ' ' . (string)($word['word'] ?? ''));
				}
			}
		}
		return array_values(array_filter($segments, fn (array $segment): bool => $segment['text'] !== ''));
	}

	/** @return list<array{start: float, end: float, name: string}> */
	private function getSpeakerIntervals(?array $timeline): array {
		if (($timeline['version'] ?? null) !== 1 || !is_array($timeline['events'] ?? null)) {
			return [];
		}
		$active = [];
		$intervals = [];
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
					'start' => (float)$start['time'],
					'end' => (float)$event['time'],
					'name' => (string)($start['displayName'] ?? $start['userId'] ?? $start['actorId'] ?? $peerId),
				];
				unset($active[$peerId]);
			}
		}
		return $intervals;
	}

	/**
	 * @param array{start: float, end: float, speaker: string, text: string} $segment
	 * @param list<array{start: float, end: float, name: string}> $intervals
	 */
	private function matchSpeaker(array $segment, array $intervals): ?string {
		$overlap = [];
		foreach ($intervals as $interval) {
			$seconds = max(0.0, min($segment['end'], $interval['end']) - max($segment['start'], $interval['start']));
			$overlap[$interval['name']] = ($overlap[$interval['name']] ?? 0.0) + $seconds;
		}
		arsort($overlap, SORT_NUMERIC);
		$names = array_keys($overlap);
		$best = $overlap[$names[0] ?? ''] ?? 0.0;
		$second = $overlap[$names[1] ?? ''] ?? 0.0;
		$duration = max(0.1, $segment['end'] - $segment['start']);
		return $best / $duration >= 0.5 && $best >= $second * 1.5 ? ($names[0] ?? null) : null;
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
