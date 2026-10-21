<?php

namespace Tests\Feature\Queries\Records;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Records\Api\RecordShowPanelQuery;
use App\Queries\Records\Api\RecordShowSections;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BuildsRecordModules;
use Tests\Support\FixedRecordShowSection;
use Tests\Support\RegistersRecordShowSections;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 詳細の節の口の呼び方・失敗・型・全実装(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/節の口.md の 2 節・5 節):
 * 登録の順に Records の欄の後ろへ並べ、閲覧できない見る人では呼ばない。帯は presence() だけ、欄を開いた時はその節の section() だけを呼ぶ。
 * 中身の形の違いは失敗の形にして報告し、名札の誤りと実装の例外は投げる。テストの節をタグで登録し、業務モジュールとレコードはテストで作る。
 */
final class RecordShowSectionsTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use RegistersRecordShowSections;
    use UsesSeededRecords;

    private Record $record;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $module = $this->recordModule('show_check');
        $this->record = Record::factory()->create(['record_module_id' => $module->id]);
    }

    public function test_sections_follow_the_records_panes_in_the_order_of_registration_without_their_contents(): void
    {
        $first = $this->registerRecordShowSection($this->listSection('test-first', '外部参照の一覧', '外部参照', 3));
        $hidden = $this->registerRecordShowSection(new FixedRecordShowSection('test-hidden', '隠す', '隠す', null));
        $second = $this->registerRecordShowSection($this->listSection('test-second', 'メモ', 'メモ', null));

        $panel = $this->panels()->panel($this->contextFor(), 'show_check', $this->record->id);

        $this->assertSame(
            [
                ['attachments', '添付ファイル', '添付', 0, 'attachments'],
                ['comments', 'コメント', 'コメント', 0, 'comments'],
                ['revisions', '更新履歴', '更新履歴', null, 'revisions'],
                ['test-first', '外部参照の一覧', '外部参照', 3, 'section'],
                ['test-second', 'メモ', 'メモ', null, 'section'],
            ],
            array_map(fn (array $item) => array_values($item), $panel['panel']['items'] ?? []),
        );
        $this->assertSame([
            ['code' => 'test-first', 'heading' => '外部参照の一覧', 'shortHeading' => '外部参照', 'count' => 3],
            ['code' => 'test-second', 'heading' => 'メモ', 'shortHeading' => 'メモ', 'count' => null],
        ], $panel['sections'] ?? null);
        $this->assertSame([1, 1, 1], [$first->presenceCalls, $hidden->presenceCalls, $second->presenceCalls]);
        $this->assertSame([0, 0, 0], [$first->sectionCalls, $hidden->sectionCalls, $second->sectionCalls]);
    }

    public function test_opening_a_section_calls_only_its_section_and_no_presence(): void
    {
        $first = $this->registerRecordShowSection($this->listSection('test-first', '一覧', '一覧'));
        $second = $this->registerRecordShowSection($this->listSection('test-second', 'メモ', 'メモ'));

        $content = $this->content($this->record->id, 'test-second');

        $list = ['kind' => 'list', 'count' => 2, 'items' => [], 'emptyText' => 'ありません', 'more' => null];
        $this->assertSame(['kind' => 'section', 'code' => 'test-second', 'content' => $list], $content);
        $this->assertSame([0, 0], [$first->presenceCalls, $second->presenceCalls]);
        $this->assertSame([0, 1], [$first->sectionCalls, $second->sectionCalls]);
    }

    public function test_the_records_panes_and_an_unknown_key_call_no_section(): void
    {
        $section = $this->registerRecordShowSection($this->listSection('test-first', '一覧', '一覧'));
        $panels = $this->panels();

        $panels->content($this->contextFor(), 'show_check', $this->record->id, 'attachments', null);
        $panels->content($this->contextFor(), 'show_check', $this->record->id, 'revisions', 1);
        $unknown = $panels->content($this->contextFor(), 'show_check', $this->record->id, 'test-unknown', null);

        $this->assertNull($unknown);
        $this->assertSame([0, 0], [$section->presenceCalls, $section->sectionCalls]);
    }

    public function test_a_viewer_who_cannot_view_the_record_calls_no_section(): void
    {
        $section = $this->registerRecordShowSection($this->listSection('test-first', '一覧', '一覧'));
        $module = $this->recordModule('show_denied', [RecordModuleAction::Update]);
        $record = Record::factory()->create(['record_module_id' => $module->id]);

        $this->assertNull($this->panels()->panel($this->contextFor(), 'show_denied', $record->id));
        $this->assertNull($this->content($record->id, 'test-first', 'show_denied'));
        $this->assertSame([0, 0], [$section->presenceCalls, $section->sectionCalls]);
    }

    public function test_a_failed_section_and_a_null_section_are_returned_as_they_are(): void
    {
        $this->registerRecordShowSection(
            new FixedRecordShowSection('test-failed', '失敗', '失敗', section: ['kind' => 'failed']),
        );
        $this->registerRecordShowSection(new FixedRecordShowSection('test-empty', '空', '空'));

        $failed = $this->content($this->record->id, 'test-failed');
        $empty = $this->content($this->record->id, 'test-empty');

        $this->assertSame(['kind' => 'section', 'code' => 'test-failed', 'content' => ['kind' => 'failed']], $failed);
        $this->assertSame(['kind' => 'section', 'code' => 'test-empty', 'content' => null], $empty);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function malformedSections(): array
    {
        $list = ['kind' => 'list', 'count' => 1, 'items' => [], 'emptyText' => 'ありません', 'more' => null];
        $item = ['code' => 'a', 'title' => '題', 'url' => null, 'note' => null, 'at' => null];

        return [
            'kind の外' => [['kind' => 'chart']],
            'kind が無い' => [['count' => 1]],
            '足りないキー' => [array_diff_key($list, ['emptyText' => true])],
            '余るキー' => [[...$list, 'extra' => 1]],
            '値の型' => [[...$list, 'count' => '1']],
            '行の形' => [[...$list, 'items' => [[...$item, 'url' => 1]]]],
            '一覧でない行' => [[...$list, 'items' => ['a' => $item]]],
            '列挙の外' => [[
                'kind' => 'table', 'count' => null, 'columns' => [['code' => 'c', 'label' => '列', 'type' => 'money']],
                'rows' => [], 'emptyText' => 'ありません', 'more' => null,
            ]],
            '札の外' => [[
                'kind' => 'table', 'count' => null, 'columns' => [],
                'rows' => [['code' => 'r', 'url' => null, 'cells' => [['text' => '済', 'tone' => 'done']]]],
                'emptyText' => 'ありません', 'more' => null,
            ]],
        ];
    }

    #[DataProvider('malformedSections')]
    public function test_a_malformed_section_is_reported_and_closed_as_failed(mixed $malformed): void
    {
        Exceptions::fake();
        $this->registerRecordShowSection(
            new FixedRecordShowSection('test-broken', '壊れた節', '壊れた節', section: $malformed),
        );

        $content = $this->content($this->record->id, 'test-broken');

        $this->assertSame(['kind' => 'failed'], $content['content'] ?? null);
        Exceptions::assertReported(fn (LogicException $e) => str_contains($e->getMessage(), 'test-broken'));
    }

    public function test_each_kind_of_the_interface_passes_the_shape_check(): void
    {
        Exceptions::fake();
        $more = ['label' => 'すべて表示', 'url' => 'https://example.test/all'];
        $sections = [
            'test-table' => [
                'kind' => 'table', 'count' => 1,
                'columns' => [
                    ['code' => 'name', 'label' => '名前', 'type' => 'title'],
                    ['code' => 's', 'label' => '状態', 'type' => 'status'],
                ],
                'rows' => [[
                    'code' => 'r1', 'url' => null,
                    'cells' => [['text' => 'A', 'tone' => null], ['text' => '済', 'tone' => 'settled']],
                ]],
                'emptyText' => 'ありません', 'more' => $more,
            ],
            'test-list' => [
                'kind' => 'list', 'count' => null,
                'items' => [[
                    'code' => 'i1', 'title' => '題', 'url' => 'https://example.test/1',
                    'note' => '注', 'at' => '2026-10-11',
                ]],
                'emptyText' => 'ありません', 'more' => null,
            ],
            'test-lines' => [
                'kind' => 'lines', 'count' => 1, 'note' => null,
                'items' => [['time' => '00:00:01', 'label' => '話者', 'text' => '発言']], 'emptyText' => 'ありません',
            ],
        ];

        foreach ($sections as $code => $section) {
            $this->registerRecordShowSection(new FixedRecordShowSection($code, '節', '節', section: $section));
            $content = $this->panels()->content($this->contextFor(), 'show_check', $this->record->id, $code, null);
            $this->assertSame($section, $content['content'] ?? null);
        }

        Exceptions::assertNothingReported();
    }

    public function test_a_malformed_presence_is_reported_and_shown_without_a_count(): void
    {
        Exceptions::fake();
        $this->registerRecordShowSection(new FixedRecordShowSection('test-broken', '壊れた節', '壊れた節', ['count' => '3']));

        $panel = $this->panels()->panel($this->contextFor(), 'show_check', $this->record->id);

        $this->assertSame(
            [['code' => 'test-broken', 'heading' => '壊れた節', 'shortHeading' => '壊れた節', 'count' => null]],
            $panel['sections'] ?? null,
        );
        Exceptions::assertReported(LogicException::class);
    }

    public function test_an_exception_of_a_section_is_not_caught(): void
    {
        $throwing = new FixedRecordShowSection('test-throw', '投げる', '投げる', section: new RuntimeException('節の誤り'));
        $this->registerRecordShowSection($throwing);

        $this->expectExceptionObject(new RuntimeException('節の誤り'));
        $this->content($this->record->id, 'test-throw');
    }

    /**
     * @return array<string, array{list<array{string, string, string}>}>
     */
    public static function wrongDescriptors(): array
    {
        return [
            'code に - が無い' => [[['records', '節', '節']]],
            'code の大文字' => [[['Test-first', '節', '節']]],
            'code の末尾の改行' => [[["test-first\n", '節', '節']]],
            'code の重複' => [[['test-first', '節', '節'], ['test-first', '別の節', '別の節']]],
            '空の heading' => [[['test-first', ' ', ' ']]],
            '0 字の shortHeading' => [[['test-first', '外部参照の一覧', '']]],
            '5 字の shortHeading' => [[['test-first', '外部参照の一覧', '外部参照一']]],
            '4 字までの heading と違う shortHeading' => [[['test-first', 'メモ', 'め']]],
        ];
    }

    /**
     * @param  list<array{string, string, string}>  $descriptors
     */
    #[DataProvider('wrongDescriptors')]
    public function test_a_wrong_descriptor_throws_whenever_the_descriptors_are_read(array $descriptors): void
    {
        foreach ($descriptors as [$code, $heading, $shortHeading]) {
            $this->registerRecordShowSection(new FixedRecordShowSection($code, $heading, $shortHeading));
        }

        try {
            $this->panels()->panel($this->contextFor(), 'show_check', $this->record->id);
            $this->fail('名札の誤りで例外にならなかった');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $this->content($this->record->id, 'test-other');
    }

    /**
     * 登録された全部の実装を、見本のレコードの詳細(閲覧できる見る人)で呼んでも例外を投げない(登録が 0 でも通る。節を足すときは、
     * その節が null 以外を返す見本のデータを足す = 節の口.md の 5 節)。
     */
    public function test_every_registered_section_can_be_called_on_a_sample_record(): void
    {
        $project = $this->recordNamed('project', 'name', '社内ポータル刷新');
        $context = new AccessContext(null, null, null, CarbonImmutable::parse('2026-10-11'), 'ja');
        $panels = $this->panels();

        $panel = $panels->panel($context, 'project', $project->id);
        $this->assertNotNull($panel);

        foreach ($this->app->tagged(RecordShowSections::TAG) as $section) {
            $this->assertInstanceOf(RecordShowSections::class, $section);
            $code = $section->descriptor()['code'];
            $this->assertNotNull($panels->content($context, 'project', $project->id, $code, null));
        }
    }

    public function test_a_details_row_and_a_record_of_another_module_have_no_panel(): void
    {
        $module = RecordModule::query()->where('code', 'show_check')->sole();
        $details = $this->detailsField($module, 'rows');
        $row = Record::factory()->create([
            'record_module_id' => $details->child_record_module_id,
            'parent_record_module_id' => $module->id,
            'parent_record_id' => $this->record->id,
        ]);
        $other = Record::factory()->create(['record_module_id' => $this->recordModule('show_other')->id]);

        foreach ([$row->id, $other->id, 999_999_999] as $recordId) {
            $this->assertNull($this->panels()->panel($this->contextFor(), 'show_check', $recordId));
            $this->assertNull($this->content($recordId, 'revisions'));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function content(int $recordId, string $key, string $moduleCode = 'show_check'): ?array
    {
        return $this->panels()->content($this->contextFor(), $moduleCode, $recordId, $key, null);
    }

    private function panels(): RecordShowPanelQuery
    {
        return $this->app->make(RecordShowPanelQuery::class);
    }
}
