<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Records\RecordRevisionAction;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordComment;
use App\Models\Records\RecordRevision;
use App\Queries\Records\RecordPageQuery;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRelatedRecords;
use Tests\Support\RegistersRecordHolds;
use Tests\Support\RowAccessRestriction;
use Tests\Support\TransactionalTestCase;

/**
 * 複製(docs の 設計/業務データの入れ物/レコードの共通機能/複製.md の 1 節・2 節・5 節): 登録の画面のクエリ copy に元の値(項目と明細。明細の行は id なし)を
 * copySource で返す。判定の順 = create 403 → 元の read 403(copy の値・レコードの有無に依らない)→ 整数でない copy 404 →
 * その業務モジュールに無い・明細の行・関係しないレコード 404。登録は通常の records.data.store で、新しいレコードの版は 1 つ、添付・コメントは写らず、元の版は増えない。
 *
 * @phpstan-import-type RelatedFixture from BuildsRelatedRecords
 */
final class RecordCopyControllerTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRelatedRecords;
    use RegistersRecordHolds;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
    }

    public function test_the_copy_form_carries_the_values_and_detail_rows_without_ids(): void
    {
        $f = $this->relatedFixture();
        [$estimateId, $projectId] = $this->estimate($f);
        $project = ['id' => $projectId, 'code' => null, 'label' => '案件 A', 'affiliation' => null];

        $this->get("/records/data/estimates/create?copy={$estimateId}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Records/Create')
                ->where('copySource', [
                    'id' => $estimateId,
                    'values' => ['title' => '見積 1', 'customer' => $project, 'billing' => null],
                    'details' => ['lines' => [
                        ['values' => ['product' => $project]],
                        ['values' => ['product' => null]],
                    ]],
                    'rowAccess' => ['restricted' => false, 'viewers' => []],
                ]));
        $this->get('/records/data/estimates/create')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('copySource', null));
    }

    public function test_copy_needs_create(): void
    {
        $f = $this->relatedFixture(['estimates' => [RecordModuleAction::Read]]);
        $estimateId = $this->recordIn($f['estimates'], ['title' => '見積'])->id;

        $this->get("/records/data/estimates/create?copy={$estimateId}")->assertForbidden();
    }

    public function test_copy_needs_the_read_before_looking_for_the_record(): void
    {
        [$estimateId] = $this->estimate($this->relatedFixture(['estimates' => [RecordModuleAction::Create]]));

        foreach ([$estimateId, 999_999_999] as $id) {
            $this->get("/records/data/estimates/create?copy={$id}")->assertForbidden();
        }
    }

    /**
     * @param  list<RecordModuleAction>  $permitted
     */
    #[TestWith([[RecordModuleAction::Read]])]
    #[TestWith([[RecordModuleAction::Create]])]
    public function test_a_malformed_copy_is_forbidden_without_create_or_read(array $permitted): void
    {
        $this->relatedFixture(['estimates' => $permitted]);

        foreach (['abc', '-1'] as $copy) {
            $this->get("/records/data/estimates/create?copy={$copy}")->assertForbidden();
        }

        $this->get('/records/data/estimates/create?copy[]=1')->assertForbidden();
    }

    public function test_records_of_another_module_detail_rows_missing_ids_and_non_numbers_are_not_found(): void
    {
        $f = $this->relatedFixture();
        [$estimateId, $projectId] = $this->estimate($f);
        $rowId = Record::query()->where('parent_record_id', $estimateId)->orderBy('id')->firstOrFail()->id;

        foreach ([$projectId, $rowId, 999_999_999, 'abc', '1.5', '-1'] as $copy) {
            $this->get("/records/data/estimates/create?copy={$copy}")->assertNotFound();
        }

        $this->get('/records/data/estimates/create?copy[]=1')->assertNotFound();
    }

    public function test_an_unrelated_record_is_not_found(): void
    {
        $f = $this->relatedFixture();
        RowAccessRestriction::restrict([$f['estimates']->id]);
        $id = $this->recordIn($f['estimates'], ['title' => '見積'], ['created_by_user_id' => $this->user()->id])->id;

        $this->get("/records/data/estimates/create?copy={$id}")->assertNotFound();
    }

    public function test_an_unrelated_record_opens_with_the_read_all_permission(): void
    {
        $f = $this->relatedFixture(['estimates' => [
            RecordModuleAction::Read, RecordModuleAction::Create, RecordModuleAction::ReadAll,
        ]]);
        RowAccessRestriction::restrict([$f['estimates']->id]);
        $id = $this->recordIn($f['estimates'], ['title' => '見積'], ['created_by_user_id' => $this->user()->id])->id;

        $this->get("/records/data/estimates/create?copy={$id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('copySource.values.title', '見積'));
    }

    public function test_a_held_record_is_copied_into_a_new_record_with_one_revision_and_nothing_else(): void
    {
        $f = $this->relatedFixture();
        [$estimateId, $projectId] = $this->estimate($f);
        $this->registerRecordHolds([$estimateId => 'case:1']);
        RecordComment::factory()->create(['record_module_id' => $f['estimates']->id, 'record_id' => $estimateId]);
        $this->get("/records/data/estimates/create?copy={$estimateId}")->assertOk();

        $this->post('/records/data/estimates', [
            'values' => ['title' => '見積 1', 'customer' => $projectId],
            'details' => ['lines' => [['values' => ['product' => $projectId]], ['values' => []]]],
        ])->assertStatus(303);

        $copied = Record::query()->where('record_module_id', $f['estimates']->id)->latest('id')->firstOrFail();
        $this->assertNotSame($estimateId, $copied->id);
        $this->assertSame(
            [RecordRevisionAction::Create->value],
            RecordRevision::query()->where('record_id', $copied->id)->get()
                ->map(fn (RecordRevision $revision) => $revision->action->value)->all(),
        );
        $this->assertSame(1, RecordRevision::query()->where('record_id', $estimateId)->count());
        $this->assertSame(0, RecordComment::query()->where('record_id', $copied->id)->count());
        $this->assertSame(0, RecordAttachment::query()->where('record_id', $copied->id)->count());
        $this->assertNull(app(RecordPageQuery::class)->hold($copied->id));
        $this->assertSame(2, Record::query()->where('parent_record_id', $copied->id)->count());
    }

    /**
     * 業務処理で見積を作る(版 1)。値 = title「見積 1」・customer = 案件 A、明細 lines の 2 行(1 行目の product = 案件 A、2 行目は空)。
     *
     * @param  RelatedFixture  $f
     * @return array{int, int} 見積と案件の id
     */
    private function estimate(array $f): array
    {
        $project = $this->recordIn($f['projects'], ['title' => '案件 A']);
        $input = new RecordInput(
            ['title' => '見積 1', 'customer' => $project->id],
            ['lines' => [
                new RecordDetailRowInput(values: ['product' => $project->id]),
                new RecordDetailRowInput(values: []),
            ]],
        );

        return [app(CreateRecord::class)->execute($this->contextFor(), 'estimates', $input), $project->id];
    }
}
