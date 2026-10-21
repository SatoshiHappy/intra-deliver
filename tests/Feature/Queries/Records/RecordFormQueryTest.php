<?php

namespace Tests\Feature\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 申請の画面のフォームの口(docs の 設計/ワークフロー/業務データとの接続/README.md 2 節「開き方」・9 節「口」、公開の口.md 7 節の表):
 * 定義と判定、一時保存の入力の初期値(定義に無いキーを捨て、型の解釈に通らない値を未設定にし、明細の行は id を持たない)。
 */
final class RecordFormQueryTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;

    public function test_the_definition_judges_the_action_on_the_module(): void
    {
        $this->field($this->recordModule('projects', [RecordModuleAction::Create]), 'title');

        $definition = app(RecordFormQuery::class)->definition($this->requester(), 'projects', RecordModuleAction::Create, $this->today());

        $this->assertSame(['projects', ['title']], [$definition['code'], array_column($definition['fields'], 'code')]);
        $this->expectException(AuthorizationException::class);
        app(RecordFormQuery::class)->definition($this->requester(), 'projects', RecordModuleAction::Update, $this->today());
    }

    public function test_allows_returns_the_judgement(): void
    {
        $this->recordModule('projects', [RecordModuleAction::Create]);
        $query = app(RecordFormQuery::class);

        $this->assertSame(
            [true, false],
            [$query->allows($this->requester(), 'projects', RecordModuleAction::Create, $this->today()), $query->allows($this->requester(), 'projects', RecordModuleAction::Update, $this->today())],
        );
    }

    public function test_a_missing_module_and_a_details_module_are_not_found(): void
    {
        $this->detailsField($this->recordModule('projects'), 'tasks');
        $calls = [
            fn (string $code) => app(RecordFormQuery::class)->definition($this->requester(), $code, RecordModuleAction::Create, $this->today()),
            fn (string $code) => app(RecordFormQuery::class)->allows($this->requester(), $code, RecordModuleAction::Create, $this->today()),
            fn (string $code) => $this->draftValues($code, [], []),
        ];

        foreach (['nope', 'tasks_rows'] as $code) {
            foreach ($calls as $call) {
                try {
                    $call($code);
                    $this->fail('見つかった');
                } catch (ModelNotFoundException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function test_draft_values_drop_unknown_keys_and_unset_values_that_do_not_parse_by_type(): void
    {
        $module = $this->recordModule('projects', []);
        $this->field($module, 'title', attributes: ['max_length' => 3]);
        $this->field($module, 'name');
        $this->field($module, 'budget', RecordFieldType::Integer);
        $this->field($module, 'rate', RecordFieldType::Decimal);
        $this->field($module, 'starts_on', RecordFieldType::Date);
        $this->field($module, 'starts_at', RecordFieldType::Datetime);
        $this->field($module, 'ends_at', RecordFieldType::Datetime);
        $this->field($module, 'urgent', RecordFieldType::Boolean);
        $this->field($module, 'checked', RecordFieldType::Boolean);
        $this->choiceField($module, 'status', ['open', 'done']);
        $this->choiceField($module, 'phase', ['plan']);
        $this->choiceField($module, 'kind', ['a']);
        $this->field($module, 'untouched');
        $this->detailsField($module, 'tasks');

        $draft = $this->draftValues('projects', [
            'title' => '長さは見ない', 'name' => 5, 'budget' => 'abc', 'rate' => '12.50', 'starts_on' => '2026-02-30',
            'starts_at' => '2026-10-11T09:30', 'ends_at' => '2026-10-11T09:30+09:00', 'urgent' => 'yes', 'checked' => true,
            'status' => 'done', 'phase' => 'gone', 'kind' => ['a'], 'tasks' => [['values' => []]], 'nope' => '定義に無い',
        ], []);

        $this->assertSame([
            'title' => '長さは見ない',
            'name' => null,
            'budget' => null,
            'rate' => '12.5',
            'starts_on' => null,
            'starts_at' => null,
            'ends_at' => '2026-10-11T00:30:00Z',
            'urgent' => false,
            'checked' => true,
            'status' => ['code' => 'done', 'name' => 'doneの名称'],
            'phase' => ['code' => 'gone', 'name' => null],
            'kind' => null,
            'untouched' => null,
        ], $draft['values']);
    }

    public function test_a_draft_string_of_invalid_utf8_is_unset(): void
    {
        $this->field($this->recordModule('projects'), 'title');

        $this->assertSame(['title' => null], $this->draftOfOneString("\xC3\x28"));
    }

    public function test_a_draft_string_with_u0000_is_unset_while_a_line_break_is_kept(): void
    {
        $this->field($this->recordModule('projects'), 'title');

        $this->assertSame(['title' => null], $this->draftOfOneString("前\0後"));
        $this->assertSame(['title' => "改行\nも残す"], $this->draftOfOneString("改行\nも残す"));
    }

    public function test_draft_references_look_up_targets_only_for_integers(): void
    {
        $customers = $this->recordModule('customers');
        $customerName = $this->field($customers, 'customer_name', attributes: ['is_title' => true]);
        $secrets = $this->recordModule('secrets', [RecordModuleAction::Create]);
        $secretName = $this->field($secrets, 'secret_name', attributes: ['is_title' => true]);
        $module = $this->recordModule('projects');
        $this->field($module, 'owner', RecordFieldType::User);
        $this->field($module, 'section', RecordFieldType::Department);
        $this->referenceField($module, 'customer', $customers);
        $this->referenceField($module, 'secret', $secrets);
        $this->referenceField($module, 'gone', $customers);
        $user = $this->user();
        $customer = Record::factory()->create(['record_module_id' => $customers->id, 'field_values' => [$customerName->id => '株式会社A']]);
        $secret = Record::factory()->create(['record_module_id' => $secrets->id, 'field_values' => [$secretName->id => '秘密']]);

        $draft = $this->draftValues('projects', ['owner' => $user->id, 'section' => (string) $user->id, 'customer' => $customer->id, 'secret' => $secret->id, 'gone' => 999_999], []);

        $this->assertSame([
            'owner' => ['id' => $user->id, 'code' => $user->code, 'label' => null, 'affiliation' => null],
            'section' => null,
            'customer' => ['id' => $customer->id, 'code' => null, 'label' => '株式会社A', 'affiliation' => null],
            'secret' => ['id' => $secret->id, 'code' => null, 'label' => null, 'affiliation' => null],
            'gone' => ['id' => 999_999, 'code' => null, 'label' => null, 'affiliation' => null],
        ], $draft['values']);
    }

    public function test_a_record_reference_to_a_record_the_field_cannot_point_to_has_no_label(): void
    {
        $customers = $this->recordModule('customers');
        $customerName = $this->field($customers, 'customer_name', attributes: ['is_title' => true]);
        $partners = $this->recordModule('partners');
        $module = $this->recordModule('projects');
        $this->referenceField($module, 'customer', $customers);
        $this->referenceField($module, 'partner', $partners);
        $this->referenceField($module, 'row_target', $customers);
        $notes = $this->detailsField($customers, 'notes');
        $customer = Record::factory()->create(['record_module_id' => $customers->id, 'field_values' => [$customerName->id => '株式会社A']]);
        $row = Record::factory()->create(['record_module_id' => $notes->child_record_module_id, 'parent_record_module_id' => $customers->id, 'parent_record_id' => $customer->id]);

        $draft = $this->draftValues('projects', ['customer' => $customer->id, 'partner' => $customer->id, 'row_target' => $row->id], []);

        $this->assertSame([
            'customer' => ['id' => $customer->id, 'code' => null, 'label' => '株式会社A', 'affiliation' => null],
            'partner' => ['id' => $customer->id, 'code' => null, 'label' => null, 'affiliation' => null],
            'row_target' => ['id' => $row->id, 'code' => null, 'label' => null, 'affiliation' => null],
        ], $draft['values']);
    }

    public function test_draft_detail_rows_have_values_only_without_ids(): void
    {
        $module = $this->recordModule('projects');
        $tasks = $this->detailsField($module, 'tasks');
        $this->field($this->childOf($tasks), 'task_name');
        $this->field($this->childOf($tasks), 'hours', RecordFieldType::Integer);
        $this->detailsField($module, 'notes');

        $draft = $this->draftValues('projects', [], [
            'tasks' => [['id' => 41, 'values' => ['task_name' => '設計', 'hours' => 'x', 'nope' => 1]], ['values' => null], '行でない'],
            'nope' => [['values' => ['task_name' => '定義に無い明細']]],
        ]);

        $this->assertSame([
            'tasks' => [['values' => ['task_name' => '設計', 'hours' => null]], ['values' => ['task_name' => null, 'hours' => null]]],
            'notes' => [],
        ], $draft['details']);
    }

    /**
     * 再申請の画面の初期値(公開の口.md 7 節の editValues): 保存された値を編集の画面と同じ形で返し、明細の行は id を持つ。版は編集の画面と同じ。業務モジュールの判定をしない
     * (read も update も許されない業務モジュールでも返す = 呼び手が先に判定する)。
     */
    public function test_edit_values_return_the_stored_values_with_the_detail_row_ids_without_judging(): void
    {
        $module = $this->recordModule('projects', []);
        $title = $this->field($module, 'title');
        $tasks = $this->detailsField($module, 'tasks');
        $taskName = $this->field($this->childOf($tasks), 'task_name');
        $record = Record::factory()->create(['record_module_id' => $module->id, 'field_values' => [$title->id => '計画']]);
        $row = Record::factory()->create(['record_module_id' => $tasks->child_record_module_id, 'parent_record_module_id' => $module->id, 'parent_record_id' => $record->id, 'field_values' => [$taskName->id => '設計']]);

        $this->assertSame(
            [
                'values' => ['title' => '計画'],
                'details' => ['tasks' => [['id' => $row->id, 'values' => ['task_name' => '設計']]]],
                'version' => $record->refresh()->version(),
            ],
            app(RecordFormQuery::class)->editValues($this->requester(), 'projects', $record->id, $this->today(), 'ja'),
        );
    }

    /**
     * 業務モジュールが無い・明細のモジュール、レコードがその業務モジュールに無い・無いレコードは 404。
     */
    public function test_edit_values_of_a_missing_module_or_a_record_of_another_module_are_not_found(): void
    {
        $module = $this->recordModule('projects');
        $this->detailsField($module, 'tasks');
        $other = Record::factory()->create(['record_module_id' => $this->recordModule('customers')->id]);
        $own = Record::factory()->create(['record_module_id' => $module->id]);

        foreach ([['nope', $own->id], ['tasks_rows', $own->id], ['projects', $other->id], ['projects', 0]] as [$code, $recordId]) {
            $this->assertThrows(fn () => app(RecordFormQuery::class)->editValues($this->requester(), $code, $recordId, $this->today(), 'ja'), ModelNotFoundException::class);
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, mixed>  $details
     * @return array<string, mixed>
     */
    private function draftValues(string $moduleCode, array $values, array $details): array
    {
        return app(RecordFormQuery::class)->draftValues($this->requester(), $moduleCode, $values, $details, $this->today(), 'ja');
    }

    /**
     * 1 行の文字列の項目 title を持つ業務モジュール projects の、title だけの一時保存の値。
     *
     * @return array<string, mixed>
     */
    private function draftOfOneString(string $value): array
    {
        return $this->draftValues('projects', ['title' => $value], [])['values'];
    }

    private function requester(): Requester
    {
        return new Requester(null, null);
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::TODAY);
    }
}
