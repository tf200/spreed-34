<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Recording;

/**
 * The structured options of a summary template, and the instructions for the
 * model compiled from them.
 *
 * @psalm-type SummaryTemplateSection = array{title: string, description: string}
 * @psalm-type SummaryTemplateDefinitionArray = array{
 *     sections: list<SummaryTemplateSection>,
 *     length: 'brief'|'standard'|'detailed',
 *     style: 'bullets'|'paragraphs',
 *     language: string,
 *     actionItemsTable: bool,
 *     transcriptLinks: bool,
 *     extraInstructions: string,
 * }
 */
class SummaryTemplateDefinition {
	public const MAX_SECTIONS = 12;
	public const MAX_SECTION_TITLE_LENGTH = 100;
	public const MAX_SECTION_DESCRIPTION_LENGTH = 500;
	public const MAX_EXTRA_INSTRUCTIONS_LENGTH = 4000;

	public const LENGTHS = ['brief', 'standard', 'detailed'];
	public const STYLES = ['bullets', 'paragraphs'];

	/**
	 * Languages a summary can be written in, besides the language of the
	 * meeting.
	 */
	public const LANGUAGES = [
		'ar' => 'Arabic',
		'cs' => 'Czech',
		'da' => 'Danish',
		'de' => 'German',
		'el' => 'Greek',
		'en' => 'English',
		'es' => 'Spanish',
		'fi' => 'Finnish',
		'fr' => 'French',
		'hu' => 'Hungarian',
		'it' => 'Italian',
		'ja' => 'Japanese',
		'ko' => 'Korean',
		'nb' => 'Norwegian',
		'nl' => 'Dutch',
		'pl' => 'Polish',
		'pt' => 'Portuguese',
		'ro' => 'Romanian',
		'ru' => 'Russian',
		'sv' => 'Swedish',
		'tr' => 'Turkish',
		'uk' => 'Ukrainian',
		'zh' => 'Chinese',
	];

	/**
	 * @return SummaryTemplateDefinitionArray
	 * @throws \InvalidArgumentException with the name of the invalid field
	 */
	public static function normalize(mixed $definition): array {
		if (!is_array($definition)) {
			throw new \InvalidArgumentException('definition');
		}

		$sections = $definition['sections'] ?? [];
		if (!is_array($sections) || !array_is_list($sections) || count($sections) > self::MAX_SECTIONS) {
			throw new \InvalidArgumentException('sections');
		}
		$normalizedSections = [];
		foreach ($sections as $section) {
			$title = is_array($section) && is_string($section['title'] ?? null) ? trim($section['title']) : '';
			$description = is_array($section) && is_string($section['description'] ?? '') ? trim($section['description'] ?? '') : null;
			if ($title === '' || mb_strlen($title) > self::MAX_SECTION_TITLE_LENGTH
				|| $description === null || mb_strlen($description) > self::MAX_SECTION_DESCRIPTION_LENGTH) {
				throw new \InvalidArgumentException('sections');
			}
			$normalizedSections[] = ['title' => $title, 'description' => $description];
		}

		$length = $definition['length'] ?? 'standard';
		if (!in_array($length, self::LENGTHS, true)) {
			throw new \InvalidArgumentException('length');
		}
		$style = $definition['style'] ?? 'bullets';
		if (!in_array($style, self::STYLES, true)) {
			throw new \InvalidArgumentException('style');
		}
		$language = $definition['language'] ?? '';
		if (!is_string($language) || ($language !== '' && !isset(self::LANGUAGES[$language]))) {
			throw new \InvalidArgumentException('language');
		}
		$extraInstructions = $definition['extraInstructions'] ?? '';
		if (!is_string($extraInstructions) || mb_strlen(trim($extraInstructions)) > self::MAX_EXTRA_INSTRUCTIONS_LENGTH) {
			throw new \InvalidArgumentException('extraInstructions');
		}
		$extraInstructions = trim($extraInstructions);
		if ($normalizedSections === [] && $extraInstructions === '') {
			// Nothing tells what the summary should contain.
			throw new \InvalidArgumentException('sections');
		}

		return [
			'sections' => $normalizedSections,
			'length' => $length,
			'style' => $style,
			'language' => $language,
			'actionItemsTable' => (bool)($definition['actionItemsTable'] ?? false),
			'transcriptLinks' => (bool)($definition['transcriptLinks'] ?? false),
			'extraInstructions' => $extraInstructions,
		];
	}

	/**
	 * Compiles the instructions for the model.
	 *
	 * @param SummaryTemplateDefinitionArray $definition
	 */
	public static function compile(array $definition): string {
		$lines = [];
		if ($definition['sections'] !== []) {
			$lines[] = 'Structure the summary in these sections, in this order, each with a level-2 Markdown heading ("## "):';
			foreach ($definition['sections'] as $index => $section) {
				$line = ($index + 1) . '. ' . $section['title'];
				if ($section['description'] !== '') {
					$line .= ': ' . $section['description'];
				}
				$lines[] = $line;
			}
			$lines[] = 'Translate the headings into the language of the summary. If nothing in the transcript fits a section, write one short line saying so instead of leaving it out.';
		}

		$lines[] = match ($definition['length']) {
			'brief' => 'Keep the summary brief: about 150 words at most, only the essentials.',
			'detailed' => 'Make the summary detailed: cover every topic that was discussed, with the relevant arguments and numbers.',
			default => 'Keep the summary concise: scale its length with the length of the meeting, about 200 to 400 words for a typical meeting.',
		};
		$lines[] = $definition['style'] === 'paragraphs'
			? 'Write short paragraphs instead of bullet points.'
			: 'Use bullet points inside the sections.';
		$lines[] = $definition['language'] === ''
			? 'Write the summary in the main language spoken in the meeting.'
			: 'Write the summary in ' . self::LANGUAGES[$definition['language']] . ', whatever language was spoken in the meeting.';
		if ($definition['actionItemsTable']) {
			$lines[] = 'Present action items as a Markdown table with columns for the task, the owner and the due date. Leave the owner or the due date empty unless the transcript states it. Turn relative dates like "Friday" into dates using the meeting date.';
		}
		if ($definition['transcriptLinks']) {
			$lines[] = 'After each decision, action item and key point, add the timestamp of the transcript block it is based on in square brackets, for example [12:34]. Only use timestamps that occur in the transcript.';
		}
		$lines[] = 'Only name owners or deadlines that are explicitly stated in the transcript. Do not invent facts.';

		if ($definition['extraInstructions'] !== '') {
			$lines[] = '';
			$lines[] = 'Additional instructions of the template author. They take precedence over the instructions above, except that facts must never be invented:';
			$lines[] = $definition['extraInstructions'];
		}

		return implode("\n", $lines);
	}
}
