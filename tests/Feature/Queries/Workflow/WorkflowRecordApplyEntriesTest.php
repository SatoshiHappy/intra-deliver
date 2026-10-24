<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Enums\Records\RowAccessScope;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordApplyEntry;
use App\Queries\Records\Api\RecordViewAccess;
use App\Queries\Workflow\RecordApplyFlow;
use App\Queries\Workflow\RecordApplyFlows;
use App\Queries\Workflow\WorkflowRecordApplyEntries;
use Tests\Support\AppliesFromRecords;
use Tests\Support\BuildsTargetFlows;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * レコードから申請できる入口のワークフローの実装(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 9.2・11 節):
 * 申請書の業務モジュールのレコードは作成者で(自分・代理元 = クエリ applicant・ほかの人と作成者なしは空)、申請対象の参照先の業務レコードは
 * 申請でき閲覧できる人で(自分 = applicant なし・代理元だけ = applicant・誰もいない = 空)、どちらでもないレコードは空。持ち主の有無を見ない。
 * 申請書の業務モジュール = テストのフローの project(初期データのフローも同じ業務モジュール)。申請対象のフローはテストで作る。
 */
final class WorkflowRecordApplyEntriesTest extends TransactionalTestCase
{
    use AppliesFromRecords;
    use BuildsTargetFlows;
    use RegistersProxies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlow();
    }

    /**
     * 作成者が自分: 自分が申請できるフローごとに、URL = flow とクエリ record。作成者が代理元: 代理元が申請でき、自分がその代理先のフローだけ、
     * クエリ applicant = 代理元も。
     */
    public function test_a_form_record_created_by_the_requester_or_a_principal_has_the_entries(): void
    {
        [$u0001, $principal] = [$this->seededUser('u0001'), $this->seededUser('u0009')];
        $own = $this->projectRecord($u0001);
        $principals = $this->projectRecord($principal);
        $this->addProxy($principal, $u0001, WorkflowProxyKind::Apply, $this->flowIdOf(self::FLOW_CODE));
        $listed = app(RecordApplyFlows::class)
            ->flowsFor($this->requesterOf($u0001), self::PROJECT, $this->operationDay());
        $urlOf = fn (RecordApplyFlow $flow) => route(
            'workflow.applications.create', ['flow' => $flow->code, 'record' => $own->id],
        );

        $expected = array_map(fn (RecordApplyFlow $flow) => [$flow->name, $flow->description, $urlOf($flow)], $listed);
        $this->assertContains(self::FLOW_CODE, array_map(fn (RecordApplyFlow $flow) => $flow->code, $listed));
        $this->assertSame($expected, $this->described($u0001, $own->id));
        $url = url('/workflow/flows/'.self::FLOW_CODE.'/apply');
        $this->assertContains("{$url}?record={$own->id}", $this->urls($u0001, $own->id));
        $this->assertSame(
            ["{$url}?record={$principals->id}&applicant={$principal->id}"],
            $this->urls($u0001, $principals->id),
        );
    }

    /**
     * 作成者がほかの人・作成者なし・作成者が申請できない(自分でも代理元でも)レコードは空。
     */
    public function test_a_form_record_of_another_person_of_nobody_or_of_a_creator_who_cannot_apply_has_no_entry(): void
    {
        [$u0001, $u0003] = [$this->seededUser('u0001'), $this->seededUser('u0003')];
        $principal = $this->seededUser('u0009');
        $others = $this->projectRecord($u0003);
        $nobodys = Record::factory()->create(['record_module_id' => $this->moduleIdOf(self::PROJECT)]);
        $own = $this->projectRecord($u0001);
        $principals = $this->projectRecord($principal);
        $this->addProxy($principal, $u0001, WorkflowProxyKind::Apply);

        $this->assertSame([], $this->entries($u0001, $others->id));
        $this->assertSame([], $this->entries($u0001, $nobodys->id));
        $this->assertNotSame([], $this->entries($u0001, $principals->id));

        $this->allowOnly(self::PROJECT, RecordModuleAction::Update, $u0003);
        $this->assertSame([], $this->entries($u0001, $own->id));
        $this->assertSame([], $this->entries($u0001, $principals->id));
        $this->assertSame([], $this->entries($u0003, $own->id));
        $this->assertNotSame([], $this->entries($u0003, $others->id));
    }

    /**
     * 行の権限のある業務モジュールでも、作成者はいつも行の判定を通るので、作成者が代理元なら自分が関係者でなくても入口がクエリ applicant つきで返る。
     * 作成者でも代理元でもない人のレコードは返らない。
     */
    public function test_a_record_of_a_principal_in_a_row_restricted_module_has_the_entry_with_applicant(): void
    {
        [$u0001, $principal] = [$this->seededUser('u0001'), $this->seededUser('u0009')];
        RecordModule::query()->whereKey($this->moduleIdOf(self::PROJECT))
            ->update(['row_access_scope' => RowAccessScope::Related]);
        $principals = $this->projectRecord($principal);
        $this->addProxy($principal, $u0001, WorkflowProxyKind::Apply, $this->flowIdOf(self::FLOW_CODE));
        $url = url('/workflow/flows/'.self::FLOW_CODE.'/apply');

        $this->assertFalse(app(RecordViewAccess::class)
            ->canView($this->requesterOf($u0001), self::PROJECT, $principals->id, $this->operationDay()));
        $this->assertSame(
            ["{$url}?record={$principals->id}&applicant={$principal->id}"],
            $this->urls($u0001, $principals->id),
        );
    }

    /**
     * 案件のあるレコード(未完了・完了)も、案件の無いレコードと同じ入口を返す(押せない形は画面が持ち主で決める)。
     */
    public function test_a_held_or_completed_record_keeps_the_entries(): void
    {
        $u0001 = $this->seededUser('u0001');
        [$fresh, $active, $completed] = array_map(fn () => $this->projectRecord($u0001), [1, 2, 3]);
        $this->applyFromRecord($u0001, $active->id);
        $this->complete($this->applyFromRecord($u0001, $completed->id));
        $names = fn (int $recordId) => array_column($this->described($u0001, $recordId), 0);

        $this->assertNotSame([], $names($fresh->id));
        $this->assertSame($names($fresh->id), $names($active->id));
        $this->assertSame($names($fresh->id), $names($completed->id));
    }

    /**
     * 無いレコード・明細の行・申請書でも申請対象の参照先でもない業務モジュールのレコードは空。
     */
    public function test_a_missing_record_a_detail_row_and_a_record_of_neither_module_have_no_entry(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001);
        $plain = $this->recordModule('m_plain', RecordModuleAction::cases());
        $plainRecord = Record::factory()->create([
            'record_module_id' => $plain->id, 'created_by_user_id' => $u0001->id,
        ]);

        $this->assertSame([], $this->entries($u0001, (int) Record::query()->max('id') + 1000));
        $this->assertSame([], $this->entries($u0001, $this->detailRowIds($record->id)[0]));
        $this->assertSame([], $this->entries($u0001, $plainRecord->id));
    }

    /**
     * 申請対象の参照先の業務レコード: 自分が申請でき閲覧できれば URL = flow とクエリ target。代理元だけが閲覧できればクエリ applicant = 代理元。
     * 誰も閲覧できなければ空。申請書の入口は出ない(作成者で決まる入口は申請書の業務モジュールだけ)。
     */
    public function test_a_target_record_has_the_entry_for_the_first_applicant_who_can_view_it(): void
    {
        [$u0001, $principal] = [$this->seededUser('u0001'), $this->seededUser('u0009')];
        $u0003 = $this->seededUser('u0003');
        $hub = $this->recordModule('m_hub', [RecordModuleAction::Read, RecordModuleAction::Update]);
        RecordModule::query()->whereKey($hub->id)->update(['row_access_scope' => RowAccessScope::Related]);
        $flow = $this->targetFlow('wf_target', $hub, [RecordModuleAction::Create], [$u0001, $principal]);
        $this->addProxy($principal, $u0001, WorkflowProxyKind::Apply, $flow->id);
        $record = fn (User $creator) => Record::factory()->create([
            'record_module_id' => $hub->id, 'created_by_user_id' => $creator->id,
        ])->id;
        [$own, $principals, $others] = [$record($u0001), $record($principal), $record($u0003)];
        $url = url('/workflow/flows/wf_target/apply');

        $this->assertSame([['wf_target の名前', null, "{$url}?target={$own}"]], $this->described($u0001, $own));
        $this->assertSame(["{$url}?target={$principals}&applicant={$principal->id}"], $this->urls($u0001, $principals));
        $this->assertSame([], $this->entries($u0001, $others));
        $this->assertSame([], $this->entries($u0003, $others));
    }

    /**
     * @return list<RecordApplyEntry>
     */
    private function entries(User $user, int $recordId): array
    {
        return app(WorkflowRecordApplyEntries::class)
            ->entriesFor($this->requesterOf($user), $recordId, $this->operationDay());
    }

    /**
     * @return list<array{string, string|null, string}> 入口の [名前, 説明, URL]
     */
    private function described(User $user, int $recordId): array
    {
        return array_map(
            fn (RecordApplyEntry $entry) => [$entry->name, $entry->description, $entry->url],
            $this->entries($user, $recordId),
        );
    }

    /**
     * @return list<string>
     */
    private function urls(User $user, int $recordId): array
    {
        return array_map(fn (RecordApplyEntry $entry) => $entry->url, $this->entries($user, $recordId));
    }

    private function requesterOf(User $user): Requester
    {
        return new Requester($user->id, null);
    }
}
