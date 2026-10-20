<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Actions\Records\AddRecordField;
use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Enums\Records\RecordFieldType;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RelativeDefault;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 相対の既定値の画面の props と呼び手(docs の 設計/業務データの入れ物/項目と規則/README.md 3 節、
 * 設計/業務データの入れ物/画面/管理の画面.md 3.3・3.4、設計/業務データの入れ物/画面/業務の画面.md 2.2 の「初期値」、
 * 設計/業務データの入れ物/項目と規則/規則と業務処理/画面と検査.md 13 節の「既定値」と「呼び手に依らない」)。
 * 初期データを入れ、時計を止めてシステム管理者(u0004)で開く。
 */
final class RelativeDefaultControllerTest extends TransactionalTestCase
{
    use BuildsRecordModules;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 15:30:45 UTC');
        $this->withoutVite();
        $this->actingAs(User::query()->where('code', 'u0004')->sole());
    }

    public function test_the_admin_forms_carry_the_duration_instead_of_a_value(): void
    {
        $date = ['type' => 'date'];
        app(AddRecordField::class)->execute('project', [
            ...$date, 'code' => 'review_on', 'name' => '見直し日', 'default_value' => ['relative' => 'P6M'],
        ]);
        app(AddRecordField::class)->execute('project', [
            ...$date, 'code' => 'kickoff_on', 'name' => '開始予定日', 'default_value' => '2026-12-01',
        ]);

        $this->get('/records/admin/modules/project/fields/review_on/edit')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('field.relativeDefault', 'P6M')
                ->where('field.defaultValue', null)
                ->where('stringMaxLengths.multilineMax', 20000));
        $this->get('/records/admin/modules/project/fields/kickoff_on/edit')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('field.relativeDefault', null)
                ->where('field.defaultValue', '2026-12-01'));
        $this->get('/records/admin/modules/project')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('module.fields', fn (Collection $fields) => $fields
                ->contains(fn (array $field) => [$field['code'], $field['relativeDefault'], $field['defaultValue']]
                    === ['review_on', 'P6M', null])));
    }

    /**
     * 登録の画面の初期値(明細の新しい行を含む)と、業務処理がキーの無い入力に入れる値が、同じ時刻で同じ。固定の値は今のまま。
     */
    public function test_the_create_form_and_the_action_make_the_same_value(): void
    {
        $module = $this->recordModule('meetings');
        $heldOn = $this->field($module, 'held_on', RecordFieldType::Date, $this->relative('P30D'));
        $startsAt = $this->field($module, 'starts_at', RecordFieldType::Datetime, $this->relative('P0D'));
        $fixed = ['default_value' => '2026-12-01T01:00:00Z'];
        $endsAt = $this->field($module, 'ends_at', RecordFieldType::Datetime, $fixed);
        $child = $this->childOf($this->detailsField($module, 'agenda'));
        $dueOn = $this->field($child, 'due_on', RecordFieldType::Date, $this->relative('P6M'));

        $this->get('/records/data/meetings/create')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('module.fields.0.defaultValue', '2026-11-10')
            ->where('module.fields.1.defaultValue', '2026-10-10T15:30:00Z')
            ->where('module.fields.2.defaultValue', '2026-12-01T01:00:00Z')
            ->where('module.fields.3.childModule.fields.0.defaultValue', '2027-04-11'));
        $input = new RecordInput([], ['agenda' => [new RecordDetailRowInput(values: [])]]);
        $id = app(CreateRecord::class)->execute($this->contextFor(), 'meetings', $input);

        $this->assertEquals([
            $heldOn->id => '2026-11-10',
            $startsAt->id => '2026-10-10T15:30:00Z',
            $endsAt->id => '2026-12-01T01:00:00Z',
        ], Record::query()->findOrFail($id)->field_values);
        $this->assertSame(
            [$dueOn->id => '2027-04-11'],
            Record::query()->where('parent_record_id', $id)->sole()->field_values,
        );
    }

    /**
     * 同じ入力を HTTP の要求(管理のルート)と業務処理の直接の呼び出しで与えると、キーと文言が同じ。
     *
     * @param  array<string, mixed>  $input
     */
    #[TestWith([['code' => 'held_on', 'name' => '開催日', 'type' => 'date', 'default_value' => ['relative' => 'P1W']]])]
    #[TestWith([['code' => 'held_on', 'name' => '開催日', 'type' => 'datetime', 'default_value' => ['relative' => '']]])]
    #[TestWith([['code' => 'memo', 'name' => 'メモ', 'type' => 'string', 'default_value' => ['relative' => 'P6M']]])]
    #[TestWith([['code' => 'memo', 'name' => 'メモ', 'type' => 'string', 'is_multiline' => true, 'max_length' => 20001]])]
    public function test_the_http_request_and_the_direct_call_return_the_same_errors(array $input): void
    {
        $viaHttp = $this->postJson('/records/admin/modules/project/fields', $input)->assertUnprocessable();

        try {
            app(AddRecordField::class)->execute('project', $input);
            $this->fail('検証の失敗にならなかった');
        } catch (ValidationException $exception) {
            $this->assertSame($exception->errors(), $viaHttp->json('errors'));
        }
    }

    /**
     * 相対の既定値を持つ項目の属性。
     *
     * @return array{default_value: RelativeDefault|null}
     */
    private function relative(string $duration): array
    {
        return ['default_value' => RelativeDefault::tryFromDuration($duration)];
    }
}
