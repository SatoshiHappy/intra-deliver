<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Architecture\Engine\Baseline;
use Tests\Architecture\Engine\ExclusionLedger;
use Tests\Architecture\Engine\Process;
use Tests\Architecture\Engine\TempDirectory;
use Tests\Architecture\Policy\Licenses;
use UnexpectedValueException;

/**
 * ベースラインの部品({@see Baseline}・{@see BaselineTestCase})の自己証明。照合 1(双方向の一致)・照合 2(基準の版から上げていない)・
 * 台帳⑧の baseline の行が無いとき。照合 2 の git は一時ディレクトリに作った小さな木で確かめる(作業木の git に触らない)。
 */
final class BaselineTest extends BaselineTestCase
{
    private const FILE = 'baselines/example.json';

    private ?string $repository = null;

    protected function tearDown(): void
    {
        if ($this->repository !== null) {
            TempDirectory::remove($this->repository);
        }

        parent::tearDown();
    }

    public function test_matching_counts_pass(): void
    {
        $baseline = Baseline::of(['a.php' => 2, 'b.tsx X' => [60, 55]]);

        $this->assertSame([], $baseline->mismatches(Baseline::of(['b.tsx X' => [55, 60], 'a.php' => 2])));
    }

    /**
     * @return array<string, array{array<string, int|list<int>>, string}>
     */
    public static function mismatchingCounts(): array
    {
        return [
            '実際が多い' => [['a.php' => 3, 'b.tsx X' => [60, 55]], 'a.php: ベースライン = 2・実際 = 3 → 違反を直す'],
            'ベースラインに無いキー' => [
                ['a.php' => 2, 'b.tsx X' => [60, 55], 'c.php' => 1],
                'c.php: ベースライン = なし・実際 = 1 → 違反を直す(ベースラインに無いキー)',
            ],
            '並びの要素が増えた' => [
                ['a.php' => 2, 'b.tsx X' => [60, 55, 51]],
                'b.tsx X: ベースライン = [60, 55]・実際 = [60, 55, 51] → 違反を直す',
            ],
            '実際が少ない' => [['a.php' => 1, 'b.tsx X' => [60, 55]], 'a.php: ベースライン = 2・実際 = 1 → ベースラインを実際の値に下げる'],
            'ベースラインのキーに違反が無い' => [['b.tsx X' => [60, 55]], 'a.php: ベースライン = 2・実際 = なし → ベースラインの行を消す'],
        ];
    }

    /**
     * @param  array<string, int|list<int>>  $actual
     */
    #[DataProvider('mismatchingCounts')]
    public function test_reports_the_key_both_values_and_what_to_fix(array $actual, string $expected): void
    {
        $baseline = Baseline::of(['a.php' => 2, 'b.tsx X' => [60, 55]]);

        $this->assertSame([$expected], $baseline->mismatches(Baseline::of($actual)));
    }

    public function test_without_a_ledger_row_the_baseline_is_not_read_and_every_violation_fails(): void
    {
        $this->repository = TempDirectory::create(sys_get_temp_dir(), 'baseline-ledger');
        mkdir($this->repository.'/baselines');
        file_put_contents($this->repository.'/'.self::FILE, '{"a.php": 2}');
        $actual = Baseline::of(['a.php' => 2]);

        $registered = self::exclusions([['baseline' => self::FILE, 'reason' => '例']]);
        $unregistered = self::exclusions([['baseline' => 'baselines/other.json', 'reason' => '例']]);

        $this->assertSame(
            [],
            Baseline::fromLedger($registered, '#3', $this->repository, self::FILE)->mismatches($actual),
        );
        $this->assertSame(
            ['a.php: ベースライン = なし・実際 = 2 → 違反を直す(ベースラインに無いキー)'],
            Baseline::fromLedger($unregistered, '#3', $this->repository, self::FILE)->mismatches($actual),
        );
    }

    public function test_a_baseline_file_with_unsorted_keys_fails(): void
    {
        $actual = Baseline::of(['a.php' => 1, 'b.php' => 2]);

        $this->assertSame([], Baseline::fromJson('{"a.php": 1, "b.php": 2}', 'example')->mismatches($actual));
        $this->assertSame(
            ['(並び): キーがバイトの昇順に並んでいない → キーで並べる'],
            Baseline::fromJson('{"b.php": 2, "a.php": 1}', 'example')->mismatches($actual),
        );
    }

    public function test_the_order_of_the_base_file_is_not_checked(): void
    {
        $this->repositoryOnBranch('{"b.php": 2, "a.php": 1}');
        $this->writeAndCommit('{"a.php": 1, "b.php": 2}', 'sort');

        $this->assertSame([], self::raisedFromBranch((string) $this->repository, self::FILE, 'main'));
    }

