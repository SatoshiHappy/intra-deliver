<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Workflow\CaseAttachmentRules;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseAttachEntries;
use App\Queries\Workflow\CaseDetailQuery;
use App\Queries\Workflow\CaseListQuery;
use App\Queries\Workflow\CaseOperationDecider;
use App\Queries\Workflow\CaseRows;
use App\Queries\Workflow\WorkflowAttachmentLimits;
use Illuminate\Database\Eloquent\Collection;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\OperatesStepAttachments;
use Tests\Support\TransactionalTestCase;

/**
 * 詳細と一覧の行の引戻し・取止めの入口の canAttach と、ページの attachmentLimits(docs の 設計/ワークフロー/申請の周辺/添付/README.md の
 * 5 節): 引戻しは引戻す先のノード(申請のノードならステップを見ない)、取止めは申請の系統で、どちらも案件に写したフローの値を読む。
 * 値は業務処理の 422 と食い違わない。完了した申請と、ダイアログを開かない行(関連する案件・ホームの節)はどれも偽。一覧の問い合わせは行の数に依らない。
 */
final class CaseAttachEntriesTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use OperatesStepAttachments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAttachments();
    }

    /**
     * @return array<string, array{bool, bool, bool}>
     */
    public static function combinations(): array
    {
        return [
            '使用 × 使う × 追加できる' => [true, true, false],
            '使用 × 使う × 追加できない' => [true, true, true],
            '使用 × 使わない × 追加できる' => [true, false, false],
            '使用 × 使わない × 追加できない' => [true, false, true],
            '不使用 × 使う × 追加できる' => [false, true, false],
            '不使用 × 使う × 追加できない' => [false, true, true],
            '不使用 × 使わない × 追加できる' => [false, false, false],
            '不使用 × 使わない × 追加できない' => [false, false, true],
        ];
    }

    /**
     * 承認のノードへの引戻し: 業務モジュール × 写したフローの値(今のフローの値は逆にする)× そのノードの設定。詳細・一覧の行・業務処理が一致する。
     */
    #[DataProvider('combinations')]
    public function test_a_pull_back_to_an_approval_node_agrees_with_the_operation(
        bool $module,
        bool $copy,
        bool $prevents,
    ): void {
        [$case, $u0002] = $this->caseReadyFor('pull_back');
        $case->update(['is_attachment_enabled' => $copy]);
        $this->caseNode($case, 'approval1')->update(['prevents_attachment_add' => $prevents]);
        $this->switchAttachments($module, ! $copy);
        $expected = $module && $copy && ! $prevents;

        $detail = $this->detail($u0002, $case);
        $row = $this->row($u0002, $case, 'processed');

        $nodeId = $this->caseNode($case, 'approval1')->id;
        $this->assertSame([[$nodeId, $expected]], $this->pullBacks($detail['pullBackNodes']));
        $this->assertSame($this->pullBacks($detail['pullBackNodes']), $this->pullBacks($row['pullBackNodes']));
        $this->assertSame([false, false], [$detail['canAttachOnWithdraw'], $row['canAttachOnWithdraw']]);
        $limits = $expected ? app(WorkflowAttachmentLimits::class)->props() : null;
        $this->assertSame($limits, $detail['attachmentLimits']);
        $this->assertOperationAgrees($expected, fn () => $this->stepWithFile('pull_back', $u0002, $case));
    }

    /**
     * 申請のノードへの引戻しと取止めは申請の系統(ステップの設定を見ず、写したフローの値と業務モジュールを見る)。取止めは許されるときだけ求める。
     */
    public function test_a_pull_back_to_the_apply_node_and_a_withdrawal_read_the_applicant_line(): void
    {
        $u0001 = $this->seededUser('u0001');
        $case = $this->applyAs($u0001);
        $this->caseNode($case, 'approval1')->update(['prevents_attachment_add' => true]);

        $applyNodeId = $this->caseNode($case, 'apply')->id;
        $this->assertSame([[$applyNodeId, true]], $this->pullBacks($this->detail($u0001, $case)['pullBackNodes']));
        $this->assertFalse($this->detail($u0001, $case)['canAttachOnWithdraw']);

        $this->step('send_back', $this->seededUser('u0002'), $case);
        $this->assertSame([true, true], $this->withdrawals($u0001, $case));
        $case->update(['is_attachment_enabled' => false]);
        $this->assertSame([false, false], $this->withdrawals($u0001, $case));
        $this->assertNull($this->detail($u0001, $case)['attachmentLimits']);
        $this->assertOperationAgrees(false, fn () => $this->stepWithFile('withdraw', $u0001, $case));
        $case->update(['is_attachment_enabled' => true]);
        $this->assertOperationAgrees(true, fn () => $this->stepWithFile('withdraw', $u0001, $case));
    }

    public function test_a_completed_case_has_no_entry_that_accepts_files(): void
    {
        [$case, $u0002] = $this->caseReadyFor('approve');
        $this->step('approve', $u0002, $case);
        $this->step('approve', $this->seededUser('u0003'), $case);

        foreach (['u0001', 'u0002', 'u0003'] as $code) {
            $detail = $this->detail($this->seededUser($code), $case);
            $entries = [$detail['pullBackNodes'], $detail['canAttachOnWithdraw'], $detail['attachmentLimits']];
            $this->assertSame([[], false, null], $entries, $code);
        }
    }

    /**
     * 処理のダイアログを開かない行(関連する案件の行・ホームの節が読む既定)は、一覧で真になる入口も偽。
     */
    public function test_rows_that_do_not_open_dialogs_are_false(): void
    {
        $u0001 = $this->seededUser('u0001');
        $case = $this->applyAs($u0001);
        $this->step('send_back', $this->seededUser('u0002'), $case);
        $requester = new Requester($u0001->id, null);

        $rows = fn (bool ...$opensDialogs) => app(CaseRows::class)
            ->rows($requester, $this->operationDay(), 'ja', new Collection([$case->refresh()]), ...$opensDialogs)[0];
        [$listed, $plain] = [$rows(true), $rows()];

        $this->assertSame([true, true], [$listed['canAttachOnWithdraw'], $listed['can']['withdraw']]);
        $this->assertSame([false, true], [$plain['canAttachOnWithdraw'], $plain['can']['withdraw']]);
        $this->assertSame([], array_filter(array_column($plain['pullBackNodes'], 'canAttach')));
    }

    /**
     * 一覧の問い合わせの数は行の数・フローの数に依らない(引戻せるノードと業務モジュールの値をまとめて引く)。
     */
    public function test_the_list_reads_the_same_number_of_queries_for_more_rows(): void
    {
        $this->createFlow('wf_other', 'ほかのフロー');
        $u0002 = $this->seededUser('u0002');
        $caseIds = [];
        $approve = function (string $flowCode) use ($u0002, &$caseIds): void {
            $case = $this->applyAs($this->seededUser('u0001'), $flowCode);
            $this->step('approve', $u0002, $case);
            $caseIds[] = $case->id;
        };
        $list = fn () => app(CaseListQuery::class)->list($this->contextOf($u0002), 'processed', 'active', null);
        $approve(self::FLOW_CODE);

        $one = $this->recordedSql($list);
        $approve(self::FLOW_CODE);
        $approve('wf_other');
        $three = $this->recordedSql($list);

        $rows = array_filter($list()['cases']['data'], fn (array $row) => in_array($row['id'], $caseIds, true));
        $canAttach = array_map(fn (array $row) => $row['pullBackNodes'][0]['canAttach'], array_values($rows));
        $this->assertSame([true, true, true], $canAttach);
        $this->assertSame(count($one), count($three));
    }

    /**
     * 業務モジュールの値(遅延)を渡せるのは詳細の 1 件の案件だけ。要る案件が 2 件以上なら呼び手の誤りで投げる(1 件なら渡した値を使う)。
     */
    public function test_a_shared_module_value_is_only_for_one_case(): void
    {
        $u0001 = $this->seededUser('u0001');
        $cases = [$this->applyAs($u0001), $this->applyAs($u0001)];
        $requester = new Requester($u0001->id, null);
        $loaded = array_map(fn (WorkflowCase $case) => $case->load('workflowFlow'), $cases);
        $read = app(CaseOperationDecider::class)->situationsOf($requester, $this->operationDay(), $loaded);
        $situations = array_values($read);
        $withdrawable = [$cases[0]->id => true, $cases[1]->id => true];
        $entries = app(CaseAttachEntries::class);
        $reads = 0;
        $shared = function () use (&$reads): bool {
            $reads++;

            return true;
        };

        $this->assertThrows(fn () => $entries->of($situations, $withdrawable, $shared), LogicException::class);
        $this->assertSame(0, $reads);
        $this->assertTrue($entries->of([$situations[0]], [$cases[0]->id => true], $shared)[$cases[0]->id]['withdraw']);
        $this->assertSame(1, $reads);
    }

    /**
     * 業務処理が、props の値が真なら添付を受け、偽なら 422 attachments を返す。
     */
    private function assertOperationAgrees(bool $canAttach, callable $operation): void
    {
        if ($canAttach) {
            $operation();
            $this->addToAssertionCount(1);

            return;
        }

        $errors = $this->validationErrors($operation(...));
        $this->assertSame(['attachments' => CaseAttachmentRules::NOT_ATTACHABLE], $errors);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(User $user, WorkflowCase $case): array
    {
        return app(CaseDetailQuery::class)->show($this->contextOf($user), $case->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(User $user, WorkflowCase $case, string $tab): array
    {
        $rows = app(CaseListQuery::class)->list($this->contextOf($user), $tab, 'active', null)['cases']['data'];

        return array_values(array_filter($rows, fn (array $row) => $row['id'] === $case->id))[0]
            ?? $this->fail("{$tab} のタブに案件 {$case->id} の行が無い");
    }

    /**
     * 詳細と一覧の行(未処理のタブ)の canAttachOnWithdraw。
     *
     * @return array{bool, bool}
     */
    private function withdrawals(User $user, WorkflowCase $case): array
    {
        return [
            $this->detail($user, $case)['canAttachOnWithdraw'],
            $this->row($user, $case, 'pending')['canAttachOnWithdraw'],
        ];
    }

    /**
     * @param  mixed  $nodes  pullBackNodes
     * @return list<array{mixed, mixed}> [id, canAttach]
     */
    private function pullBacks(mixed $nodes): array
    {
        $nodes = is_array($nodes) ? array_values($nodes) : [];

        return array_map(fn (array $node) => [$node['id'], $node['canAttach']], $nodes);
    }

    private function stepWithFile(string $name, User $user, WorkflowCase $case): void
    {
        $this->step($name, $user, $case, [$this->uploadedFile("{$name}.pdf")]);
    }
}
