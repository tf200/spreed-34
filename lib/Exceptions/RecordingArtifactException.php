<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Exceptions;

class RecordingArtifactException extends \RuntimeException {
	public const NOT_FOUND = 'artifact';
	public const STALE_REVISION = 'stale_revision';
	public const EDITING = 'editing';
	public const PUBLISHING = 'publishing';
	public const PUBLISHED = 'published';
	public const CONTENT = 'content';
	public const CONTENT_TOO_LARGE = 'content_too_large';
	public const CONVERSION = 'conversion';
	public const QUOTA = 'quota';
	public const STORAGE = 'storage';
	public const TYPE = 'type';
	public const TRANSCRIPT_UNAVAILABLE = 'transcript';
	public const GENERATION = 'generation';

	public function __construct(
		private readonly string $reason,
	) {
		parent::__construct($reason);
	}

	public function getReason(): string {
		return $this->reason;
	}
}
