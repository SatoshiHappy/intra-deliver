<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowAction;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNodeTarget;
use App\Models\Workflow\WorkflowContent;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteNode;
use App\Queries\Workflow\CaseDetailQuery;
use App\Queries\Workflow\CaseNumbers;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsRecordModules;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\TransactionalTestCase;

/**
 * 案件の詳細(docs の 設計/ワークフロー/画面.md 5 節、認可と一覧.md 2 節、業務データとの接続/README.md 5・6・9 節): 閲覧できない・一時保存は 404、
 * 閲覧の判定より前に業務データの入れ物の読み取りの SQL を出さない、組み立ての後にもう 1 回閲覧を判定する(途中で閲覧を失えば 404)、基本情報・ノード・履歴・
 * can・差戻し先、再申請の差分、最後の提出の後の更新(秒の精度)、関連する案件。A = u0001(申請者)、B = u0002(承認 1)、C = u0003・E = u0005(承認 2 の処理対象者)、D = u0004(関わらない)。
 */
final class CaseDetailQueryTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use BuildsWorkflowCases;

    private const DATE = '2026-10-13';

    /** @var array<string, User> */
    private array $users = [];

    private RecordModule $targets;

    private RecordField $targetName;

    private RecordField $title;

    private RecordField $project;

    private WorkflowFlow $flow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-13 09:00:00'));
        $this->users = array_combine(['a', 'b', 'c', 'd', 'e'], $this->seededUsers('u0001', 'u0002', 'u0003', 'u0004', 'u0005'));
        $this->targets = $this->recordModule('wf_targets');
        $this->targetName = $this->field($this->targets, 'target_name', attributes: ['is_title' => true]);
        $module = $this->recordModule('wf_detail');
        $this->title = $this->field($module, 'title', attributes: ['is_title' => true]);
        $this->project = $this->referenceField($module, 'project', $this->targets);
        $this->flow = $this->flowOf($module);
    }

    public function test_a_case_not_visible_a_draft_and_a_missing_case_are_not_found(): void
    {
        $case = $this->standardCase();
        $draft = WorkflowCase::factory()->draft()->create(['workflow_flow_id' => $this->flow->id, 'user_id' => $this->users['a']->id]);

        foreach ([[$this->users['d'], $case->id], [null, $case->id], [$this->users['a'], $draft->id], [$this->users['a'], 0]] as [$viewer, $caseId]) {
            $this->assertThrows(fn () => $this->show($viewer, $caseId), ModelNotFoundException::class);
        }
    }

    /**
     * 閲覧できない案件の詳細の要求では、業務データの入れ物の表の SQL が出ない(閲覧できる案件では出る = 記録の仕方の確かめ)。
     */
    public function test_the_records_tables_are_not_read_before_the_visibility_is_judged(): void
    {
        $case = $this->standardCase();
        $hidden = $this->recordQueries(fn () => $this->assertThrows(fn () => $this->show($this->users['d'], $case->id), ModelNotFoundException::class));
        $visible = $this->recordQueries(fn () => $this->show($this->users['c'], $case->id));

        $this->assertSame([], $hidden);
        $this->assertNotSame([], $visible);
    }

    /** 閲覧の判定の問い合わせ(案件の行を閲覧の述語で読む)が、最初と組み立ての後(履歴の後の最後の問い合わせ)の 2 回出る(同時実行は作れないので SQL の記録で確かめる)。 */
    public function test_the_visibility_is_judged_again_after_the_assembly(): void
    {
        $case = $this->standardCase();
        DB::enableQueryLog();

        $this->show($this->users['c'], $case->id);
        $sql = array_map(fn (mixed $statement) => (string) $statement, array_column(DB::getQueryLog(), 'query'));
        $judgements = array_keys(array_filter($sql, fn (string $statement) => str_starts_with($statement, 'select * from "workflow_cases" where "workflow_cases"."id" = ? and "state" <> ?')));
        $histories = array_keys(array_filter($sql, fn (string $statement) => str_starts_with($statement, 'select * from "workflow_case_histories" where "workflow_case_id" = ?')));
        $this->assertSame([2, 1, true, count($sql) - 1], [count($judgements), count($histories), $judgements[0] < $histories[0], $judgements[1]]);
    }

    /** 組み立ての途中(履歴を読んだ直後)に閲覧を失う(処理対象者でなくなる)と、組み立ての後の判定で 404 になり、閲覧を失った後の履歴を返さない。 */
    public function test_a_viewer_who_loses_the_visibility_during_the_assembly_is_not_found(): void
    {
        $case = $this->standardCase();
        $lost = false;
        DB::listen(function (QueryExecuted $query) use (&$lost): void {
            if (! $lost && str_starts_with($query->sql, 'select * from "workflow_case_histories" where "workflow_case_id" = ?')) {
                $lost = true;
                WorkflowCaseNodeTarget::query()->where('user_id', $this->users['c']->id)->delete();
            }
        });

        $this->assertThrows(fn () => $this->show($this->users['c'], $case->id), ModelNotFoundException::class);
        $this->assertTrue($lost, '最初の閲覧の判定を通って履歴を読んだ');
    }

    /**
     * 現在のノードが無い(完了の)案件では処理対象者を問い合わせない(現在のノードの id が無いまま絞る SQL が出ない)。
     */
    public function test_a_completed_case_does_not_ask_for_targets(): void
    {
        $case = $this->appliedCase($this->record(), $this->users['a'], completed: true);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $detail = $this->show($this->users['a'], $case->id);

        $this->assertNull($detail['case']['currentSummary']);
        $this->assertSame([], array_values(array_filter(array_column(DB::getQueryLog(), 'query'), fn (mixed $sql) => str_contains((string) $sql, '"workflow_case_node_id" is null'))));
    }

    public function test_returns_the_case_diagram_histories_can_and_node_actions(): void
    {
        $case = $this->standardCase();
        [$apply, $approve1, $approve2] = $this->nodesOf($case);
        [$detail, $at] = [$this->show($this->users['c'], $case->id), now()->toIso8601String()];
        $due = ['reachedOn' => self::DATE, 'elapsedDays' => 0, 'dueOn' => null, 'overdue' => false];

        $this->assertSame([
            'id' => $case->id, 'number' => CaseNumbers::format((int) $case->case_number), 'name' => $case->name, 'state' => 'active', 'completionReason' => null,
            'flow' => ['code' => $this->flow->code, 'name' => $this->flow->name], 'applicant' => ['id' => $this->users['a']->id, 'code' => 'u0001', 'name' => '佐々木 陽太'],
            'department' => ['id' => $case->department_id, 'code' => $case->department()->sole()->code, 'name' => null],
            'appliedAt' => $case->applied_at?->toIso8601String(), 'completedAt' => null, 'baseDate' => $case->base_date?->format('Y-m-d'), 'version' => 1,
            'currentSummary' => ['state' => 'waiting', 'name' => $approve2->name], 'deadline' => $due, 'revision' => 0,
        ], $detail['case']);
        $this->assertSame(['wf_detail', $case->record_id, false], [$detail['content']['module']['code'], $detail['content']['record']['id'], $detail['content']['updatedAfterSubmission']]);
        $this->assertSame([
            [$apply->id, 'apply', 'done', [[$this->users['a']->id, false]], ['apply', $at]],
            [$approve1->id, 'approve', 'done', [[$this->users['b']->id, false]], ['approve', $at]],
            [$approve2->id, 'approve', 'waiting', [[$this->users['c']->id, true], [$this->users['e']->id, false]], null],
        ], array_map(fn (array $node) => [
            $node['id'], $node['kind'], $node['state'],
            array_map(fn (array $person) => [$person['id'], $person['isMe']], $node['people']),
            $node['processed'] === null ? null : [$node['processed']['action'], $node['processed']['at']],
        ], $detail['nodes']));
        $this->assertSame([[[$apply->id, $approve1->id, null], [$approve1->id, $approve2->id, null]], []], [array_map(fn (array $edge) => [$edge['from'], $edge['to'], $edge['taken']], $detail['edges']), $detail['councils']]);
        $this->assertSame([[$apply->name, 'apply', $this->users['a']->id, $at, null, null, null, null, []], [$approve1->name, 'approve', $this->users['b']->id, $at, null, null, null, null, []]], array_map(fn (array $history) => [
            $history['nodeName'], $history['action'], $history['actor']['id'], $history['at'], $history['comment'], $history['sendBackNodeName'], $history['diff'], $history['diffBaseAt'], $history['assignments'],
        ], $detail['histories']));
        $this->assertSame([['pullBack' => false, 'reapply' => false, 'withdraw' => false, 'confirm' => false], 4000], [$detail['can'], $detail['commentMaxLength']]);
        $this->assertSame([['nodeId' => $approve2->id, 'name' => $approve2->name, 'approve' => true, 'approveEarly' => true, 'reject' => true, 'sendBack' => true, 'hold' => true, 'release' => false, 'transfer' => true, 'blockedReason' => null, 'holdBlockedReason' => null, 'sendBackTargets' => [['id' => $apply->id, 'name' => $apply->name], ['id' => $approve1->id, 'name' => $approve1->name]], 'sendBackRestricted' => false, 'isCouncilVote' => false, 'principals' => array_fill_keys(['approve', 'approveEarly', 'reject', 'sendBack', 'hold', 'transfer'], [['id' => $this->users['c']->id, 'name' => '高橋 誠', 'isSelf' => true]]), 'designations' => []]], $detail['nodeActions']);
        $this->assertSame([['pullBack' => true, 'reapply' => false, 'withdraw' => false, 'confirm' => false], []], array_values(array_intersect_key($this->show($this->users['b'], $case->id), ['can' => 0, 'nodeActions' => 0])));
    }

    public function test_a_send_back_row_names_its_target_node(): void
    {
        $case = $this->standardCase();
        [$apply, , $approve2] = $this->nodesOf($case);
        $this->process($approve2, $this->users['c'], WorkflowAction::SendBack, sendBackTo: $apply);
        $this->reach($apply, $this->users['a']);

        $histories = $this->show($this->users['a'], $case->id)['histories'];

        $this->assertSame([$approve2->name, 'send_back', $apply->name], [$histories[2]['nodeName'], $histories[2]['action'], $histories[2]['sendBackNodeName']]);
    }

    /**
     * 差分は再申請の行だけで、直前の申請・再申請と比べる(2 回目の再申請は 1 回目と比べる)。再申請で参照を付け替え、前の相手を消した後も詳細は開け、表示名が null。
     */
    public function test_each_reapply_is_compared_with_the_previous_submission(): void
    {
        $gone = Record::factory()->create(['record_module_id' => $this->targets->id, 'field_values' => [$this->targetName->id => '消す相手']]);
        $kept = Record::factory()->create(['record_module_id' => $this->targets->id, 'field_values' => [$this->targetName->id => '残る相手']]);
        $case = $this->caseOn($record = $this->record(), $this->users['a']);
        [$apply] = $this->caseNodes($case, 2);
        [$titleId, $projectId, $submissions] = [$this->title->id, $this->project->id, []];

        foreach ([[WorkflowAction::Apply, '版 1', $gone], [WorkflowAction::Reapply, '版 2', $kept], [WorkflowAction::Reapply, '版 3', $kept]] as $index => [$action, $title, $target]) {
            $this->travelTo(CarbonImmutable::parse('2026-10-13 09:00:00')->addMinutes($index));
            $submissions[] = $history = $this->process($apply, $this->users['a'], $action);
            $this->pointAtSubmittedRevision($history, $record, [$titleId => $title], [$projectId => $target]);
        }

        $gone->delete();
        $histories = $this->show($this->users['a'], $case->id)['histories'];

        $this->assertSame([null, null], [$histories[0]['diff'], $histories[0]['diffBaseAt']]);
        $this->assertSame([
            ['code' => 'title', 'before' => '版 1', 'after' => '版 2'],
            ['code' => 'project', 'before' => ['id' => $gone->id, 'code' => null, 'label' => null, 'affiliation' => null], 'after' => ['id' => $kept->id, 'code' => null, 'label' => '残る相手', 'affiliation' => null]],
        ], $histories[1]['diff']['fields'] ?? null);
        $this->assertSame([['code' => 'title', 'before' => '版 2', 'after' => '版 3']], $histories[2]['diff']['fields'] ?? null);
        $this->assertSame([$submissions[0]->created_at?->toIso8601String(), $submissions[1]->created_at?->toIso8601String()], [$histories[1]['diffBaseAt'], $histories[2]['diffBaseAt']]);
    }

    /**
     * レコードの更新が最後の提出の後か(秒の精度。列は timestamp(0) で、同じ秒は変更なし)。
     */
    public function test_updated_after_submission_compares_at_the_second(): void
    {
        $case = $this->standardCase();
        WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->toBase()->update(['created_at' => '2026-10-13 10:00:00+00']);

        foreach (['2026-10-13 10:00:01+00' => true, '2026-10-13 10:00:00+00' => false, '2026-10-13 09:59:59+00' => false] as $updatedAt => $expected) {
            Record::query()->whereKey($case->record_id)->toBase()->update(['updated_at' => $updatedAt]);

            $this->assertSame($expected, $this->show($this->users['a'], $case->id)['content']['updatedAfterSubmission'], $updatedAt);
        }
    }

    /**
     * 関連する案件: 同じレコード → 参照先 → 参照元の順で、同じ案件は先の群だけ。見えない案件・一時保存・自分自身は出ない。群ごとに申請日時の新しい順。
     */
    public function test_related_cases_are_grouped_and_filtered_by_visibility(): void
    {
        $issues = $this->recordModule('wf_issues');
        $about = $this->referenceField($issues, 'about', RecordModule::query()->where('code', 'wf_detail')->sole());
        $back = $this->referenceField($this->targets, 'back', RecordModule::query()->where('code', 'wf_detail')->sole());
        $record = $this->record();
        $target = Record::factory()->create(['record_module_id' => $this->targets->id]);
        $issue = Record::factory()->create(['record_module_id' => $issues->id]);
        $this->reference($record, $this->project, $target);
        $this->reference($target, $back, $record);
        $this->reference($issue, $about, $record);
        $targetFlow = $this->flowOf($this->targets);
        $case = $this->appliedCase($record, $this->users['a']);
        $sameRecord = $this->appliedCase($record, $this->users['a'], completed: true);
        $older = $this->appliedCase($target, $this->users['a'], completed: true, flow: $targetFlow);
        $older->update(['applied_at' => '2026-10-01 09:00:00']);
        $newer = $this->appliedCase($target, $this->users['a'], flow: $targetFlow);
        $this->appliedCase(Record::factory()->create(['record_module_id' => $this->targets->id]), $this->users['a'], flow: $targetFlow);
        $this->appliedCase($issue, $this->users['d'], flow: $this->flowOf($issues));
        $issueCase = $this->appliedCase($issue, $this->users['a'], completed: true, flow: $this->flowOf($issues));
        WorkflowCase::factory()->draft()->create(['workflow_flow_id' => $targetFlow->id, 'user_id' => $this->users['a']->id]);

        $related = $this->show($this->users['a'], $case->id)['related'];

        $this->assertSame([[$sameRecord->id], [$newer->id, $older->id], [$issueCase->id]], [array_column($related['sameRecord'], 'id'), array_column($related['referenced'], 'id'), array_column($related['referencing'], 'id')]);
        $this->assertSame(['pullBack', 'reapply', 'withdraw', 'editDraft', 'deleteDraft', 'bulkApprove', 'confirm'], array_keys($related['referenced'][0]['can']));
    }

    /**
     * @return array<string, mixed>
     */
    private function show(?User $viewer, int $caseId): array
    {
        return app(CaseDetailQuery::class)->show(new AccessContext($viewer?->id, null, null, CarbonImmutable::parse(self::DATE), 'ja'), $caseId);
    }

    /**
     * 処理の間に出た、業務データの入れ物の表(records・record_*)を読み書きする SQL。
     *
     * @return list<string>
     */
    private function recordQueries(callable $action): array
    {
        $log = [];
        DB::listen(function (QueryExecuted $query) use (&$log) {
            $log[] = $query->sql;
        });
        $action();
        $sql = $log;
        $log = [];

        return array_values(array_filter($sql, fn (string $statement) => preg_match('/(from|join|update|into)\s+"record(s|_[a-z_]+)"/i', $statement) === 1));
    }

    /**
     * 申請 A → 承認 1 を B → 承認 2 が承認待ち(C・E)の案件。
     */
    private function standardCase(): WorkflowCase
    {
        $case = $this->caseOn($this->record(), $this->users['a']);
        [$apply, $approve1, $approve2] = $this->caseNodes($case, 3);
        $this->process($apply, $this->users['a'], WorkflowAction::Apply);
        $this->process($approve1, $this->users['b'], WorkflowAction::Approve);
        $this->reach($approve2, $this->users['c'], $this->users['e']);

        return $case;
    }

    private function record(): Record
    {
        return Record::factory()->create(['record_module_id' => RecordModule::query()->where('code', 'wf_detail')->sole()->id, 'field_values' => [$this->title->id => '件名']]);
    }

    private function reference(Record $from, RecordField $field, Record $to): void
    {
        RecordReference::factory()->create(['record_module_id' => $from->record_module_id, 'record_id' => $from->id, 'record_field_id' => $field->id, 'user_id' => null, 'referenced_record_module_id' => $to->record_module_id, 'referenced_record_id' => $to->id]);
    }

    /**
     * 申請のノードを申請者が処理済みにした案件(申請者が処理済の述語で見られる)。
     */
    private function appliedCase(Record $record, User $applicant, bool $completed = false, ?WorkflowFlow $flow = null): WorkflowCase
    {
        $case = $this->caseOn($record, $applicant, $completed, $flow);
        $this->process($this->caseNodes($case, 1)[0], $applicant, WorkflowAction::Apply);

        return $case;
    }

    private function caseOn(Record $record, User $applicant, bool $completed = false, ?WorkflowFlow $flow = null): WorkflowCase
    {
        $factory = $completed ? WorkflowCase::factory()->completed() : WorkflowCase::factory()->active();

        return $factory->create(['workflow_flow_id' => ($flow ?? $this->flow)->id, 'user_id' => $applicant->id, 'record_id' => $record->id]);
    }

    /**
     * 業務モジュールをコンテンツにしたフロー(版 1 つのルート = 申請(A)+ 承認 2)。
     */
    private function flowOf(RecordModule $module): WorkflowFlow
    {
        $flow = WorkflowFlow::factory()->create(['workflow_content_id' => WorkflowContent::factory()->create(['record_module_id' => $module->id])->id]);
        $version = WorkflowFlowVersion::factory()->create(['workflow_flow_id' => $flow->id]);
        WorkflowRouteNode::factory()->count(3)->sequence(['position' => 1, 'authz_subject_group_id' => $this->selection(SelectionConditionKind::User, $this->users['a']->id)], ['position' => 2], ['position' => 3])->create(['workflow_flow_version_id' => $version->id]);

        return $flow;
    }
}
