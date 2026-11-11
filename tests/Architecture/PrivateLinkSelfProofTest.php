<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Architecture\Engine\Finding;
use Tests\Architecture\Engine\LinkedTree;
use Tests\Architecture\Engine\ProcessResult;
use Tests\Architecture\Policy\SourceDirectories;

/**
 * 手元だけのリポジトリへのリンク(アプリのルートの {@see SourceDirectories::PRIVATE_ROOT})の先のファイルに、phpstan の規則が
 * 木の中のファイルと同じに当たることの自己証明(docs の ADR 0039 の決定 8、設計/コード構成/検査/README.md 6.2)。
 * phpstan はリンクをたどってディレクトリを歩き、ファイルのパスをリンクの側のまま報告するので、パスで決まるコード構成の規則
 * (クラスを置くディレクトリ・配線のファイル・照合しないディレクトリ・1 クラスの規則の組)がリンクの先にも当たる。
 *
 * 一時の木 = tests/Architecture/fixtures/PrivateLink/tree の写しと、木の外の同 linked を指すリンク(build/fixtures の下にテストのたびに作る。
 * リンクを commit しない)。設定 = 同 phpstan.neon(アプリのルートを一時の木に差し替える)。
 */
final class PrivateLinkSelfProofTest extends PhpStanSelfProofTestCase
{
    private const LINKED = __DIR__.'/fixtures/PrivateLink/linked';

    private static ?string $tree = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$tree = LinkedTree::create(
            dirname(__DIR__, 2).'/build/fixtures',
            'private-link',
            __DIR__.'/fixtures/PrivateLink/tree',
            SourceDirectories::PRIVATE_ROOT,
            self::LINKED,
        );
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$tree !== null) {
            LinkedTree::remove(self::$tree, [SourceDirectories::PRIVATE_ROOT]);
            self::$tree = null;
        }

        parent::tearDownAfterClass();
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function violations(): array
    {
        return [
            '副作用の規則(読み取りからモデルの保存)' => ['sideEffect.dbWrite', 'ai-private/app/Queries/Alpha/WritesFromQuery.php', 14],
            '(d) 自分の表の規則(同じモジュールの業務処理の外の書き込み)' => [
                'moduleTableWrite', 'ai-private/app/Queries/Alpha/WritesFromQuery.php', 14,
            ],
            '1 クラスの規則(2 つ目の組 ai-private/app)' => ['appFile.classCount', 'ai-private/app/Support/TwoClasses.php', 1],
            '層の規則(読み取り → 業務処理)' => ['phpat.queriesDependencies', 'ai-private/app/Queries/Alpha/ReachesActions.php', 12],
            '配線のファイルの規則(クラスを置くディレクトリの外 = リンクの根の providers.php)' => ['wiring.dependency', 'ai-private/providers.php', 5],
        ];
    }

    #[DataProvider('violations')]
    public function test_rules_apply_to_files_behind_the_link(string $ruleId, string $file, int $line): void
    {
        $this->assertDetects($ruleId, $file, $line, self::linkedResult());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function cleanFiles(): array
    {
        return [
            'クラスを置くディレクトリ(配線のファイルの規則が当たらない)' => ['ai-private/app/Actions/Alpha/UsesModel.php'],
            '照合しないディレクトリ(副作用・自分の表の規則が当たらない)' => ['ai-private/tests/Feature/WritesInTestDirectory.php'],
        ];
    }

    #[DataProvider('cleanFiles')]
    public function test_class_directories_and_exempt_directories_behind_the_link_keep_their_role(string $file): void
    {
        $found = array_filter(
            $this->findings(self::linkedResult()),
            fn (Finding $finding): bool => $finding->matches($finding->ruleId, $file, null),
        );

        $this->assertSame([], array_map(fn (Finding $finding): string => $finding->describe(), array_values($found)));
    }

    public function test_reports_files_by_the_path_on_the_link_side(): void
    {
        $findings = $this->findings(self::linkedResult());
        $files = array_map(fn (Finding $finding): string => str_replace('\\', '/', $finding->file), $findings);
        $under = fn (string $directory): array => array_values(
            array_filter($files, fn (string $file): bool => str_contains($file, $directory)),
        );

        $this->assertSame([], $under('/PrivateLink/linked/'));
        $this->assertNotSame([], $under('/'.SourceDirectories::PRIVATE_ROOT.'/'));
    }

    private static function linkedResult(): ProcessResult
    {
        self::assertNotNull(self::$tree);

        return self::analysePath(self::$tree, 'PrivateLink/phpstan.neon', ['PHPSTAN_PRIVATE_LINK_ROOT' => self::$tree]);
    }
}
