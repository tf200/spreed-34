<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Tests\Recording;

use OCA\Talk\Recording\ParticipantTracksStore;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class ParticipantTracksStoreTest extends TestCase {
	private IAppData&MockObject $appData;
	private ParticipantTracksStore $store;
	/** @var list<string> */
	private array $archives = [];

	protected function setUp(): void {
		parent::setUp();
		$this->appData = $this->createMock(IAppData::class);
		$this->store = new ParticipantTracksStore($this->appData);
	}

	protected function tearDown(): void {
		foreach ($this->archives as $archive) {
			@unlink($archive);
		}
		parent::tearDown();
	}

	private static function manifest(): array {
		return [
			'version' => 1,
			'startPageTime' => 1234.5,
			'segments' => [[
				'id' => 1,
				'peerId' => 'peer1',
				'actorType' => 'users',
				'actorId' => 'alice',
				'displayName' => 'Alice',
				'recordingOffset' => 7.0,
				'alignment' => 'xcorr',
				'anchors' => [['segmentTime' => 0, 'recordingTime' => 7.0, 'score' => 0.8]],
			]],
			'chunks' => [[
				'id' => '1-0',
				'segmentId' => 1,
				'file' => 'chunk-1-0.ogg',
				'mimeType' => 'audio/ogg',
				'duration' => 12.5,
				'cutMap' => [
					['chunkStart' => 0, 'trackStart' => 0.6, 'recordingStart' => 7.6, 'duration' => 5.4],
					['chunkStart' => 6.4, 'trackStart' => 14.6, 'recordingStart' => 21.6, 'duration' => 6.1],
				],
			]],
		];
	}

	/** @param array<string, string> $entries */
	private function archive(array $entries): string {
		$path = tempnam(sys_get_temp_dir(), 'tracks');
		$this->archives[] = $path;
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::OVERWRITE);
		foreach ($entries as $name => $content) {
			$zip->addFromString($name, $content);
		}
		$zip->close();
		return $path;
	}

	public function testStoreArchiveExtractsChunksAndManifest(): void {
		$root = $this->createMock(ISimpleFolder::class);
		$recording = $this->createMock(ISimpleFolder::class);
		$this->appData->method('getFolder')->with('recording-tracks')->willReturn($root);
		$root->method('getFolder')->willThrowException(new NotFoundException());
		$root->expects($this->once())->method('newFolder')->with('42')->willReturn($recording);

		$written = [];
		$recording->method('newFile')->willReturnCallback(function (string $name, $content) use (&$written) {
			$written[$name] = $content;
			return $this->createMock(\OCP\Files\SimpleFS\ISimpleFile::class);
		});

		$this->store->storeArchive(42, $this->archive([
			'manifest.json' => json_encode(self::manifest()),
			'chunk-1-0.ogg' => 'OggS-audio',
		]));

		$this->assertSame(['chunk-1-0.ogg', 'manifest.json'], array_keys($written));
		$this->assertSame('OggS-audio', $written['chunk-1-0.ogg']);
		$manifest = json_decode($written['manifest.json'], true);
		$this->assertSame('1', $manifest['segments'][0]['id']);
		$this->assertSame('Alice', $manifest['segments'][0]['displayName']);
		$this->assertEquals(7.0, $manifest['segments'][0]['recordingOffset']);
		$this->assertArrayNotHasKey('anchors', $manifest['segments'][0]);
		$this->assertSame(21.6, $manifest['chunks'][0]['cutMap'][1]['recordingStart']);
	}

	public static function dataInvalidArchive(): array {
		return [
			'unexpected entry' => [['manifest.json' => '{}', '../evil.php' => 'x'], 'participant_tracks_invalid_archive'],
			'missing manifest' => [['chunk-1-0.ogg' => 'x'], 'participant_tracks_invalid_manifest'],
			'invalid json' => [['manifest.json' => '{', 'chunk-1-0.ogg' => 'x'], 'participant_tracks_invalid_manifest'],
			'chunk missing in archive' => [['manifest.json' => json_encode(self::manifest())], 'participant_tracks_invalid_manifest'],
		];
	}

	#[DataProvider('dataInvalidArchive')]
	public function testStoreArchiveRejectsInvalidArchives(array $entries, string $error): void {
		$this->appData->expects($this->never())->method('newFolder');
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($error);
		$this->store->storeArchive(42, $this->archive($entries));
	}

	public static function dataInvalidManifest(): array {
		$cases = [];
		$manifest = self::manifest();
		$manifest['version'] = 2;
		$cases['version'] = [$manifest];
		$manifest = self::manifest();
		$manifest['chunks'][0]['segmentId'] = 9;
		$cases['unknown segment'] = [$manifest];
		$manifest = self::manifest();
		$manifest['chunks'][0]['file'] = 'chunk-1-1.ogg';
		$cases['file does not match id'] = [$manifest];
		$manifest = self::manifest();
		$manifest['chunks'][0]['duration'] = 901;
		$cases['too long'] = [$manifest];
		$manifest = self::manifest();
		$manifest['chunks'][0]['cutMap'][1]['chunkStart'] = 0;
		$cases['unordered cut map'] = [$manifest];
		$manifest = self::manifest();
		$manifest['chunks'][0]['cutMap'][0]['recordingStart'] = -1;
		$cases['negative time'] = [$manifest];
		$manifest = self::manifest();
		$manifest['segments'][0]['displayName'] = ['Alice'];
		$cases['invalid name'] = [$manifest];
		$manifest = self::manifest();
		$manifest['segments'][0]['id'] = '../1';
		$cases['invalid segment id'] = [$manifest];
		return $cases;
	}

	#[DataProvider('dataInvalidManifest')]
	public function testValidateManifestRejectsInvalidContent(array $manifest): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->store->validateManifest(json_encode($manifest), ['chunk-1-0.ogg' => true, 'chunk-1-1.ogg' => true]);
	}

	public function testSetDisplayNames(): void {
		$manifest = $this->store->validateManifest(json_encode(self::manifest()), ['chunk-1-0.ogg' => true]);
		$file = $this->createMock(\OCP\Files\SimpleFS\ISimpleFile::class);
		$file->method('getContent')->willReturn(json_encode($manifest));
		$recording = $this->createMock(ISimpleFolder::class);
		$recording->method('getFile')->with('manifest.json')->willReturn($file);
		$root = $this->createMock(ISimpleFolder::class);
		$root->method('getFolder')->with('42')->willReturn($recording);
		$this->appData->method('getFolder')->with('recording-tracks')->willReturn($root);

		$file->expects($this->once())->method('putContent')->with($this->callback(function (string $content) use ($manifest): bool {
			$expected = $manifest;
			$expected['segments'][0]['displayName'] = 'Bob (guest)';
			$this->assertEquals($expected, json_decode($content, true));
			return true;
		}));

		$this->store->setDisplayNames(42, ['1' => 'Bob (guest)', '99' => 'Unknown']);
	}

	public function testGetChunkContentRejectsOtherFiles(): void {
		$this->appData->expects($this->never())->method('getFolder');
		$this->expectException(NotFoundException::class);
		$this->store->getChunkContent(42, 'manifest.json');
	}
}
