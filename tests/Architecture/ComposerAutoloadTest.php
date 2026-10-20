<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Architecture\Engine\ComposerAutoload;
use Tests\Architecture\Engine\TempDirectory;

/**
 * M 照合の Engine のうち、composer.json の走査の範囲の読み方(exclude-from-classmap を台帳の書き方にする変換と、psr-4・psr-0 の走査に
 * 掛かるパスパターンの選び方)を確かめる。照合が違反で赤になることは VerifySettingsSelfProofTest が確かめる。
 */
final class ComposerAutoloadTest extends TestCase
{
    private string $workRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workRoot = TempDirectory::create(dirname(__DIR__, 2).'/build/fixtures', 'composer-autoload');
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->workRoot);

        parent::tearDown();
    }

    public function test_classmap_exclusions_cover_directories_and_wildcards_and_keep_other_paths_as_written(): void
    {
        mkdir($this->workRoot.'/lib/Legacy', 0777, true);
        mkdir($this->workRoot.'/tests/fixtures', 0777, true);
        touch($this->workRoot.'/lib/Old.php');

        $this->assertSame(
            ['lib/Legacy/**', 'lib/Old.php', 'tests/fixtures/**', 'tests/*/Generated/**', 'tests/Missing'],
            array_column(ComposerAutoload::classmapExclusions([
                'autoload' => ['exclude-from-classmap' => ['/lib/./Legacy/', 'lib/Old.php']],
                'autoload-dev' => [
                    'exclude-from-classmap' => ['tests/fixtures', 'tests/*/Generated/', 'tests/Missing/'],
                ],
            ], $this->workRoot), 'value'),
        );
    }

    public function test_patterns_overlapping_psr_directories_are_selected_by_their_fixed_prefix(): void
    {
        $manifest = [
            'autoload' => ['psr-4' => ['App\\' => 'app/'], 'psr-0' => ['Legacy_' => ['lib/', 'src/Old']]],
            'autoload-dev' => ['psr-4' => ['Tests\\' => './tests/']],
        ];

        $this->assertSame(
            ['tests/fixtures/**', 'src/Old/A.php', '**/Generated/**', 'src/**'],
            ComposerAutoload::overlappingPsrDirectories(
                [
                    'vendor/**', 'tests/fixtures/**', 'src/Old/A.php', 'application/**',
                    '**/Generated/**', 'src/**', 'storage/**',
                ],
                $manifest,
            ),
        );
        $this->assertSame(
            ['vendor/**'],
            ComposerAutoload::overlappingPsrDirectories(['vendor/**'], ['autoload' => ['psr-4' => ['App\\' => '']]]),
        );
    }
}
