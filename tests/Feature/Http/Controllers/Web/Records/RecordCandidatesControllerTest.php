<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\Support\TransactionalTestCase;

/**
 * 他モジュール参照の候補の口(docs の 設計/業務データの入れ物/画面/README.md 4 節、ADR 0008): GET /records/data/{module}/candidates?q= が、
 * 表示の項目の値が語を含むレコードか、語と id が等しいレコードを id の降順で 10 件まで JSON で返す(大文字小文字を区別せず、%・_・\ は文字)。
 * q は任意(無い・空・空白だけは語で絞らず、id の降順の先頭から 10 件 = 参照の選択欄が開いた直後)。
 * 101 文字・UTF-8 として正しくない語は 422(業務モジュールの 404・403 の後)。初期データを入れ、ゲストで開く。
 */
final class RecordCandidatesControllerTest extends TransactionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
    }

    public function test_finds_records_by_a_part_of_the_title_ignoring_the_case(): void
    {
        $portal = $this->projectId('社内ポータル刷新');

        $this->candidates('project', 'ポータル')->assertOk()->assertExactJson(['records' => [['id' => $portal, 'label' => '社内ポータル刷新']]]);
        $this->candidates('project_issue', '旧ポータル')->assertJsonPath('records.0.label', '旧ポータルのデータ移行手順');
    }

    public function test_finds_a_record_by_its_id_and_treats_wildcards_as_characters(): void
    {
        $campaign = $this->projectId('新規顧客開拓キャンペーン');

        $this->candidates('project', (string) $campaign)->assertExactJson(['records' => [['id' => $campaign, 'label' => '新規顧客開拓キャンペーン']]]);
        $this->candidates('project', '%')->assertExactJson(['records' => []]);
        $this->candidates('project', '_')->assertExactJson(['records' => []]);
    }

    public function test_lists_up_to_10_records_newest_first(): void
    {
        $module = RecordModule::query()->with('recordFields')->where('code', 'project')->sole();
        $title = $module->recordFields->firstWhere('code', 'name');
        Record::factory()->count(11)->create(['record_module_id' => $module->id, 'field_values' => [$title?->id => '追加の案件']]);
        $newest = Record::query()->where('record_module_id', $module->id)->max('id');

        $response = $this->candidates('project', '追加');

        $response->assertJsonCount(10, 'records')->assertJsonPath('records.0.id', $newest);
    }

    public function test_exclude_removes_that_record_in_sql_and_keeps_10_records(): void
    {
        $module = RecordModule::query()->with('recordFields')->where('code', 'project')->sole();
        $title = $module->recordFields->firstWhere('code', 'name');
        Record::factory()->count(11)->create(['record_module_id' => $module->id, 'field_values' => [$title?->id => '追加の案件']]);
        $newest = Record::query()->where('record_module_id', $module->id)->max('id');

        $response = $this->getJson('/records/data/project/candidates?'.http_build_query(['q' => '追加', 'exclude' => $newest]));

        $response->assertOk()->assertJsonCount(10, 'records');
        $this->assertNotContains($newest, array_column((array) $response->json('records'), 'id'));
        $this->assertNotContains($newest, array_column((array) $this->getJson('/records/data/project/candidates?'.http_build_query(['exclude' => $newest]))->json('records'), 'id'));
        $this->assertContains($newest, array_column((array) $this->candidates('project', '追加')->json('records'), 'id'));
    }

    public function test_rejects_an_exclude_that_is_not_a_positive_integer(): void
    {
        foreach (['abc', '1.5', '0', '-1'] as $exclude) {
            $this->getJson('/records/data/project/candidates?'.http_build_query(['exclude' => $exclude]))
                ->assertUnprocessable()->assertJsonValidationErrors(['exclude']);
        }
        $this->getJson('/records/data/project/candidates?exclude[]=1')->assertUnprocessable()->assertJsonValidationErrors(['exclude']);
        $this->getJson('/records/data/project/candidates?exclude=')->assertOk();
        $this->getJson('/records/data/nothing/candidates?exclude=abc')->assertNotFound();
    }

    public function test_the_response_is_not_stored_by_caches(): void
    {
        $response = $this->candidates('project', '社内');

        $this->assertTrue($response->headers->getCacheControlDirective('private'));
        $this->assertTrue($response->headers->getCacheControlDirective('no-store'));
    }

    public function test_a_missing_or_blank_word_does_not_narrow_down_and_returns_the_newest_first(): void
    {
        $module = RecordModule::query()->where('code', 'project')->sole();
        $expected = Record::query()->where('record_module_id', $module->id)->orderByDesc('id')->limit(10)->pluck('id')->all();
        $this->assertNotEmpty($expected);

        foreach ([$this->candidates('project', ''), $this->candidates('project', '   '), $this->getJson('/records/data/project/candidates')] as $response) {
            $response->assertOk();
            $this->assertSame($expected, array_column((array) $response->json('records'), 'id'));
        }
    }

    public function test_a_blank_word_returns_at_most_10_records_newest_first(): void
    {
        $module = RecordModule::query()->with('recordFields')->where('code', 'project')->sole();
        $title = $module->recordFields->firstWhere('code', 'name');
        Record::factory()->count(11)->create(['record_module_id' => $module->id, 'field_values' => [$title?->id => '追加の案件']]);
        $newest = Record::query()->where('record_module_id', $module->id)->max('id');

        $response = $this->candidates('project', '');

        $response->assertJsonCount(10, 'records')->assertJsonPath('records.0.id', $newest);
    }

    public function test_rejects_a_too_long_word_or_an_array(): void
    {
        $this->candidates('project', str_repeat('a', 100))->assertOk();
        $this->candidates('project', str_repeat('a', 101))->assertUnprocessable()->assertJsonValidationErrors(['q' => '検索する語は 100 文字以内にしてください']);
        $this->getJson('/records/data/project/candidates?q[]=a')->assertUnprocessable()->assertJsonValidationErrors(['q']);
    }

    public function test_rejects_a_word_that_is_not_valid_utf8(): void
    {
        $this->candidates('project', "\xFF")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['q' => '検索する語の指定が正しくありません']);
    }

    public function test_a_missing_module_or_a_details_module_is_not_found(): void
    {
        $this->candidates('nothing', 'a')->assertNotFound();
        $this->candidates('nothing', str_repeat('a', 101))->assertNotFound();
        $this->candidates('project_milestone', 'a')->assertNotFound();
    }

    private function projectId(string $name): int
    {
        $module = RecordModule::query()->with('recordFields')->where('code', 'project')->sole();
        $title = $module->recordFields->firstWhere('code', 'name');

        return Record::query()->where('record_module_id', $module->id)->get()
            ->firstOrFail(fn (Record $record) => ($record->field_values[$title?->id] ?? null) === $name)->id;
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function candidates(string $moduleCode, string $keyword): TestResponse
    {
        return $this->getJson("/records/data/{$moduleCode}/candidates?".http_build_query(['q' => $keyword]));
    }
}
