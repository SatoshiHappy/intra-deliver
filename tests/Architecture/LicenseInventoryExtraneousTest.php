<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Architecture\Engine\LicenseInventory;
use Tests\Architecture\Engine\LicenseRecord;
use Tests\Architecture\Engine\TempDirectory;
use UnexpectedValueException;

/**
 * npm ls が依存の辺を持たない項目(extraneous)とその子孫を、lock の分類で本番に数えるかを決めることの確認(LicenseInventory::npm)。
 * lock が同じパス・同じ版で dev: true とする項目だけ数えない(子は 1 件ずつ確かめる)/ 確かめられないものは数える / ルートの無い木は例外。
 */
final class LicenseInventoryExtraneousTest extends TestCase
{
    private string $workRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workRoot = TempDirectory::create(dirname(__DIR__, 2).'/build/fixtures', 'license-extraneous');
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->workRoot);

        parent::tearDown();
    }

    public function test_extraneous_packages_that_the_lock_marks_dev_at_the_same_version_are_not_counted(): void
    {
        $tree = ['path' => $this->workRoot, 'dependencies' => [
            'left-pad' => $this->installed('left-pad', '1.0.0', 'MIT'),
            'wasm-lgpl' => [
                ...$this->installed('wasm-lgpl', '0.35.5', 'LGPL-3.0-or-later'),
                'extraneous' => true,
                'dependencies' => ['runtime' => $this->installed('wasm-lgpl/node_modules/runtime', '1.0.0', 'GPL-3.0-only')],
            ],
            'host' => [
                ...$this->installed('host', '1.0.0', 'MIT'),
                'dependencies' => ['nested-dev' => [...$this->installed('host/node_modules/nested-dev', '2.0.0', 'GPL-3.0-only'), 'extraneous' => true]],
            ],
        ]];
        $lockPackages = [
            'node_modules/wasm-lgpl' => ['version' => '0.35.5', 'dev' => true, 'optional' => true],
            'node_modules/wasm-lgpl/node_modules/runtime' => ['version' => '1.0.0', 'dev' => true, 'optional' => true],
            'node_modules/host/node_modules/nested-dev' => ['version' => '2.0.0', 'dev' => true],
        ];

        $this->assertSame(['left-pad@1.0.0', 'host@1.0.0'], $this->names(LicenseInventory::npm($tree, $lockPackages)));
    }

    public function test_a_production_package_first_met_under_a_dev_extraneous_package_is_counted_with_its_children(): void
    {
        $tree = ['path' => $this->workRoot, 'dependencies' => [
            'wasm-lgpl' => [
                ...$this->installed('wasm-lgpl', '0.35.5', 'LGPL-3.0-or-later'),
                'extraneous' => true,
                'dependencies' => ['tslib' => [
                    ...$this->installed('tslib', '2.8.1', '0BSD'),
                    'dependencies' => ['helper' => $this->installed('helper', '1.0.0', 'GPL-3.0-only')],
                ]],
            ],
        ]];
        $lockPackages = [
            'node_modules/wasm-lgpl' => ['version' => '0.35.5', 'dev' => true, 'optional' => true],
            'node_modules/tslib' => ['version' => '2.8.1'],
            'node_modules/helper' => ['version' => '1.0.0'],
        ];

        $this->assertSame(['tslib@2.8.1', 'helper@1.0.0'], $this->names(LicenseInventory::npm($tree, $lockPackages)));
    }

    public function test_an_extraneous_package_that_the_lock_does_not_confirm_as_dev_is_counted(): void
    {
        $outside = TempDirectory::create(dirname(__DIR__, 2).'/build/fixtures', 'license-extraneous-outside');
        mkdir($outside.'/linked');
        file_put_contents($outside.'/linked/package.json', json_encode(['license' => 'GPL-3.0-only'], JSON_THROW_ON_ERROR));

        try {
            $tree = ['path' => $this->workRoot, 'dependencies' => [
                'dev-optional' => [...$this->installed('dev-optional', '1.0.0', 'GPL-3.0-only'), 'extraneous' => true],
                'not-in-lock' => [...$this->installed('not-in-lock', '2.0.0', 'GPL-3.0-only'), 'extraneous' => true],
                'other-version' => [...$this->installed('other-version', '3.0.0', 'GPL-3.0-only'), 'extraneous' => true],
                'linked' => ['version' => '4.0.0', 'path' => $outside.'/linked', 'extraneous' => true],
            ]];
            $lockPackages = [
                'node_modules/dev-optional' => ['version' => '1.0.0', 'optional' => true, 'devOptional' => true],
                'node_modules/other-version' => ['version' => '2.9.0', 'dev' => true],
                'node_modules/linked' => ['version' => '4.0.0', 'dev' => true],
            ];

            $this->assertSame(
                ['dev-optional@1.0.0', 'not-in-lock@2.0.0', 'other-version@3.0.0', 'linked@4.0.0'],
                $this->names(LicenseInventory::npm($tree, $lockPackages)),
            );
        } finally {
            TempDirectory::remove($outside);
        }
    }

    public function test_an_npm_tree_without_a_root_path_is_an_error(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('npm ls の path(ルート)が無い');

        LicenseInventory::npm(['dependencies' => ['left-pad' => $this->installed('left-pad', '1.0.0', 'MIT')]], []);
    }

    /**
     * node_modules の下に package.json を作り、npm ls --long の 1 項目(入っているディレクトリを path に持つ)を返す。
     *
     * @return array<string, mixed>
     */
    private function installed(string $directory, string $version, string $license): array
    {
        $path = $this->workRoot.'/node_modules/'.$directory;
        mkdir($path, 0777, true);
        file_put_contents($path.'/package.json', json_encode(['license' => $license], JSON_THROW_ON_ERROR));

        return ['version' => $version, 'path' => $path];
    }

    /**
     * @param  list<LicenseRecord>  $records
     * @return list<string>
     */
    private function names(array $records): array
    {
        return array_map(static fn (LicenseRecord $record): string => $record->name.'@'.$record->version, $records);
    }
}
