<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowContent;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteNode;
use App\Queries\Workflow\CaseListQuery;
use App\Queries\Workflow\CaseNumbers;
use App\Queries\Workflow\CaseRows;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsRecordModules;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 案件一覧の 4 タブ(docs の 設計/ワークフロー/認可と一覧.md 3.2・7 節の「一覧」、形は 画面.md 1.2・4 節): タブの述語・件数(一時保存と未処理だけ)・
 * 処理済と参照の未完了・完了の切替・並び(更新の新しい順)・20 件のページ・解釈できないクエリ・行の項目と can。問い合わせの回数は行の数に依らない。
 * 案件はテストで作る。人は初期データにワークフローの案件を持たず、初期データのフローの参照者でもない利用者(自分が作った行だけが見える)。
 * A = u0004(フローの申請のノードの指定 = A)、B = u0005、C = u0006。
 *
 * @phpstan-import-type CaseRow from CaseRows
 */
final class CaseListQueryTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use BuildsWorkflowCases;
    use UsesSeededRecords;

    private const DATE = '2026-10-13';

    private User $a;

    private User $b;

    private User $c;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->a, $this->b, $this->c] = $this->seededUsers('u0004', 'u0005', 'u0006');
        // 作った案件の updated_at を同じ秒にそろえ、並びを id で決める(並びのテストは updated_at を書き換える)
        $this->travelTo(CarbonImmutable::parse('2026-10-13 09:00:00'));
    }

    /**
     * 申請した案件は処理済(未完了)に出て(申請済タブは無い)、同じ案件が未処理と処理済の両方に出る。件数は一時保存と未処理だけで、どのタブでも同じ。
     */
    public function test_each_tab_lists_its_predicate_and_the_counts_are_the_same_on_every_tab(): void
    {
        $flow = $this->flow('wf_tabs');
        $referred = $this->flow('wf_referred', $this->selection(SelectionConditionKind::User, $this->a->id));
        $drafts = [$this->draft($flow, '下書き'), $this->draft($flow)];
        $pending = $this->applied($flow, $this->b, targets: [$this->a]);
        $both = $this->applied($flow, $this->a, targets: [$this->b]);
        $this->process($this->nodesOf($both)[1], $this->b, WorkflowAction::SendBack, sendBackTo: $this->nodesOf($both)[0]);
        $this->reach($this->nodesOf($both)[0], $this->a);
        $applied = $this->applied($flow, $this->a, targets: [$this->b]);
        $completed = $this->applied($flow, $this->a, completed: true);
        $referencedActive = $this->applied($referred, $this->b, targets: [$this->c]);
        $referencedCompleted = $this->applied($referred, $this->b, completed: true);

        $this->assertSame([$drafts[1]->id, $drafts[0]->id], $this->idsOf($this->listFor($this->a, 'draft')));
        $this->assertSame([$both->id, $pending->id], $this->idsOf($this->listFor($this->a, 'pending')));
        $this->assertSame([$applied->id, $both->id], $this->idsOf($this->listFor($this->a, 'processed')));
        $this->assertSame([$completed->id], $this->idsOf($this->listFor($this->a, 'processed', 'completed')));
        $this->assertSame([$referencedActive->id], $this->idsOf($this->listFor($this->a, 'referenced')));
        $this->assertSame([$referencedCompleted->id], $this->idsOf($this->listFor($this->a, 'referenced', 'completed')));

        foreach (['draft', 'pending', 'processed', 'referenced'] as $tab) {
            $this->assertSame(['draft' => 2, 'pending' => 2], $this->listFor($this->a, $tab)['counts']);
        }
    }

    /**
     * scope は処理済・参照だけが読む(一時保存・未処理では無視し、返す scope は既定の active)。解釈できない値は既定。
     */
    public function test_reads_the_query_and_falls_back_to_the_defaults(): void
    {
        $flow = $this->flow('wf_query');
        $pending = $this->applied($flow, $this->b, targets: [$this->a]);
        $this->applied($flow, $this->a, completed: true);

        $this->assertSame(['tab' => 'draft', 'scope' => 'active'], $this->listFor($this->a, 'draft', 'completed')['query']);
        $this->assertSame(4000, $this->listFor($this->a, 'draft')['commentMaxLength']);
        $this->assertSame(['tab' => 'processed', 'scope' => 'completed'], $this->listFor($this->a, 'processed', 'completed')['query']);

        foreach ([['x', 'y', '0'], [null, null, null], [['pending'], 'completed', 'abc'], ['PENDING', 'Completed', '-1']] as [$tab, $scope, $page]) {
            $list = $this->listFor($this->a, $tab, $scope, $page);
            $this->assertSame(['tab' => 'pending', 'scope' => 'active'], $list['query']);
            $this->assertSame([$pending->id], $this->idsOf($list));
            $this->assertSame(1, $list['cases']['currentPage']);
        }
    }

    public function test_orders_by_updated_at_then_id_and_pages_by_20(): void
    {
        $flow = $this->flow('wf_pages');
        $drafts = array_map(fn (int $i) => $this->draft($flow), range(1, 25));
        $this->touch($drafts[3], '2026-10-13 12:00:00');
        $this->touch($drafts[1], '2026-10-13 11:00:00');
        $this->touch($drafts[2], '2026-10-13 11:00:00');
        $rest = array_reverse(array_map(fn (WorkflowCase $case) => $case->id, [$drafts[0], ...array_slice($drafts, 4)]));

        $first = $this->listFor($this->a, 'draft');
        $second = $this->listFor($this->a, 'draft', page: '2');

        $this->assertSame([$drafts[3]->id, $drafts[2]->id, $drafts[1]->id, ...array_slice($rest, 0, 17)], $this->idsOf($first));
        $this->assertSame(array_slice($rest, 17), $this->idsOf($second));
        $this->assertSame([1, 2, 25], [$first['cases']['currentPage'], $first['cases']['lastPage'], $first['cases']['total']]);
        $this->assertSame(2, $second['cases']['currentPage']);
    }

    public function test_a_row_has_the_listed_fields(): void
    {
        $flow = $this->flow('wf_rows');
        $nameless = User::factory()->create();
        $draft = $this->draft($flow, '下書きの名前');
        $unnamedDraft = $this->draft($flow);
        $active = $this->applied($flow, $nameless, targets: [$this->a]);
        $active->update(['case_number' => 1_234_567, 'name' => '案件の名前']);
        $completed = $this->applied($flow, $this->a, completed: true);
        $completed->update(['completion_reason' => WorkflowCompletionReason::Rejected]);

        $draftRows = array_column($this->listFor($this->a, 'draft')['cases']['data'], null, 'id');
        $activeRow = $this->listFor($this->a, 'pending')['cases']['data'][0];
        $completedRow = $this->listFor($this->a, 'processed', 'completed')['cases']['data'][0];

        $this->assertSame([null, '下書きの名前', 'wf_rows の名前', null, null, 'draft'], [$draftRows[$draft->id]['number'], $draftRows[$draft->id]['name'], $draftRows[$draft->id]['flowName'], $draftRows[$draft->id]['appliedAt'], $draftRows[$draft->id]['currentSummary'], $draftRows[$draft->id]['state']]);
        $this->assertSame('wf_rows の名前', $draftRows[$unnamedDraft->id]['name']);
        $this->assertSame(['id' => $this->a->id, 'code' => 'u0004', 'name' => '伊藤 涼子'], $draftRows[$draft->id]['applicant']);
        $this->assertSame([
            'id' => $active->id, 'number' => 'WF-1234567', 'name' => '案件の名前', 'flowName' => 'wf_rows の名前',
            'applicant' => ['id' => $nameless->id, 'code' => $nameless->code, 'name' => null], 'appliedAt' => $active->applied_at?->toIso8601String(),
            'currentSummary' => ['state' => 'waiting', 'name' => $this->nodesOf($active)[1]->name], 'state' => 'active', 'completionReason' => null, 'revision' => 0, 'pullBackNodes' => [], 'canAttachOnWithdraw' => false,
        ], array_diff_key($activeRow, ['can' => true]));
        $this->assertSame(['WF-000001', 'WF-1234567'], [CaseNumbers::format(1), CaseNumbers::format(1_234_567)]);
        $this->assertSame([CaseNumbers::format((int) $completed->case_number), null, 'completed', 'rejected'], [$completedRow['number'], $completedRow['currentSummary'], $completedRow['state'], $completedRow['completionReason']]);
    }

    /**
     * 行の can は判定の表と 403 の判定の AND(期待は 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 8 節の表と 設計/ワークフロー/認可と一覧.md 3.2 から手で書いた。
     * 現在のノードが 2 つの案件を含む)。
     * create を禁止すると一時保存の「開く」が消え、削除は残る。
     */
    public function test_the_row_can_is_the_same_judgement_as_the_rules(): void
    {
        $flow = $this->flow('wf_can');
        $draft = $this->draft($flow);
        $pullBackable = $this->applied($flow, $this->a, targets: [$this->b]);
        $sentBack = $this->applied($flow, $this->a, targets: [$this->b]);
        $this->process($this->nodesOf($sentBack)[1], $this->b, WorkflowAction::SendBack, sendBackTo: $this->nodesOf($sentBack)[0]);
        $this->reach($this->nodesOf($sentBack)[0], $this->a);
        $twoCurrent = $this->applied($flow, $this->a, targets: [$this->b]);
        $this->reach($this->nodesOf($twoCurrent)[2], $this->a);
        $none = ['pullBack' => false, 'reapply' => false, 'withdraw' => false, 'editDraft' => false, 'deleteDraft' => false];
        $expected = [
            $draft->id => [...$none, 'editDraft' => true, 'deleteDraft' => true],
            $pullBackable->id => [...$none, 'pullBack' => true],
            $sentBack->id => [...$none, 'reapply' => true, 'withdraw' => true],
            $twoCurrent->id => [...$none, 'pullBack' => true],
        ];
        $seen = [];

        foreach (['draft', 'pending', 'processed'] as $tab) {
            foreach ($this->listFor($this->a, $tab)['cases']['data'] as $row) {
                $this->assertSame($expected[$row['id']] ?? null, $row['can'], "{$tab} の案件 {$row['id']}");
                $seen[$row['id']] = true;
            }
        }

        $this->assertEqualsCanonicalizing(array_keys($expected), array_keys($seen));

        $this->assertTrue($this->rowOf('draft', $draft)['can']['editDraft']);
        $this->assertTrue($this->rowOf('processed', $pullBackable)['can']['pullBack']);
        $this->assertSame([true, true], [$this->rowOf('pending', $sentBack)['can']['reapply'], $this->rowOf('pending', $sentBack)['can']['withdraw']]);

        $this->deny('m_wf_can', RecordModuleAction::Create);
        $this->assertSame([false, true], [$this->rowOf('draft', $draft)['can']['editDraft'], $this->rowOf('draft', $draft)['can']['deleteDraft']]);
    }

    /**
     * 20 行のページ(フロー 2・業務モジュール 2)の一覧全体の問い合わせの回数が、2 行のページと同じ(行の数に依らない)。
     */
    public function test_the_number_of_queries_does_not_depend_on_the_number_of_rows(): void
    {
        $flows = [$this->flow('wf_count_a'), $this->flow('wf_count_b')];

        foreach ($flows as $flow) {
            $this->applied($flow, $this->b, targets: [$this->a]);
        }

        $two = $this->countQueries();

        foreach (range(1, 10) as $ignored) {
            foreach ($flows as $flow) {
                $this->applied($flow, $this->b, targets: [$this->a]);
            }
        }

        $this->assertCount(20, $this->listFor($this->a, 'pending')['cases']['data']);
        $this->assertSame($two, $this->countQueries());
    }

    /**
     * @return array{query: array{tab: string, scope: string}, counts: array{draft: int, pending: int}, cases: array{data: list<CaseRow>, currentPage: int, lastPage: int, total: int}, commentMaxLength: int}
     */
    private function listFor(User $user, mixed $tab, mixed $scope = null, mixed $page = null): array
    {
        return app(CaseListQuery::class)->list(new AccessContext($user->id, null, null, CarbonImmutable::parse(self::DATE), 'ja'), $tab, $scope, $page);
    }

    /**
     * @param  array{cases: array{data: list<CaseRow>}}  $list
     * @return list<int>
     */
    private function idsOf(array $list): array
    {
        return array_column($list['cases']['data'], 'id');
    }

    /**
     * @return array<string, mixed>
     */
    private function rowOf(string $tab, WorkflowCase $case): array
    {
        return array_column($this->listFor($this->a, $tab)['cases']['data'], null, 'id')[$case->id];
    }

    private function countQueries(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->listFor($this->a, 'pending');

        return count(DB::getQueryLog());
    }

    /**
     * フロー(コンテンツ = 業務モジュール m_<コード>(全員に 4 つのアクション)、版 1 つのルート = 申請(A)+ 承認 2)。
     */
    private function flow(string $code, ?int $referrerSubjectGroupId = null): WorkflowFlow
    {
        $flow = WorkflowFlow::factory()->create([
            'code' => $code,
            'name' => "{$code} の名前",
            'workflow_content_id' => WorkflowContent::factory()->create(['record_module_id' => $this->recordModule("m_{$code}")->id])->id,
            'authz_subject_group_id' => $referrerSubjectGroupId,
        ]);
        $version = WorkflowFlowVersion::factory()->create(['workflow_flow_id' => $flow->id]);
        WorkflowRouteNode::factory()->create(['workflow_flow_version_id' => $version->id, 'position' => 1, 'authz_subject_group_id' => $this->selection(SelectionConditionKind::User, $this->a->id)]);
        WorkflowRouteNode::factory()->count(2)->sequence(['position' => 2], ['position' => 3])->create(['workflow_flow_version_id' => $version->id]);

        return $flow;
    }

    private function draft(WorkflowFlow $flow, ?string $name = null): WorkflowCase
    {
        return WorkflowCase::factory()->draft()->create(['workflow_flow_id' => $flow->id, 'user_id' => $this->a->id, 'draft_input' => ['name' => $name, 'comment' => null, 'values' => [], 'details' => []]]);
    }

    /**
     * 申請した案件(ノード 3。申請のノードを申請者が処理済みにし、targets があれば承認 1 を到達させる。completed なら完了(承認))。
     *
     * @param  list<User>  $targets
     */
    private function applied(WorkflowFlow $flow, User $applicant, array $targets = [], bool $completed = false): WorkflowCase
    {
        $case = ($completed ? WorkflowCase::factory()->completed() : WorkflowCase::factory()->active())->create(['workflow_flow_id' => $flow->id, 'user_id' => $applicant->id]);
        [$apply, $approve1] = $this->caseNodes($case, 3);
        $this->process($apply, $applicant, WorkflowAction::Apply);

        if ($targets !== []) {
            $this->reach($approve1, ...$targets);
        }

        return $case;
    }

    private function touch(WorkflowCase $case, string $updatedAt): void
    {
        WorkflowCase::query()->whereKey($case->id)->toBase()->update(['updated_at' => $updatedAt]);
    }
}
