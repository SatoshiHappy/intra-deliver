<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\RecordApplicationSteps;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Records\RowAccessScope;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordModule;
use App\Models\Upload\Upload;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AppliesFromRecords;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 既存のデータからの申請の画面の GET(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 3 節・7 節・8 節): 判定の順は
 * 403(自分か代理元のどれもできない。レコードの有無を漏らさない)→ 404 → 持ち主ありは詳細へ戻す + トースト → 画面。クエリ record が
 * 正の整数でなければ新規の申請の画面。フローはテストのフロー(業務モジュール project)。
 */
final class RecordApplicationPageTest extends TransactionalTestCase
{
    use AppliesFromRecords;
    use RegistersProxies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow($this->operationDay()->toDateString().' 03:00:00 UTC');
        $this->createFlow();
    }

    /**
     * 開いた画面の props: モード record、開いたときの版、見出しの表示の項目の値、初期値 = 今の値(明細の行の id つき)、
     * 書くレコード、付いている添付(表示だけ)。一時保存の画面の案件は無い。
     */
    public function test_the_form_carries_the_record_values_and_version(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001, ['name' => '既存の計画']);
        $this->attachFile($record, '計画書.pdf');
        $rowId = $this->detailRowIds($record->id)[0];
        $this->actingAs($u0001);

        $this->get($this->formUrl($record->id))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Workflow/Apply')
            ->where('mode', 'record')
            ->where('flow.code', self::FLOW_CODE)
            ->where('case', null)
            ->where('recordId', $record->id)
            ->where('recordVersion', $record->version())
            ->where('recordTitle', '既存の計画')
            ->where('initial.name', null)
            ->where('initial.values.name', '既存の計画')
            ->where('initial.details.milestones.0.id', $rowId)
            ->where('baseDate', $this->operationDay()->toDateString())
            ->where('attachment.attached', [['name' => '計画書.pdf', 'size' => 2048]])
            ->where('attachment.attachedCount', 1)
            ->where('attachment.drafts', [])
            ->has('module.fields'));
    }

    /**
     * 正の整数でない record(19 桁 = レコードの id の桁の上限を超える・配列を含む)は無いのと同じ(新規の申請の画面)。
     * ほかの画面のモードは版と見出しの値を持たない。
     */
    public function test_a_record_query_that_is_not_a_positive_integer_opens_a_new_application(): void
    {
        $this->actingAs($this->seededUser('u0001'));

        $queries = ['record=abc', 'record=0', 'record=-1', 'record=1.5', 'record=1000000000000000000', 'record[]=1'];

        foreach ($queries as $query) {
            $this->get('/workflow/flows/'.self::FLOW_CODE."/apply?{$query}")->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('mode', 'new')->where('recordId', null)
                    ->where('recordVersion', null)->where('recordTitle', null));
        }
    }

    /**
     * 既存のデータからの申請をできない人には、無いレコード・ほかのモジュールのレコード・申請中のレコードのどれも 403。
     * create だけの人は新規の申請の画面を開ける。
     */
    public function test_a_person_who_cannot_apply_from_a_record_gets_403_whatever_the_record_is(): void
    {
        [$u0001, $u0003] = [$this->seededUser('u0001'), $this->seededUser('u0003')];
        $held = $this->projectRecord($u0001);
        $this->applyFromRecord($u0001, $held->id);
        $other = Record::factory()->create(['record_module_id' => $this->moduleIdOf('project_issue')]);
        $this->allowOnly(self::PROJECT, RecordModuleAction::Update, $u0003);
        $this->actingAs($u0001);

        foreach ([999999999, $other->id, $held->id] as $recordId) {
            $this->get($this->formUrl($recordId))->assertForbidden();
        }

        $this->get('/workflow/flows/'.self::FLOW_CODE.'/apply')->assertOk();
    }

    /**
     * read と update だけの人は自分が作成者のレコードを開ける(create は求めない)。無いレコード・ほかのモジュールのレコード・明細の行は 404。
     */
    public function test_read_and_update_suffice_and_records_out_of_the_module_are_not_found(): void
    {
        $u0003 = $this->seededUser('u0003');
        $record = $this->projectRecord($u0003);
        $rowId = $this->detailRowIds($record->id)[0];
        $other = Record::factory()->create(['record_module_id' => $this->moduleIdOf('project_issue')]);
        $this->allowOnly(self::PROJECT, RecordModuleAction::Create);
        $this->actingAs($u0003);

        $this->get($this->formUrl($record->id))->assertOk();
        $this->get('/workflow/flows/'.self::FLOW_CODE.'/apply')->assertForbidden();

        foreach ([999999999, $other->id, $rowId] as $recordId) {
            $this->get($this->formUrl($recordId))->assertNotFound();
        }
    }

    /**
     * 行の判定を通らないレコードは無いのと同じ(404)。
     */
    public function test_a_record_outside_the_row_access_is_not_found(): void
    {
        $record = $this->projectRecord($this->seededUser('u0001'));
        RecordModule::query()->where('code', self::PROJECT)->update(['row_access_scope' => RowAccessScope::Related]);
        $this->actingAs($this->seededUser('u0003'));

        $this->get($this->formUrl($record->id))->assertNotFound();
    }

    /**
     * ゲスト(未ログイン)は 403(レコードの有無を漏らさない)。
     */
    public function test_a_guest_gets_403(): void
    {
        $record = $this->projectRecord($this->seededUser('u0001'));

        $this->get($this->formUrl($record->id))->assertForbidden();
        $this->get($this->formUrl(999999999))->assertForbidden();
    }

    /**
     * 持ち主(申請中)があれば、レコードの詳細へ戻してトーストを出す。
     */
    public function test_a_held_record_moves_to_the_record_detail_with_a_toast(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001);
        $this->applyFromRecord($u0001, $record->id);
        $this->actingAs($u0001);

        $this->get($this->formUrl($record->id))
            ->assertRedirect("/records/data/project/{$record->id}")
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => RecordApplicationSteps::HELD]);
    }

    /**
     * 自分に権限が無くても、申請の代理元ができれば開け(allowsAny)、定義と値は代理元で引く。create だけの代理元は数えない。
     */
    public function test_a_proxy_opens_the_form_when_the_principal_can_apply_from_the_record(): void
    {
        [$u0001, $u0003, $u0005] = [$this->seededUser('u0001'), $this->seededUser('u0003'), $this->seededUser('u0005')];
        $record = $this->projectRecord($u0001, ['name' => '代理の計画']);
        $this->allowOnly(self::PROJECT, RecordModuleAction::Update, $u0001);
        $this->addProxy($u0001, $u0005, WorkflowProxyKind::Apply);
        $this->addProxy($u0003, $this->seededUser('u0006'), WorkflowProxyKind::Apply);

        $this->actingAs($u0005);
        $this->get($this->formUrl($record->id))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('mode', 'record')->where('initial.values.name', '代理の計画'));

        $this->actingAs($this->seededUser('u0006'));
        $this->get($this->formUrl($record->id))->assertForbidden();
    }

    private function formUrl(int $recordId): string
    {
        return '/workflow/flows/'.self::FLOW_CODE."/apply?record={$recordId}";
    }

    private function attachFile(Record $record, string $name): void
    {
        RecordAttachment::factory()->create([
            'record_module_id' => $record->record_module_id,
            'record_id' => $record->id,
            'upload_id' => Upload::factory()->create(['size' => 2048])->id,
            'name' => $name,
        ]);
    }
}
