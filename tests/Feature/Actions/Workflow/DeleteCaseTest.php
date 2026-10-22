<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\CreateDraft;
use App\Actions\Workflow\DraftInput;
use App\Models\Notice\Notice;
use App\Models\Records\Record;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseAssignment;
use App\Models\Workflow\WorkflowCaseConfirmation;
use App\Models\Workflow\WorkflowCaseConfirmTarget;
use App\Models\Workflow\WorkflowCaseEdge;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseHistoryUser;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseNodeTarget;
use App\Models\Workflow\WorkflowCaseSendBack;
use App\Queries\Workflow\WorkflowNoticeTargets;
use App\Queries\Workflow\WorkflowRecordHolds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\OperatesAsCaseManager;
use Tests\Support\ReadsCaseNotices;
use Tests\Support\TransactionalTestCase;

/**
 * 案件の削除(docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.7 節・同時実行と検査.md の 12 節、
 * 設計/ワークフロー/案件の運用/データモデル/表.md の 3.13 節):
 * 案件の行と部品が連鎖で全部消え、業務データのレコードは残って持ち主が外れ、通知の行は残って移り先を持たなくなる。履歴を書かず通知を作らない。
 * 一時保存・改訂番号の違いは 422 case。削除の後の操作は 404、操作が先に確定した後の削除は改訂番号の 422。管理者 = u0004。
 * ルートの見本 R1(課長に確認する人 u0010)で、課長が合議と責任者を指定して承認し(指定の行・確認対象者)、合議の子 1 を振替えた(履歴の人)案件を消す。
 */
