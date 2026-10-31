<?php

namespace Tests\Feature\Queries\Workflow;

use App\Queries\Workflow\CaseDetailQuery;
use Tests\Support\ReadsCaseLists;
use Tests\Support\TransactionalTestCase;

/**
 * 詳細の principals の canAttach を求める問い合わせは、選択肢の数(操作 × 処理者)に依らない(docs の
 * 設計/ワークフロー/申請の周辺/添付/README.md の 5 節。業務モジュールの値はページで 1 回だけ読む)。ステップ c1 の処理担当者 = u0005・u0006・u0007。
 * 全体の問い合わせの数は代理元の数に比例する(引戻せるノードを処理者ごとに読む = CaseProcessorChoices)ので、業務モジュールの値の読みで比べる。
 */
final class CaseAttachPrincipalsQueriesTest extends TransactionalTestCase
{
    use ReadsCaseLists;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
        $this->addLine($this->listNode('c1', ['u0005', 'u0006', 'u0007']), $this->listNode('c2', ['u0013']));
    }

    public function test_more_choices_do_not_add_queries(): void
    {
        $this->addProxy($this->seededUser('u0005'), $this->seededUser('u0007'));
        $this->addProxy($this->seededUser('u0006'), $this->seededUser('u0007'));
        $case = $this->applyAs($this->seededUser(self::APPLICANT));
        $show = fn (string $code) => app(CaseDetailQuery::class)
            ->show($this->contextOf($this->seededUser($code)), $case->id);
        $detail = [];

        $one = $this->recordedSql(function () use (&$detail, $show) {
            $detail['one'] = $show('u0005');
        });
        $three = $this->recordedSql(function () use (&$detail, $show) {
            $detail['three'] = $show('u0007');
        });

        $counts = fn (array $detail) => array_map(
            fn (array $choices) => array_column($choices, 'canAttach'),
            $detail['nodeActions'][0]['principals'],
        );
        $this->assertSame(
            array_fill_keys(['approve', 'approveEarly', 'reject', 'sendBack', 'hold', 'transfer'], [true]),
            $counts($detail['one']),
        );
        $this->assertSame(
            array_fill_keys(['approve', 'approveEarly', 'reject', 'sendBack', 'hold', 'transfer'], [true, true, true]),
            $counts($detail['three']),
        );
        $reads = fn (array $log, string $table) => count(array_filter(
            $log,
            fn (string $sql) => str_contains($sql, "\"{$table}\""),
        ));

        foreach (['record_modules', 'workflow_contents'] as $table) {
            $this->assertSame($reads($one, $table), $reads($three, $table), $table);
        }
    }

    /**
     * 入口(承認の選択肢・引戻し)がある詳細は、入口が無い詳細より、業務モジュールの値の読み(コンテンツと業務モジュールの 2 つの SQL)を
     * ちょうど 1 回分だけ多く読む(CaseNodeActions と CaseAttachEntries が共有する)。入口が無い詳細は読まない。
     * u0005 は p1 を承認済みで p2 の処理対象者(承認 = 選択肢、p1 への引戻し)、u0011 は u0005 の代理先(代理元の引戻しの行を通る)、
     * 申請者は p1 が承認された後は引戻せず入口が無い。
     */
    public function test_the_choices_and_the_pull_back_share_one_read_of_the_module_value(): void
    {
        $this->addVersion([
            [$this->applyNode(), $this->listNode('p1', ['u0005']), $this->listNode('p2', ['u0005', 'u0013'])],
            $this->chain(['apply', 'p1'], ['p1', 'p2']),
        ], '2026-02-01');
        $this->addProxy($this->seededUser('u0005'), $this->seededUser('u0011'));
        $case = $this->applyAs($this->seededUser(self::APPLICANT));
        $this->approve($case, 'p1', 'u0005');
        $p1 = $this->labelIds($case)['p1'];
        $show = function (string $code) use ($case): array {
            $detail = [];
            $log = $this->recordedSql(function () use (&$detail, $case, $code) {
                $detail = app(CaseDetailQuery::class)->show($this->contextOf($this->seededUser($code)), $case->id);
            });

            return [$detail, $this->moduleTableReads($log)];
        };

        [$none, $base] = $show(self::APPLICANT);
        $this->assertSame([[], [], false, null], [
            $none['nodeActions'], $none['pullBackNodes'], $none['canAttachOnWithdraw'], $none['attachmentLimits'],
        ]);

        foreach (['u0005', 'u0011'] as $code) {
            [$detail, $reads] = $show($code);

            $this->assertSame([[$p1, true]], array_map(
                fn (array $node) => [$node['id'], $node['canAttach']],
                $detail['pullBackNodes'],
            ), $code);
            $approve = $detail['nodeActions'][0]['principals']['approve'];
            $this->assertSame([true], array_unique(array_column($approve, 'canAttach')), $code);
            $this->assertSame($base + 2, $reads, $code);
        }
    }

    /**
     * 業務モジュールの表とコンテンツの表を読む SQL の数(値の読みはコンテンツ → 業務モジュールの 2 つ。1 行ずつの読みも数える)。
     *
     * @param  list<string>  $log
     */
    private function moduleTableReads(array $log): int
    {
        $isRead = fn (string $sql) => str_contains($sql, '"record_modules"')
            || str_contains($sql, '"workflow_contents"');

        return count(array_filter($log, $isRead));
    }
}
