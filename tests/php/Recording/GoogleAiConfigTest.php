<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\php\Recording;

use OCA\Talk\Recording\GoogleAiConfig;
use OCP\AppFramework\Services\IAppConfig;
use Test\TestCase;

class GoogleAiConfigTest extends TestCase {
	public function testSummaryModelDefaultsToCurrentFlashLite(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueString')->willReturnArgument(1);

		$this->assertSame('gemini-3.5-flash-lite', (new GoogleAiConfig($appConfig))->getSummaryModel());
	}

	public function testRejectsInvalidSummaryModel(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueString')->with('recording_google_summary_model')->willReturn('models/../x');
		$this->expectException(\InvalidArgumentException::class);

		(new GoogleAiConfig($appConfig))->getSummaryModel();
	}
}