    public function test_rejects_a_malformed_baseline(): void
    {
        $this->expectException(UnexpectedValueException::class);

        Baseline::fromJson('{"a.php": 0}', 'example');
    }

    /**
     * @return array<string, array{array<string, int|list<int>>, list<string>}>
     */
    public static function currentBaselines(): array
    {
        return [
            '同じ' => [['a.php' => 2, 'b.php' => 1], []],
            '下げた' => [['a.php' => 1], []],
            '基準にあるキーの値が増えた(合計は同じ)' => [['a.php' => 3], ['a.php: 基準 = 2・今 = 3 → ベースラインを上げない']],
            '合計が増えた(新しいキー)' => [['a.php' => 2, 'b.php' => 1, 'c.php' => 1], [self::totalRaised(3, 4)]],
            'キーの付け替え(合計は同じ)' => [['a.php' => 2, 'renamed.php' => 1], []],
        ];
    }

    /**
     * @param  array<string, int|list<int>>  $current
     * @param  list<string>  $expected
     */
    #[DataProvider('currentBaselines')]
    public function test_reports_a_baseline_raised_from_the_base(array $current, array $expected): void
    {
        $this->assertSame($expected, Baseline::of($current)->raisedFrom(Baseline::of(['a.php' => 2, 'b.php' => 1])));
    }

    public function test_a_baseline_unchanged_from_the_merge_base_passes(): void
    {
        $this->repositoryOnBranch('{"a.php": 2}');

        $this->assertSame([], self::raisedFromBranch((string) $this->repository, self::FILE, 'main'));
    }

    public function test_a_baseline_raised_on_a_branch_fails(): void
    {
        $this->repositoryOnBranch('{"a.php": 2}');
        $this->writeAndCommit('{"a.php": 3}', 'raise');

        $this->assertSame(
            ['a.php: 基準 = 2・今 = 3 → ベースラインを上げない', self::totalRaised(2, 3)],
            self::raisedFromBranch((string) $this->repository, self::FILE, 'main'),
        );
    }

    public function test_a_baseline_raised_in_the_working_tree_on_main_fails(): void
    {
        $this->repositoryOnBranch('{"a.php": 2}');
        $this->git(['checkout', '-q', 'main']);
        file_put_contents($this->repository.'/'.self::FILE, '{"a.php": 3}');

        $this->assertSame(
            ['a.php: 基準 = 2・今 = 3 → ベースラインを上げない', self::totalRaised(2, 3)],
            self::raisedFromBranch((string) $this->repository, self::FILE, 'main'),
        );
    }

    public function test_a_base_without_the_file_is_not_compared(): void
    {
        $this->repositoryOnBranch(null);
        $this->writeAndCommit('{"a.php": 5}', 'first baseline');

        $this->assertSame([], self::raisedFromBranch((string) $this->repository, self::FILE, 'main'));
    }

    private static function totalRaised(int $base, int $current): string
    {
        return "(合計): 基準 = {$base}・今 = {$current} → ベースラインを上げない(足したキーの分だけ、ほかのキーを下げていない)";
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private static function exclusions(array $rows): ExclusionLedger
    {
        return ExclusionLedger::fromArray(['#3' => $rows], Licenses::LEDGER_CHECK);
    }

    /**
     * 一時ディレクトリに git の木を作り、main に $baseline(null ならベースラインのファイル無し)を置いた版を作って、枝 work に移る。
     */
    private function repositoryOnBranch(?string $baseline): void
    {
        $this->repository = TempDirectory::create(sys_get_temp_dir(), 'baseline-git');
        mkdir($this->repository.'/baselines');
        $this->git(['init', '-q', '-b', 'main']);
        file_put_contents($this->repository.'/README', "example\n");

        if ($baseline !== null) {
            file_put_contents($this->repository.'/'.self::FILE, $baseline);
        }

        $this->git(['add', '.']);
        $this->git(['commit', '-q', '-m', 'base']);
        $this->git(['checkout', '-q', '-b', 'work']);
    }

    private function writeAndCommit(string $baseline, string $message): void
    {
        file_put_contents($this->repository.'/'.self::FILE, $baseline);
        $this->git(['add', '.']);
        $this->git(['commit', '-q', '-m', $message]);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(array $arguments): void
    {
        $identity = ['-c', 'user.name=example', '-c', 'user.email=git@example.invalid', '-c', 'commit.gpgsign=false'];
        $result = Process::run(
            ['git', ...$identity, ...$arguments],
            (string) $this->repository,
            ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1'],
        );

        $this->assertSame(0, $result->exitCode, $result->describe());
    }
}
