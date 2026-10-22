<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordReference;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BuildsRelatedRecords;
use Tests\Support\TransactionalTestCase;

/**
 * レコードの詳細の関連レコードの props と、ダイアログの新規の登録(docs の 設計/業務データの入れ物/レコードの共通機能/関連レコード.md の 3 節・4 節・7 節・
 * 9 節の「入口」「新規」、詳細の画面/README.md の 3 節・6.1 節): relatedRecords は最初の応答に入り、relatedRecordRows はクエリ tab が related のときだけ、
 * relatedRecordForm は部分の再読み込みでだけ。relatedPage は部分の再読み込みでだけ読む。related つきの登録は元の画面(Referer)へ 303 で戻り、
 * 入口の項目の値が related.record と違えば 422。can.copy = 業務モジュールの create。
 *
 * @phpstan-import-type RelatedFixture from BuildsRelatedRecords
 */
final class RecordRelatedControllerTest extends TransactionalTestCase
{
    use BuildsRelatedRecords;

    /** @var RelatedFixture */
    private array $f;

    private Record $project;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
        $this->f = $this->relatedFixture();
        $this->project = $this->recordIn($this->f['projects'], ['title' => '案件 A']);
        $this->refer($this->recordIn($this->f['estimates'], ['title' => '見積 1']), $this->f['customer'], $this->project);
    }

    public function test_the_first_response_carries_the_entries_and_copy_but_no_rows_or_form(): void
    {
        $this->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Records/Show')
            ->where('relatedRecords', [
                'entries' => [
                    ['key' => 'estimates.customer', 'name' => 'estimates(customer)', 'count' => 1, 'canCreate' => true],
                    ['key' => 'estimates.billing', 'name' => 'estimates(billing)', 'count' => 0, 'canCreate' => true],
                    [
                        'key' => 'estimates.lines.product', 'name' => 'estimates(linesのproduct)', 'count' => 0,
                        'canCreate' => false,
                    ],
                    ['key' => 'issues.project', 'name' => 'issues', 'count' => 0, 'canCreate' => true],
                ],
            ])
            ->where('relatedRecordRows', null)
            ->missing('relatedRecordForm')
            ->where('can.copy', true)
        );
    }

    public function test_the_related_tab_carries_the_rows_of_the_selected_entry_or_else_the_first(): void
    {
        $this->get($this->url(['tab' => 'related']))->assertInertia(fn (Assert $page) => $page
            ->where('relatedRecordRows.entry', 'estimates.customer')
            ->where('relatedRecordRows.rows.0.values', ['title' => '見積 1']));
        $this->get($this->url(['tab' => 'related', 'related' => 'issues.project']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('relatedRecordRows.entry', 'issues.project')
                ->where('relatedRecordRows.total', 0));
        $this->get($this->url(['tab' => 'related', 'related' => 'nothing.here']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('relatedRecordRows.entry', 'estimates.customer'));
        $this->get($this->url(['tab' => 'fields', 'related' => 'issues.project']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('relatedRecordRows', null));
    }

    public function test_a_partial_reload_of_the_rows_reads_the_page_and_a_full_response_ignores_it(): void
    {
        foreach (range(1, 20) as $number) {
            $estimate = $this->recordIn($this->f['estimates'], ['title' => "見積 {$number}"]);
            $this->refer($estimate, $this->f['customer'], $this->project);
        }

        $page = $this->partial(['tab' => 'related', 'relatedPage' => '2'], 'relatedRecordRows')
            ->assertOk()->viewData('page');

        $this->assertSame([2, 2, 21], [
            $page['props']['relatedRecordRows']['currentPage'],
            $page['props']['relatedRecordRows']['lastPage'],
            $page['props']['relatedRecordRows']['total'],
        ]);
        $this->assertCount(1, $page['props']['relatedRecordRows']['rows']);
        foreach (['relatedRecords', 'record', 'panel', 'can'] as $prop) {
            $this->assertArrayNotHasKey($prop, $page['props']);
        }

        $this->get($this->url(['tab' => 'related', 'relatedPage' => '2']))
            ->assertInertia(fn (Assert $full) => $full->where('relatedRecordRows.currentPage', 1));
    }

    public function test_the_form_comes_with_a_partial_reload_of_it_for_an_entry_that_may_create(): void
    {
        $page = $this->partial(['tab' => 'related', 'related' => 'estimates.customer'], 'relatedRecordForm')
            ->assertOk()->viewData('page');
        $form = $page['props']['relatedRecordForm'];

        $this->assertSame(
            ['estimates.customer', 'customer', '案件 A'],
            [$form['entry'], $form['field'], $form['value']['label']],
        );
        $this->assertSame('estimates', $form['module']['code']);
        $this->assertNull($this->partial(['related' => 'estimates.lines.product'], 'relatedRecordForm')
            ->viewData('page')['props']['relatedRecordForm']);
        $this->assertNull($this->partial([], 'relatedRecordForm')->viewData('page')['props']['relatedRecordForm']);
    }

    public function test_a_record_without_visible_entries_has_no_related_props_and_copy_needs_create(): void
    {
        $lonely = $this->recordModule('lonely', [RecordModuleAction::Read]);
        $record = $this->recordIn($lonely);

        $this->get("/records/data/lonely/{$record->id}?tab=related")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('relatedRecords', null)
                ->where('relatedRecordRows', null)
                ->where('can.copy', false));
    }

    public function test_a_related_store_returns_to_the_referer_without_a_toast(): void
    {
        $back = 'http://localhost'.$this->url(['tab' => 'related', 'related' => 'estimates.customer']);

        $this->post('/records/data/estimates', $this->relatedInput($this->project->id), ['Referer' => $back])
            ->assertStatus(303)
            ->assertRedirect($back)
            ->assertSessionHasNoErrors()
            ->assertInertiaFlashMissing('toast');

        $this->assertSame(2, RecordReference::query()->where('record_field_id', $this->f['customer']->id)
            ->where('referenced_record_id', $this->project->id)->count());
    }

    public function test_a_store_without_related_still_moves_to_the_new_detail_with_a_toast(): void
    {
        $response = $this->post(
            '/records/data/estimates',
            ['values' => ['title' => '見積 2']],
            ['Referer' => 'http://localhost/x'],
        );

        $created = Record::query()->where('record_module_id', $this->f['estimates']->id)->latest('id')->firstOrFail();
        $response->assertStatus(303)->assertRedirect("/records/data/estimates/{$created->id}")
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '登録しました']);
    }

    public function test_an_entry_value_other_than_the_related_record_is_refused_with_its_key(): void
    {
        $other = $this->recordIn($this->f['projects']);
        $before = Record::query()->count();
        $input = $this->relatedInput($other->id);
        $input['related']['record'] = $this->project->id;

        $this->from($this->url())->post('/records/data/estimates', $input)
            ->assertRedirect($this->url())
            ->assertSessionHasErrors(['values.customer' => '関連レコードとして登録するため、変更できません']);
        $this->from($this->url())->post('/records/data/estimates', [...$input, 'values' => ['title' => '見積']])
            ->assertSessionHasErrors(['values.customer' => '関連レコードとして登録するため、変更できません']);
        foreach ([true, '+'.$this->project->id, (string) $this->project->id] as $value) {
            $this->from($this->url())->post('/records/data/estimates', [
                ...$input,
                'values' => ['title' => '見積', 'customer' => $value],
                'related' => ['field' => 'customer', 'record' => $this->project->id],
            ])->assertSessionHasErrors(['values.customer' => '関連レコードとして登録するため、変更できません']);
        }

        $this->assertSame($before, Record::query()->count());
    }

    public function test_a_malformed_related_is_refused_with_its_key(): void
    {
        $before = Record::query()->count();

        foreach ([
            ['related', 'x'],
            ['related', ['field' => 'customer']],
            ['related', ['field' => 'customer', 'record' => $this->project->id, 'extra' => 1]],
            ['related.record', ['field' => 'customer', 'record' => 'abc']],
            ['related.field', ['field' => 'Customer!', 'record' => $this->project->id]],
        ] as [$key, $related]) {
            $this->from($this->url())->post('/records/data/estimates', [
                ...$this->relatedInput($this->project->id),
                'related' => $related,
            ])->assertSessionHasErrors([$key => '関連レコードの指定が正しくありません']);
        }

        $this->assertSame($before, Record::query()->count());
    }

    /**
     * 「新規」のダイアログが送る入力(入口の項目 customer を $projectId に固定)。
     *
     * @return array{values: array<string, mixed>, related: array{field: string, record: int}}
     */
    private function relatedInput(int $projectId): array
    {
        return [
            'values' => ['title' => '見積 2', 'customer' => $projectId],
            'related' => ['field' => 'customer', 'record' => $this->project->id],
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    private function url(array $query = []): string
    {
        $url = "/records/data/projects/{$this->project->id}";

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    /**
     * 部分の再読み込み(Inertia の画面が送る見出し)。
     *
     * @param  array<string, string>  $query
     * @return TestResponse<Response>
     */
    private function partial(array $query, string $only): TestResponse
    {
        return $this->get($this->url($query), [
            Header::PARTIAL_COMPONENT => 'Records/Show',
            Header::PARTIAL_ONLY => $only,
        ]);
    }
}