final class DeleteCaseTest extends TransactionalTestCase
{
    use OperatesAsCaseManager;
    use ReadsCaseNotices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
        $this->addVersion($this->r1($this->confirmGroup('u0010')));
    }

    /**
     * 消すと部品の行が 0 になり、レコードは残って持ち主(未完了の案件)が外れる。通知の行は残り、移り先を持たない(文だけ)。
     */
    public function test_deleting_cascades_to_the_parts_and_keeps_the_record_and_notices(): void
    {
        $case = $this->busyCase();
        $this->confirmAs($case, 'u0010');
        $parts = fn () => $this->partCounts($case->id);
        $notices = Notice::query()->where('target_id', $case->id)->count();
        $holdsBefore = array_keys(app(WorkflowRecordHolds::class)->holdsOf([(int) $case->record_id]));
        $this->assertNotContains(0, $parts());
        $lastId = $this->lastNoticeId();

        $this->deleteCaseAs($case);

        $this->assertFalse(WorkflowCase::query()->whereKey($case->id)->exists());
        $this->assertSame(array_fill_keys(array_keys($parts()), 0), $parts());
        $this->assertTrue(Record::query()->whereKey($case->record_id)->exists());
        $holdsAfter = array_keys(app(WorkflowRecordHolds::class)->holdsOf([(int) $case->record_id]));
        $this->assertSame([[(int) $case->record_id], []], [$holdsBefore, $holdsAfter]);
        $kept = Notice::query()->where('target_id', $case->id)->count();
        $this->assertSame([$notices, []], [$kept, $this->noticesSince($lastId)]);
        $context = $this->contextOf($this->seededUser('u0002'));
        $type = WorkflowNoticeTargets::CASE_TARGET_TYPE;
        $this->assertSame([], app(WorkflowNoticeTargets::class)->resolve($type, [$case->id], $context));
    }

    /**
     * 完了の案件も消せる。
     */
    public function test_a_completed_case_can_be_deleted(): void
    {
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $this->approve($case, 'section_chief', 'u0002', ['council' => ['u0005', 'u0009'], 'owner' => ['u0007']]);
        // 合議 2 人で可決 2 なので、1 人の否認で可決できなくなり完了する
        $this->reject($case, 'council.1', 'u0005');
        $this->assertSame('completed', WorkflowCase::query()->findOrFail($case->id)->state->value);

        $this->deleteCaseAs($case);

        $this->assertFalse(WorkflowCase::query()->whereKey($case->id)->exists());
    }

    /**
     * 一時保存(管理者自身のもの = 閲覧できる)と、改訂番号が違う削除は 422 case で何も変わらない。
     */
    public function test_a_draft_and_a_stale_revision_are_rejected(): void
    {
        $context = $this->contextOf($this->seededUser(self::MANAGER));
        $draftId = app(CreateDraft::class)->execute($context, self::FLOW_CODE, new DraftInput('下書き', null, [], []));
        $draft = WorkflowCase::query()->findOrFail($draftId);
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $before = $this->workflowCounts();

        $errors = [
            $this->attemptOf(fn () => $this->deleteCaseAs($draft)),
            $this->attemptOf(fn () => $this->deleteCaseAs($case, revision: $this->revisionOf($case) - 1)),
        ];

        $this->assertSame([
            ['case' => 'この申請は今この操作をできません'],
            ['case' => '画面を表示した後に申請が変更されました。再表示してください'],
        ], $errors);
        $this->assertSame($before, $this->workflowCounts());
    }

    /**
     * 順に流す: 削除の後の承認は案件が無く 404。先に承認が確定した後に、開いた時の改訂番号で削除すると 422(改訂番号)。
     */
    public function test_operations_and_deletion_are_ordered_by_the_revision(): void
    {
        [$deletedFirst] = $this->applyOnRoute(['budget' => 100]);
        [$approvedFirst] = $this->applyOnRoute(['budget' => 100]);
        $opened = $this->revisionOf($approvedFirst);
        $designations = ['council' => ['u0005', 'u0009'], 'owner' => ['u0007']];

        $chiefId = $this->labelIds($deletedFirst)['section_chief'];
        $this->deleteCaseAs($deletedFirst);
        $context = $this->contextOf($this->seededUser('u0002'));
        $this->assertThrows(
            fn () => app(ApproveCase::class)->execute($context, $deletedFirst->id, $opened, $chiefId, null, []),
            ModelNotFoundException::class,
        );
        $this->approve($approvedFirst, 'section_chief', 'u0002', $designations);
        $error = $this->attemptOf(fn () => $this->deleteCaseAs($approvedFirst, revision: $opened));

        $this->assertSame(['case' => '画面を表示した後に申請が変更されました。再表示してください'], $error);
        $this->assertTrue(WorkflowCase::query()->whereKey($approvedFirst->id)->exists());
    }

    /**
     * 課長が合議 u0005・u0009・u0011 と責任者 u0007 を指定して承認し、合議の子 1 を u0005 が u0006 へ振替えた案件(予算 100)。
     */
    private function busyCase(): WorkflowCase
    {
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $designations = ['council' => ['u0005', 'u0009', 'u0011'], 'owner' => ['u0007']];
        $this->approve($case, 'section_chief', 'u0002', $designations);
        $this->transferOn($case, 'council.1', 'u0005', $this->seededUser('u0006')->id);

        return $case;
    }

    /**
     * 案件の部品の表ごとの行の数。
     *
     * @return array<string, int>
     */
    private function partCounts(int $caseId): array
    {
        $byCase = fn (string $model) => $model::query()->where('workflow_case_id', $caseId)->count();
        $byNode = fn (string $model) => $model::query()
            ->whereHas('workflowCaseNode', fn (Builder $query) => $query->where('workflow_case_id', $caseId))->count();

        return [
            'nodes' => $byCase(WorkflowCaseNode::class),
            'edges' => $byCase(WorkflowCaseEdge::class),
            'send_backs' => $byCase(WorkflowCaseSendBack::class),
            'assignments' => $byCase(WorkflowCaseAssignment::class),
            'targets' => $byNode(WorkflowCaseNodeTarget::class),
            'histories' => $byCase(WorkflowCaseHistory::class),
            'history_users' => $byCase(WorkflowCaseHistoryUser::class),
            'confirm_targets' => $byCase(WorkflowCaseConfirmTarget::class),
            'confirmations' => $byCase(WorkflowCaseConfirmation::class),
        ];
    }
}
