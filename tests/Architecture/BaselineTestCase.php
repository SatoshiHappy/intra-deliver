<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Architecture\Engine\Baseline;
use Tests\Architecture\Engine\ExclusionLedger;
use Tests\Architecture\Engine\ExclusionLedgerFile;
use Tests\Architecture\Engine\GitRevision;
use Tests\Architecture\Policy\Baselines;
use Tests\Architecture\Policy\Licenses;

/**
 * ベースラインを持つ検査の照合 1・2 の基底(docs の 設計/コード構成/検査.md 6 節)。
 */
abstract class BaselineTestCase extends TestCase
{
    /**
     * 照合 1。ベースライン = 根 $root の台帳⑧の検査 $check に baseline の行 $file があればそのファイル、無ければ空。
     */
    final protected function assertMatchesBaseline(string $root, string $check, string $file, Baseline $actual): void
    {
        $mismatches = Baseline::fromLedger(self::ledger($root), $check, $root, $file)->mismatches($actual);

        $this->assertSame([], $mismatches, "ベースライン {$file} と今の木の数が違う:\n".implode("\n", $mismatches));
    }

    /**
     * 照合 2。
     */
    final protected function assertNotRaisedFromMain(string $root, string $file): void
    {
        $raised = self::raisedFromBranch($root, $file, Baselines::MAIN_BRANCH);

        $this->assertSame([], $raised, "ベースライン {$file} を基準の版から上げた:\n".implode("\n", $raised));
    }

    /**
     * 照合 2 の違反(根 $root の作業木のファイル $file と、枝 $branch との分かれ目の版のもの)。作業木にファイルが無ければ空と比べる。
     *
     * @return list<string>
     */
    final protected static function raisedFromBranch(string $root, string $file, string $branch): array
    {
        $revision = GitRevision::mergeBase($root, $branch);
        $base = GitRevision::fileAt($root, $revision, $file);

        if ($base === null) {
            return [];
        }

        $current = is_file($root.'/'.$file)
            ? Baseline::fromJson((string) file_get_contents($root.'/'.$file), $file)
            : Baseline::empty();

        return $current->raisedFrom(Baseline::fromJson($base, "{$revision}:{$file}"));
    }

    final protected static function ledger(string $root): ExclusionLedger
    {
        return ExclusionLedgerFile::read($root, Licenses::LEDGER_CHECK);
    }
}
