<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\RecordApplicationSteps;
use App\Enums\Records\RowAccessScope;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AppliesFromRecords;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * そのものを申請する画面の GET と入口の作成者の確かめ(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 3 節・6 節・8 節):
 * 判定を求める者 = 自分と各代理元のうちレコードの作成者で申請できる人(代理元が作成者なら代理元で開く)。いなければ 404(ほかの人・作成者なし・
 * 行の判定で見えないも同じ)。404 の段は値を読む口より前。完了の後は、入口が返り「申請する」が押せない形で、GET は詳細へ戻し、POST は 422 record。
 */
final class RecordApplicationCreatorPageTest extends TransactionalTestCase
{
    use AppliesFromRecords;
    use RegistersProxies;

    /** レコードの値を読む SQL(行の判定の exists・業務モジュールの引き当ては含まない) */
    private const RECORD_ROW_SQL = '/^select \* from "records" /';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow($this->operationDay()->toDateString().' 03:00:00 UTC');
        $this->createFlow();
    }

    /**
     * 自分が作成者なら自分で、代理元が作成者なら代理元で開く(初期値はそのレコード)。ほかの人の・作成者なしのレコードは 404。
     */
    public function test_the_form_opens_for_the_creator_who_is_the_requester_or_a_principal(): void
    {
        [$proxy, $principal] = [$this->seededUser('u0001'), $this->seededUser('u0009')];
        $this->addProxy($principal, $proxy, WorkflowProxyKind::Apply, $this->flowIdOf(self::FLOW_CODE));
        $own = $this->projectRecord($proxy, ['name' => '自分の計画']);
        $principals = $this->projectRecord($principal, ['name' => '代理元の計画']);
        $others = $this->projectRecord($this->seededUser('u0003'));
        $nobodys = Record::factory()->create(['record_module_id' => $this->moduleIdOf(self::PROJECT)]);
        $this->actingAs($proxy);

        foreach ([[$own, '自分の計画'], [$principals, '代理元の計画']] as [$record, $name]) {
            $this->get($this->formUrl($record->id))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('mode', 'record')->where('recordId', $record->id)->where('initial.values.name', $name));
        }

        $this->get($this->formUrl($others->id))->assertNotFound();
        $this->get($this->formUrl($nobodys->id))->assertNotFound();
    }

    /**
     * 行の判定: 自分は見えなくても、作成者の代理元が見えれば開く。誰も見えないレコードは 404 で、値を読む SQL を出さない(見えるレコードでは出る)。
     */
    public function test_the_row_access_is_judged_before_the_values_are_read(): void
    {
        [$proxy, $principal] = [$this->seededUser('u0001'), $this->seededUser('u0009')];
        $this->addProxy($principal, $proxy, WorkflowProxyKind::Apply, $this->flowIdOf(self::FLOW_CODE));
        $principals = $this->projectRecord($principal);
        $others = $this->projectRecord($this->seededUser('u0003'));
        RecordModule::query()->where('code', self::PROJECT)->update(['row_access_scope' => RowAccessScope::Related]);
        $this->actingAs($proxy);

        $hidden = $this->recordedSql(fn () => $this->get($this->formUrl($others->id))->assertNotFound());
        $visible = $this->recordedSql(fn () => $this->get($this->formUrl($principals->id))->assertOk());

        $this->assertSame([[], true], [
            preg_grep(self::RECORD_ROW_SQL, $hidden),
            preg_grep(self::RECORD_ROW_SQL, $visible) !== [],
        ]);
    }

    /**
     * 完了の後: 詳細の入口は返り、持ち主で「申請する」が押せない形。GET は詳細へ戻してトースト、POST は 422 record で何も書かない。
     */
    public function test_a_completed_record_is_held_for_the_entry_the_form_and_the_application(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001);
        $this->complete($this->applyFromRecord($u0001, $record->id));
        $counts = $this->workflowCounts();
        $this->actingAs($u0001);

        $detail = '/records/data/'.self::PROJECT."/{$record->id}";

        $this->get($detail)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can.apply', true)->has('applyEntries', 2)->whereNot('hold', null));
        $this->get($this->formUrl($record->id))
            ->assertRedirect('/records/data/'.self::PROJECT."/{$record->id}")
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => RecordApplicationSteps::HELD]);
        $this->postJson('/workflow/flows/'.self::FLOW_CODE.'/apply', $this->payload($record))->assertStatus(422)
            ->assertJsonPath('errors', ['record' => [RecordApplicationSteps::HELD]]);
        $this->assertSame($counts, $this->workflowCounts());
    }

    private function formUrl(int $recordId): string
    {
        return '/workflow/flows/'.self::FLOW_CODE."/apply?record={$recordId}";
    }

    /**
     * 申請の画面が送る形(既定の入力。明細の行は id つき)。
     *
     * @return array<string, mixed>
     */
    private function payload(Record $record): array
    {
        $rows = array_map(
            fn (int $id) => ['id' => $id, 'values' => ['name' => '要件の整理', 'due_date' => '2026-11-30']],
            $this->detailRowIds($record->id),
        );

        return [
            'values' => $this->projectInput()->record->values,
            'details' => ['milestones' => $rows],
            'base_date' => $this->operationDay()->toDateString(),
            'record_id' => $record->id,
            'record_version' => Record::query()->findOrFail($record->id)->version(),
        ];
    }
}
