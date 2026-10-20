<?php

namespace Tests\Architecture;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Architecture\Engine\LicenseAudit;
use Tests\Architecture\Engine\LicenseInventory;
use Tests\Architecture\Engine\SpdxExpression;
use Tests\Architecture\Engine\TempDirectory;
use Tests\Architecture\Policy\Licenses;
use UnexpectedValueException;

/**
 * D 依存のライセンスの検査(scripts/ci/licenses.php)の自己証明。composer の出力・npm ls の出力・node_modules の package.json を
 * fixture で用意し、許可 → 成功 / GPL・不明 → 失敗 / 期限内の除外 → 成功 / 期限切れ → 失敗 / 台帳にあるが実体に無い → 失敗を確かめる。
 * 許可リストは Policy の実物(Licenses::ALLOWED)を使う。
 */
final class LicenseAuditTest extends TestCase
{
    private const TODAY = '2026-10-12';

    private string $modules;

    private string $workRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workRoot = TempDirectory::create(dirname(__DIR__, 2).'/build/fixtures', 'license-audit');
        $this->modules = $this->workRoot.'/node_modules';
        mkdir($this->modules, 0777, true);
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->workRoot);

        parent::tearDown();
    }

    public function test_allowed_licenses_pass_in_both_ecosystems(): void
    {
        $this->installNpm('left-pad', ['license' => 'MIT']);
        $this->installNpm('@scope/dual', ['license' => '(MIT OR GPL-3.0-only)']);

        $records = [
            ...LicenseInventory::composer($this->composerOutput(['a/mit' => ['MIT'], 'b/apache' => ['Apache-2.0']])),
            ...LicenseInventory::npm($this->npmTree(['left-pad' => '1.0.0', '@scope/dual' => '2.0.0']), []),
        ];

        $this->assertCount(4, $records);
        $this->assertSame([], $this->audit()->failures($records));
    }

    public function test_a_gpl_package_fails_with_name_version_license_and_reason(): void
    {
        $this->installNpm('copyleft', ['license' => 'AGPL-3.0-only']);

        $records = [
            ...LicenseInventory::composer($this->composerOutput(['x/gpl' => ['GPL-3.0-only']], '1.2.3')),
            ...LicenseInventory::npm($this->npmTree(['copyleft' => '4.5.6']), []),
        ];

        $this->assertSame([
            'composer x/gpl 1.2.3 [GPL-3.0-only]: 許可リストに無い',
            'npm copyleft 4.5.6 [AGPL-3.0-only]: 許可リストに無い',
        ], $this->audit()->failures($records));
    }

    public function test_a_composer_license_list_is_a_choice_so_one_allowed_entry_is_enough(): void
    {
        $records = LicenseInventory::composer($this->composerOutput(['nette/like' => ['BSD-3-Clause', 'GPL-2.0-only', 'GPL-3.0-only']]));

        $this->assertSame([], $this->audit()->failures($records));
    }

    public function test_unknown_licenses_fail(): void
    {
        $this->installNpm('no-field', ['name' => 'no-field']);
        $this->installNpm('unlicensed', ['license' => 'UNLICENSED']);
        $this->installNpm('see-file', ['license' => 'SEE LICENSE IN LICENSE.md']);
        $this->installNpm('broken-json', null);
        $records = [
            ...LicenseInventory::composer($this->composerOutput(['x/none' => []])),
            ...LicenseInventory::npm($this->npmTree(['no-field' => '1.0.0', 'unlicensed' => '1.0.0', 'see-file' => '1.0.0', 'broken-json' => '1.0.0', 'not-installed-dir' => '1.0.0']), []),
        ];

        $this->assertSame([
            'composer x/none 1.0.0 [license 欄なし]: ライセンスが不明(license 欄なし)',
            'npm no-field 1.0.0 [license 欄なし]: ライセンスが不明(license 欄なし)',
            'npm unlicensed 1.0.0 [UNLICENSED]: 許可リストに無い',
            'npm see-file 1.0.0 [SEE LICENSE IN LICENSE.md]: 許可リストに無い',
            'npm broken-json 1.0.0 [license 欄なし]: package.json を読めない',
            'npm not-installed-dir 1.0.0 [license 欄なし]: package.json が無い',
        ], $this->audit()->failures($records));
    }

    public function test_an_exception_within_its_period_passes_and_an_expired_one_fails(): void
    {
        $records = LicenseInventory::composer($this->composerOutput(['x/gpl' => ['GPL-3.0-only'], 'y/gpl' => ['GPL-3.0-only'], 'z/gpl' => ['GPL-3.0-only']]));
        $audit = $this->audit(['composer:x/gpl' => '2026-10-13', 'composer:y/gpl' => self::TODAY, 'composer:z/gpl' => '2026-10-11']);

        $this->assertSame(['composer z/gpl 1.0.0 [GPL-3.0-only]: 除外の期限切れ(期限 2026-10-11)'], $audit->failures($records));
    }

    public function test_an_exception_for_a_package_that_is_not_installed_fails(): void
    {
        $records = LicenseInventory::composer($this->composerOutput(['a/mit' => ['MIT']]));
        $audit = $this->audit(['composer:gone/package' => '2099-01-01', 'npm:a/mit' => '2099-01-01']);

        $this->assertSame([
            '台帳⑧ composer:gone/package: 台帳にあるが依存の実体に無い(除外を外す)',
            '台帳⑧ npm:a/mit: 台帳にあるが依存の実体に無い(除外を外す)',
        ], $audit->failures($records));
    }

    public function test_npm_reads_each_node_at_its_own_path_so_a_nested_child_with_another_version_is_its_own_record(): void
    {
        $this->installNpm('shared', ['license' => 'ISC']);
        $this->installNpm('host', ['license' => 'MIT']);
        $this->installNpm('host/node_modules/shared', ['license' => 'GPL-3.0-only']);
        $this->installNpm('old-object', ['license' => ['type' => 'ISC']]);
        $this->installNpm('old-array', ['licenses' => [['type' => 'GPL-2.0-only'], ['type' => 'MIT']]]);

        $tree = ['path' => $this->workRoot, 'dependencies' => [
            'host' => $this->node('host', '1.0.0', [
                'shared' => $this->node('host/node_modules/shared', '2.0.0'),
                'old-object' => $this->node('old-object', '1.0.0'),
            ]),
            'shared' => $this->node('shared', '1.0.0'),
            'old-array' => $this->node('old-array', '1.0.0'),
            'peer-missing' => [],
        ]];
        $records = LicenseInventory::npm($tree, []);

        $this->assertSame(
            ['host@1.0.0', 'shared@2.0.0', 'old-object@1.0.0', 'shared@1.0.0', 'old-array@1.0.0'],
            array_map(fn ($record): string => $record->name.'@'.$record->version, $records),
        );
        $this->assertSame(['npm shared 2.0.0 [GPL-3.0-only]: 許可リストに無い'], $this->audit()->failures($records));
    }

    public function test_a_missing_package_json_is_reported_and_its_children_are_still_audited(): void
    {
        $this->installNpm('copyleft', ['license' => 'AGPL-3.0-only']);
        $tree = ['path' => $this->workRoot, 'dependencies' => [
            'host' => $this->node('host', '1.0.0', ['copyleft' => $this->node('copyleft', '2.0.0')]),
            'no-path' => ['version' => '3.0.0', 'dependencies' => ['copyleft' => $this->node('copyleft', '2.0.0')]],
        ]];

        $this->assertSame([
            'npm host 1.0.0 [license 欄なし]: package.json が無い',
            'npm copyleft 2.0.0 [AGPL-3.0-only]: 許可リストに無い',
            'npm no-path 3.0.0 [license 欄なし]: npm ls に path が無い',
        ], $this->audit()->failures(LicenseInventory::npm($tree, [])));
    }

    public function test_the_old_licenses_field_is_read_only_when_license_is_empty(): void
    {
        $this->installNpm('gpl-then-mit', ['license' => 'GPL-3.0-only', 'licenses' => [['type' => 'MIT']]]);
        $this->installNpm('empty-then-mit', ['license' => '', 'licenses' => [['type' => 'MIT']]]);

        $records = LicenseInventory::npm($this->npmTree(['gpl-then-mit' => '1.0.0', 'empty-then-mit' => '1.0.0']), []);

        $this->assertSame(['npm gpl-then-mit 1.0.0 [GPL-3.0-only]: 許可リストに無い'], $this->audit()->failures($records));
    }

    public function test_a_missing_dependency_is_a_failure_and_an_empty_optional_entry_is_not_counted(): void
    {
        $tree = ['path' => $this->workRoot, 'dependencies' => [
            'left-pad' => ['required' => '1.0.0', 'missing' => true, 'problems' => ['missing: left-pad@1.0.0']],
            'unknown-version' => ['missing' => true],
            'optional-absent' => [],
        ]];

        $this->assertSame([
            'npm left-pad 1.0.0 [license 欄なし]: 入っていない(npm ls が missing)',
            'npm unknown-version (未導入) [license 欄なし]: 入っていない(npm ls が missing)',
        ], $this->audit()->failures(LicenseInventory::npm($tree, [])));
    }

    public function test_the_same_name_and_version_under_two_parents_is_one_record(): void
    {
        $this->installNpm('host-a', ['license' => 'MIT']);
        $this->installNpm('host-b', ['license' => 'MIT']);
        $this->installNpm('shared', ['license' => 'ISC']);

        $tree = ['path' => $this->workRoot, 'dependencies' => [
            'host-a' => $this->node('host-a', '1.0.0', ['shared' => $this->node('shared', '1.0.0')]),
            'host-b' => $this->node('host-b', '1.0.0', ['shared' => $this->node('shared', '1.0.0')]),
        ]];

        $this->assertCount(3, LicenseInventory::npm($tree, []));
    }

    public function test_an_npm_tree_without_dependencies_is_an_error_and_an_empty_one_is_zero_records(): void
    {
        $this->assertSame([], LicenseInventory::npm(['path' => $this->workRoot, 'dependencies' => []], []));

        $this->expectException(UnexpectedValueException::class);

        LicenseInventory::npm(['name' => 'fixture', 'path' => $this->workRoot], []);
    }

    public function test_an_exception_does_not_cover_a_package_whose_license_could_not_be_read(): void
    {
        $records = LicenseInventory::npm($this->npmTree(['no-package-json' => '1.0.0']), []);

        $this->assertSame(
            ['npm no-package-json 1.0.0 [license 欄なし]: package.json が無い'],
            $this->audit(['npm:no-package-json' => '2099-01-01'])->failures($records),
        );
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function expressions(): array
    {
        return [
            'single allowed' => ['MIT', true],
            'case-insensitive' => ['apache-2.0', true],
            'or: one allowed' => ['(MIT OR GPL-3.0-only)', true],
            'or: none allowed' => ['GPL-2.0-only OR AGPL-3.0-only', false],
            'and: all allowed' => ['MIT AND ISC', true],
            'and: one not allowed' => ['MIT AND GPL-3.0-only', false],
            'nested' => ['(MIT AND (ISC OR GPL-3.0-only)) OR LGPL-3.0-only', true],
            'with exception' => ['Apache-2.0 WITH LLVM-exception', true],
            'gpl with exception' => ['GPL-2.0-only WITH Classpath-exception-2.0', false],
            'lgpl' => ['LGPL-3.0-or-later', false],
            'sspl' => ['SSPL-1.0', false],
            'plus is another id' => ['GPL-2.0+', false],
            'unknown' => ['UNKNOWN', false],
            'unlicensed' => ['UNLICENSED', false],
            'empty' => ['', false],
            'dangling or' => ['MIT OR', false],
            'unclosed paren' => ['(MIT', false],
            'extra close paren' => ['MIT)', false],
            'free text' => ['SEE LICENSE IN LICENSE', false],
            'with and no exception id' => ['MIT WITH', false],
            'with an operator' => ['MIT WITH OR', false],
            'with a close paren' => ['MIT WITH )', false],
            'with an open paren' => ['MIT WITH (', false],
        ];
    }

    #[DataProvider('expressions')]
    public function test_spdx_expression_is_judged_against_the_policy_allow_list(string $expression, bool $allowed): void
    {
        $this->assertSame($allowed, SpdxExpression::isAllowed($expression, Licenses::ALLOWED));
    }

    /**
     * @param  array<string, string>  $exceptions
     */
    private function audit(array $exceptions = []): LicenseAudit
    {
        return new LicenseAudit(Licenses::ALLOWED, $exceptions, new DateTimeImmutable(self::TODAY));
    }

    /**
     * @param  array<string, list<string>>  $licenses  パッケージ名 → license 配列
     * @return array<mixed>
     */
    private function composerOutput(array $licenses, string $version = '1.0.0'): array
    {
        $dependencies = [];

        foreach ($licenses as $name => $license) {
            $dependencies[$name] = ['version' => $version, 'license' => $license];
        }

        return ['name' => 'fixture/app', 'dependencies' => $dependencies];
    }

    /**
     * @param  array<string, string>  $versions  パッケージ名 → 版(最上位の依存)
     * @return array<mixed>
     */
    private function npmTree(array $versions): array
    {
        $dependencies = [];

        foreach ($versions as $name => $version) {
            $dependencies[$name] = $this->node($name, $version);
        }

        return ['name' => 'fixture', 'path' => $this->workRoot, 'dependencies' => $dependencies];
    }

    /**
     * npm ls --long の 1 項目(入っているディレクトリを path に持つ)。$directory は node_modules からの相対。
     *
     * @param  array<string, array<mixed>>  $children
     * @return array<string, mixed>
     */
    private function node(string $directory, string $version, array $children = []): array
    {
        $node = ['version' => $version, 'path' => $this->modules.'/'.$directory];

        return $children === [] ? $node : [...$node, 'dependencies' => $children];
    }

    /**
     * node_modules の下にパッケージの package.json を作る。null は壊れた JSON。
     *
     * @param  array<string, mixed>|null  $manifest
     */
    private function installNpm(string $name, ?array $manifest): void
    {
        $directory = $this->modules.'/'.$name;
        mkdir($directory, 0777, true);
        file_put_contents($directory.'/package.json', $manifest === null ? '{broken' : json_encode($manifest, JSON_THROW_ON_ERROR));
    }
}
