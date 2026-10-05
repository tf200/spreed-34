<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Recording;

use OCP\IL10N;

/**
 * Summary templates shipped with Talk.
 *
 * @psalm-import-type SummaryTemplateDefinitionArray from SummaryTemplateDefinition
 */
class BuiltInSummaryTemplates {
	public const PREFIX = 'builtin-';
	public const DEFAULT_ID = self::PREFIX . 'general';

	public function __construct(
		private readonly IL10N $l,
	) {
	}

	/**
	 * @return array<string, array{name: string, description: string, definition: SummaryTemplateDefinitionArray}>
	 */
	public function getAll(): array {
		return [
			self::PREFIX . 'general' => $this->template(
				$this->l->t('General meeting'),
				$this->l->t('Overview, decisions, action items and open questions'),
				[
					[$this->l->t('Overview'), $this->l->t('The purpose of the meeting and the main topics, in two or three sentences.')],
					[$this->l->t('Decisions'), $this->l->t('Every decision that was made.')],
					[$this->l->t('Action items'), $this->l->t('Every task someone agreed to do, with the owner and the deadline when stated.')],
					[$this->l->t('Open questions'), $this->l->t('Questions and issues that remain unresolved.')],
				],
				actionItemsTable: false,
			),
			self::PREFIX . 'standup' => $this->template(
				$this->l->t('Stand-up'),
				$this->l->t('What each person did, plans next and is blocked by'),
				[
					[$this->l->t('Updates per person'), $this->l->t('For each participant who spoke: what they finished, what they work on next, and their blockers. Use the participant name as a bold label.')],
					[$this->l->t('Blockers'), $this->l->t('All blockers, and who offered to help with them.')],
				],
				length: 'brief',
			),
			self::PREFIX . 'one-on-one' => $this->template(
				$this->l->t('1:1'),
				$this->l->t('Topics, feedback, agreements and follow-ups'),
				[
					[$this->l->t('Topics'), $this->l->t('The topics that were discussed.')],
					[$this->l->t('Feedback'), $this->l->t('Feedback that was given, in both directions.')],
					[$this->l->t('Agreements'), $this->l->t('What was agreed.')],
					[$this->l->t('Follow-ups'), $this->l->t('Tasks with the owner and the deadline when stated.')],
				],
			),
			self::PREFIX . 'client-call' => $this->template(
				$this->l->t('Client call'),
				$this->l->t('Client needs, commitments made and next steps'),
				[
					[$this->l->t('Client needs'), $this->l->t('The needs, goals and concerns the client expressed, in their words where possible.')],
					[$this->l->t('Commitments'), $this->l->t('Everything that was promised to the client, and by whom.')],
					[$this->l->t('Next steps'), $this->l->t('The agreed next steps with owners and dates.')],
				],
				actionItemsTable: true,
			),
			self::PREFIX . 'kickoff' => $this->template(
				$this->l->t('Project kick-off'),
				$this->l->t('Goals, scope, roles, timeline and risks'),
				[
					[$this->l->t('Goals'), $this->l->t('The goals of the project and how success is measured.')],
					[$this->l->t('Scope'), $this->l->t('What is in and out of scope.')],
					[$this->l->t('Roles'), $this->l->t('Who is responsible for what.')],
					[$this->l->t('Timeline'), $this->l->t('Milestones and dates that were mentioned.')],
					[$this->l->t('Risks'), $this->l->t('Risks and dependencies that were raised.')],
				],
				length: 'detailed',
				actionItemsTable: true,
			),
			self::PREFIX . 'retrospective' => $this->template(
				$this->l->t('Retrospective'),
				$this->l->t('What went well, what to improve and the actions'),
				[
					[$this->l->t('Went well'), $this->l->t('What the team was happy with.')],
					[$this->l->t('To improve'), $this->l->t('What did not go well and why.')],
					[$this->l->t('Actions'), $this->l->t('The improvements the team agreed on, with owners.')],
				],
			),
			self::PREFIX . 'interview' => $this->template(
				$this->l->t('Interview'),
				$this->l->t('Candidate, strengths, concerns and recommendation'),
				[
					[$this->l->t('Candidate'), $this->l->t('Background and experience the candidate described.')],
					[$this->l->t('Strengths'), $this->l->t('Strengths shown in the interview, with examples.')],
					[$this->l->t('Concerns'), $this->l->t('Concerns and gaps, with examples.')],
					[$this->l->t('Recommendation'), $this->l->t('Only a recommendation the interviewers stated themselves; otherwise say that none was given.')],
				],
			),
			self::PREFIX . 'brainstorm' => $this->template(
				$this->l->t('Brainstorm'),
				$this->l->t('Ideas grouped by theme and the shortlist'),
				[
					[$this->l->t('Ideas'), $this->l->t('All ideas, grouped by theme, with who proposed them.')],
					[$this->l->t('Shortlist'), $this->l->t('The ideas the group preferred, and why.')],
					[$this->l->t('Next steps'), $this->l->t('How the ideas will be followed up.')],
				],
				length: 'detailed',
			),
		];
	}

	public static function isBuiltIn(string $id): bool {
		return str_starts_with($id, self::PREFIX);
	}

	/**
	 * @param list<array{string, string}> $sections
	 * @param 'brief'|'standard'|'detailed' $length
	 * @return array{name: string, description: string, definition: SummaryTemplateDefinitionArray}
	 */
	private function template(string $name, string $description, array $sections, string $length = 'standard', bool $actionItemsTable = false): array {
		return [
			'name' => $name,
			'description' => $description,
			'definition' => [
				'sections' => array_map(static fn (array $section): array => ['title' => $section[0], 'description' => $section[1]], $sections),
				'length' => $length,
				'style' => 'bullets',
				'language' => '',
				'actionItemsTable' => $actionItemsTable,
				'transcriptLinks' => false,
				'extraInstructions' => '',
			],
		];
	}
}
