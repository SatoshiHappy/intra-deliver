<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApplicationBaseDate;
use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\RecordApplicationSteps;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Records\RowAccessScope;
use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowFlow;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\AppliesFromRecords;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 既存のデータからの申請の判定の順と、新規の申請と同じ残り(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 4 節・8 節):
 * 0 の確かめは 403 → 404 → 422 record の順で、申請の行を書く前。展開・処理対象者・履歴の行は新規の申請と同じ。代理は代理元で判定し、
 * 版の操作した人は代理先。申請基準日の規則の (4) は既存のデータからの申請をできるかで見る。鍵の順は案件の行 → 業務データの入れ物の鍵。
 */
final class RecordApplicationTest extends TransactionalTestCase
{
    use AppliesFromRecords;
    use CatchesValidationErrors;
    use RegistersProxies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlow();
    }

    /**
     * 権限の無い人には、無いレコード・ほかのモジュールのレコード・申請中のレコードのどれも 403(存在を漏らさない)。
     */
    public function test_a_person_who_cannot_apply_from_a_record_gets_403_whatever_the_record_is(): void
    {
        [$u0001, $u0003] = [$this->seededUser('u0001'), $this->seededUser('u0003')];
        $held = $this->projectRecord($u0001);
        $this->applyFromRecord($u0001, $held->id);
        $other = Record::factory()->create(['record_module_id' => $this->moduleIdOf('project_issue')]);
        $this->allowOnly(self::PROJECT, RecordModuleAction::Update, $u0003);
        $counts = $this->workflowCounts();

        foreach ([$this->missingRecordId(), $other->id, $held->id] as $recordId) {
            $this->assertSame('403', $this->outcome(fn () => $this->applyFromRecord($u0001, $recordId, version: '')));
        }

        $this->assertSame($counts, $this->workflowCounts());
    }

    /**
     * 権限があれば、申請中は 422 record、無いレコード・ほかのモジュールのレコード・明細の行は 404。どれも何も書かない。
     */
    public function test_a_person_who_can_apply_gets_422_for_a_held_record_and_404_for_records_out_of_the_module(): void
    {
        $u0001 = $this->seededUser('u0001');
        $held = $this->projectRecord($u0001);
        $this->applyFromRecord($u0001, $held->id);
        $detailRowId = $this->detailRowIds($this->projectRecord($u0001)->id)[0];
        $other = Record::factory()->create(['record_module_id' => $this->moduleIdOf('project_issue')]);
        $counts = $this->workflowCounts();

        $errors = $this->validationErrors(fn () => $this->applyFromRecord($u0001, $held->id));
        $notFound = array_map(
            fn (int $recordId) => $this->outcome(fn () => $this->applyFromRecord($u0001, $recordId, version: '')),
            [$this->missingRecordId(), $other->id, $detailRowId],
        );

        $this->assertSame(['record' => RecordApplicationSteps::HELD], $errors);
        $this->assertSame(['404', '404', '404'], $notFound);
        $this->assertSame($counts, $this->workflowCounts());
    }

    /**
     * create だけの人は既存のデータから申請できず(403)、read と update だけの人は自分が作成者のレコードを申請できる(新規の申請はその逆 = 403)。
     */
    public function test_from_a_record_needs_read_and_update_but_not_create(): void
    {
        [$u0001, $u0003] = [$this->seededUser('u0001'), $this->seededUser('u0003')];
        $record = $this->projectRecord($u0003);
        $this->allowOnly(self::PROJECT, RecordModuleAction::Create, $u0001);
        $this->allowOnly(self::PROJECT, RecordModuleAction::Read, $u0003);
        $this->allowOnly(self::PROJECT, RecordModuleAction::Update, $u0003);

        $this->assertSame('403', $this->outcome(fn () => $this->applyFromRecord($u0001, $record->id)));
        $this->assertSame('403', $this->outcome(fn () => $this->applyAs($u0003)));
        $this->assertSame($u0003->id, $this->applyFromRecord($u0003, $record->id)->user_id);
        $this->assertSame($u0001->id, $this->applyAs($u0001)->user_id);
    }

    /**
     * 業務モジュールの read と update があっても、行の判定を通らないレコードは無いのと同じ(404)で、何も書かない
     * (更新の口は持ち主の鍵を渡すと行の判定をしないので、申請の側で止める)。作成者本人は申請できる。
     */
    public function test_a_record_outside_the_row_access_of_the_applicant_is_not_found(): void
    {
        [$u0001, $u0003] = [$this->seededUser('u0001'), $this->seededUser('u0003')];
        $record = $this->projectRecord($u0001);
        $this->allowOnly(self::PROJECT, RecordModuleAction::Read, $u0003);
        $this->allowOnly(self::PROJECT, RecordModuleAction::Update, $u0003);
        RecordModule::query()->where('code', self::PROJECT)->update(['row_access_scope' => RowAccessScope::Related]);
        $counts = $this->workflowCounts();

        $this->assertSame('404', $this->outcome(fn () => $this->applyFromRecord($u0003, $record->id)));
        $this->assertSame($counts, $this->workflowCounts());

        $this->allowOnly(self::PROJECT, RecordModuleAction::Read, $u0001);
        $this->allowOnly(self::PROJECT, RecordModuleAction::Update, $u0001);
        $this->assertSame($u0001->id, $this->applyFromRecord($u0001, $record->id)->user_id);
    }

    /**
     * 展開・処理対象者・履歴の行・案件の値は新規の申請と同じ。案件名の既定は更新の後の値で決まり、案件はレコードを指す。
     */
    public function test_the_rest_is_the_same_as_a_new_application(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001, ['name' => '前の名前']);
        $new = $this->applyAs($u0001);

        $case = $this->applyFromRecord($u0001, $record->id, $this->projectInput(['name' => '直した名前'], comment: 'コメント'));

        $this->assertSame($this->nodeStates($new), $this->nodeStates($case));
        $columns = fn (WorkflowCase $row) => [
            $row->state, $row->user_id, $row->department_id, $row->revision, $row->workflow_flow_version_id,
            $row->base_date?->toDateString(), $row->is_attachment_enabled,
        ];
        $this->assertSame($columns($new), $columns($case));
        $this->assertSame([$record->id, 'プロジェクト計画の承認 直した名前'], [$case->record_id, $case->name]);
        $this->assertNotNull($case->case_number);
        $this->assertSame([['apply', $u0001->id, null, 'コメント']], $this->histories($case));
        $revision = RecordRevision::query()->findOrFail($this->historyRevisionIds($case)[0]);
        $this->assertSame([$u0001->id, $u0001->id], [$this->updatedBy($record), $revision->user_id]);
        $this->assertNodeInvariant($case);
    }

    /**
     * 代理(自分は read・update を持たず、代理元が持つ): 代理元を選ぶと申請でき、自分としては 403。業務データの入れ物の口には代理元の判定の入力を渡し、
     * 版の操作した人は代理先・処理者は代理元。
     */
    public function test_a_proxy_applies_from_a_record_as_the_principal(): void
    {
        [$principal, $proxy] = [$this->seededUser('u0009'), $this->seededUser('u0001')];
        $this->addProxy($principal, $proxy, WorkflowProxyKind::Apply, $this->flowIdOf(self::FLOW_CODE));
        $record = $this->projectRecord($principal);
        $this->allowOnly(self::PROJECT, RecordModuleAction::Read, $principal);
        $this->allowOnly(self::PROJECT, RecordModuleAction::Update, $principal);

        $asSelf = $this->outcome(fn () => $this->applyFromRecord($proxy, $record->id));
        $case = $this->applyFromRecord($proxy, $record->id, principal: $principal);

        $revision = RecordRevision::query()->findOrFail($this->historyRevisionIds($case)[0]);
        $this->assertSame('403', $asSelf);
        $this->assertSame([$principal->id, $principal->id], [$case->user_id, $this->updatedBy($record)]);
        $this->assertSame([$proxy->id, $principal->id], [$revision->user_id, $revision->principal_user_id]);
        $this->assertSame([['apply', $principal->id, $proxy->id, null]], $this->histories($case));
    }

    /**
     * 申請基準日の規則の (4): 既存のデータからの申請は、申請者が申請基準日に read・update とともに申請できるかで見る(create を見ない)。
     * 代理元が申請基準日に有効でなければ 422 base_date(今日の判定 = 0 は通る)。
     */
    public function test_the_base_date_rule_judges_the_applicant_from_a_record_on_the_base_date(): void
    {
        $u0001 = $this->seededUser('u0001');
        $flowId = $this->flowIdOf(self::FLOW_CODE);
        $context = $this->contextOf($u0001);
        $day = $context->date->toDateString();
        $rules = app(ApplicationBaseDate::class);
        $this->allowOnly(self::PROJECT, RecordModuleAction::Create);

        $this->assertSame($day, $rules->resolveFromRecord($context, $flowId, $day)[0]->toDateString());
        $newErrors = $this->validationErrors(fn () => $rules->resolve($context, $flowId, $day));
        $this->assertSame(['base_date'], array_keys($newErrors));

        [$principal, $proxy] = [$this->seededUser('u0015'), $this->seededUser('u0002')];
        $this->addProxy($principal, $proxy, WorkflowProxyKind::Apply, $flowId);
        $record = Record::factory()->create([
            'record_module_id' => $this->moduleIdOf(self::PROJECT), 'created_by_user_id' => $principal->id,
        ]);
        $counts = $this->workflowCounts();
        $later = $this->contextOf($proxy, date: '2026-11-02');
        [$input, $version] = [$this->projectInput(), $record->version()];
        $errors = $this->validationErrors(fn () => app(ApplyCase::class)
            ->executeFromRecord($later, self::FLOW_CODE, $record->id, $version, $input, '2026-10-20', $principal->id));

        $this->assertSame(['base_date'], array_keys($errors));
        $this->assertSame($counts, $this->workflowCounts());
    }

    /**
     * 鍵の順: フローの行の FOR SHARE → 案件の行の挿入 → 業務モジュールの共有の勧告ロック → レコードの行の FOR NO KEY UPDATE
     * (再申請と同じ向き = 案件の行 → 業務データの入れ物の鍵)。
     */
    public function test_the_case_row_is_written_before_the_records_locks(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001);
        $flowId = WorkflowFlow::query()->where('code', self::FLOW_CODE)->sole()->id;
        $log = [];
        DB::listen(function (QueryExecuted $query) use (&$log) {
            $log[] = [$query->sql, $query->bindings];
        });

        $this->applyFromRecord($u0001, $record->id);

        $at = fn (callable $matches) => array_key_first(array_filter($log, fn (array $query) => $matches(...$query)));
        $flowAt = $at(fn (string $sql) => str_contains($sql, 'from "workflow_flows"')
            && str_ends_with($sql, 'for share'));
        $caseAt = $at(fn (string $sql) => str_starts_with($sql, 'insert into "workflow_cases"'));
        $moduleAt = $at(fn (string $sql) => str_contains($sql, 'pg_advisory_xact_lock_shared('));
        $recordAt = $at(fn (string $sql, array $bindings) => str_contains($sql, 'from "records"')
            && str_ends_with($sql, 'for no key update') && in_array($record->id, $bindings, true));

        $this->assertIsInt($flowAt);
        $this->assertIsInt($caseAt);
        $this->assertIsInt($moduleAt);
        $this->assertIsInt($recordAt);
        $this->assertTrue($flowAt < $caseAt && $caseAt < $moduleAt && $moduleAt < $recordAt);
        $this->assertNotSame([], array_intersect([$flowId, self::FLOW_CODE], $log[$flowAt][1]));
        $case = WorkflowCase::query()->where('record_id', $record->id)->sole();
        $this->assertSame(WorkflowCaseState::Active, $case->state);
    }

    private function missingRecordId(): int
    {
        return (int) Record::query()->max('id') + 1000;
    }

    private function updatedBy(Record $record): ?int
    {
        return Record::query()->findOrFail($record->id)->updated_by_user_id;
    }

    /**
     * @return list<array{string, int, int|null, string|null}> 履歴の行の [種別, 処理者, 実行者, コメント](id の順)
     */
    private function histories(WorkflowCase $case): array
    {
        $rows = WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->orderBy('id')->get();

        return array_values($rows->map(
            fn (WorkflowCaseHistory $row) => [$row->action->value, $row->user_id, $row->executor_user_id,
                $row->comment],
        )->all());
    }
}
