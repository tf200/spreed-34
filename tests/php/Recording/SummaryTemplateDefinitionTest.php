<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Recording;

use OCA\Talk\Recording\SummaryTemplateDefinition;
use Test\TestCase;

class SummaryTemplateDefinitionTest extends TestCase {
	public function testNormalizeFillsDefaults(): void {
		$this->assertSame([
			'sections' => [['title' => 'Overview', 'description' => '']],
			'length' => 'standard',
			'style' => 'bullets',
			'language' => '',
			'actionItemsTable' => false,
			'transcriptLinks' => false,
			'extraInstructions' => '',
		], SummaryTemplateDefinition::normalize(['sections' => [['title' => 'Overview']]]));
	}

	public static function dataInvalid(): array {
		return [
			'not an object' => ['text', 'definition'],
			'no content' => [['sections' => []], 'sections'],
			'section without title' => [['sections' => [['title' => ' ']]], 'sections'],
			'sections as a map' => [['sections' => ['a' => ['title' => 'A']]], 'sections'],
			'too many sections' => [['sections' => array_fill(0, SummaryTemplateDefinition::MAX_SECTIONS + 1, ['title' => 'A'])], 'sections'],
			'unknown length' => [['sections' => [['title' => 'A']], 'length' => 'huge'], 'length'],
			'unknown style' => [['sections' => [['title' => 'A']], 'style' => 'poem'], 'style'],
			'unknown language' => [['sections' => [['title' => 'A']], 'language' => 'xx'], 'language'],
			'long extra instructions' => [['extraInstructions' => str_repeat('a', SummaryTemplateDefinition::MAX_EXTRA_INSTRUCTIONS_LENGTH + 1)], 'extraInstructions'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('dataInvalid')]
	public function testNormalizeRejectsInvalidDefinition(mixed $definition, string $field): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($field);

		SummaryTemplateDefinition::normalize($definition);
	}

	public function testExtraInstructionsAloneAreEnough(): void {
		$this->assertSame('Focus on budget', SummaryTemplateDefinition::normalize(['extraInstructions' => ' Focus on budget '])['extraInstructions']);
	}

	public function testCompile(): void {
		$instructions = SummaryTemplateDefinition::compile(SummaryTemplateDefinition::normalize([
			'sections' => [['title' => 'Decisions', 'description' => 'Every decision.'], ['title' => 'Risks']],
			'length' => 'detailed',
			'style' => 'paragraphs',
			'language' => 'de',
			'actionItemsTable' => true,
			'transcriptLinks' => true,
			'extraInstructions' => 'Mention the budget.',
		]));

		$this->assertStringContainsString("1. Decisions: Every decision.\n2. Risks\n", $instructions);
		$this->assertStringContainsString('cover every topic', $instructions);
		$this->assertStringContainsString('short paragraphs', $instructions);
		$this->assertStringContainsString('Write the summary in German', $instructions);
		$this->assertStringContainsString('Markdown table', $instructions);
		$this->assertStringContainsString('[12:34]', $instructions);
		$this->assertStringEndsWith("\nMention the budget.", $instructions);
	}

	public function testCompileWithoutOptions(): void {
		$instructions = SummaryTemplateDefinition::compile(SummaryTemplateDefinition::normalize(['sections' => [['title' => 'Overview']]]));

		$this->assertStringContainsString('main language spoken in the meeting', $instructions);
		$this->assertStringContainsString('bullet points', $instructions);
		$this->assertStringNotContainsString('Markdown table', $instructions);
		$this->assertStringNotContainsString('[12:34]', $instructions);
		$this->assertStringNotContainsString('Additional instructions', $instructions);
	}
}
