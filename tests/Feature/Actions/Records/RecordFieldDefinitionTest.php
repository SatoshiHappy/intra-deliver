<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\AddRecordField;
use App\Actions\Records\DeleteRecordField;
use App\Actions\Records\UpdateRecordField;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Records\RecordRuleKind;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldOption;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Models\Records\RecordRule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordPageQuery;
use App\Queries\Records\RecordQuery;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 項目を足す・変える・消す業務処理(docs の 設計/業務データの入れ物/項目と規則/README.md 1 節・3 節・4 節・6 節・
 * 設計/業務データの入れ物/項目と規則/規則と業務処理/失敗と定義の変更.md 11 節、画面と検査.md 13 節の「定義の変更」と
 * 「2 段目の明細の項目を作れない」と「`is_multiline` の変更」。設計/業務データの入れ物/データモデル/制約の分担と検査.md 6 節の例外 2・3)。
 * 並び替えと追加の並び順は RecordFieldOrderTest。
 */
final class RecordFieldDefinitionTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;
    use CatchesValidationErrors;

    /**
     * 足す項目の入力 → 拒むキーと文言。
     *
     * @return array<string, array{array<string, mixed>, array<string, string>}>
     */
    public static function rejectedNewFields(): array
    {
        return [
            'コードの重なり' => [['code' => 'title', 'name' => '件名2', 'type' => 'string'], ['code' => 'このコードは既に使用されています']],
            'コードの書式' => [['code' => 'Title2', 'name' => '件名', 'type' => 'string'], ['code' => 'コードは英小文字で始まる英小文字・数字・_ の 50 文字までにしてください']],
            '予約語 id' => [['code' => 'id', 'name' => 'ID', 'type' => 'integer'], ['code' => 'id は予約語のためコードに使用できません']],
            '予約語 create' => [['code' => 'create', 'name' => '作成', 'type' => 'string'], ['code' => 'create は予約語のためコードに使用できません']],
            '予約語 reorder' => [['code' => 'reorder', 'name' => '並び替え', 'type' => 'string'], ['code' => 'reorder は予約語のためコードに使用できません']],
            '名称の空白だけ' => [['code' => 'memo', 'name' => '   ', 'type' => 'string'], ['name' => '名前を入力してください']],
            '名称の U+0000' => [['code' => 'memo', 'name' => "メ\0モ", 'type' => 'string'], ['name' => '名前に使用できない文字(U+0000)が含まれています']],
            '型が無い' => [['code' => 'memo', 'name' => 'メモ', 'type' => 'text'], ['type' => '型を選択してください']],
            '2 つ目の表示の項目' => [['code' => 'memo', 'name' => 'メモ', 'type' => 'string', 'is_title' => true], ['is_title' => '表示の項目は業務モジュールに 1 つまでです(前の項目の表示の項目を先に解除してください)']],
            '最大の文字数 0' => [['code' => 'memo', 'name' => 'メモ', 'type' => 'string', 'max_length' => 0], ['max_length' => '最大の文字数は 1〜1000 にしてください']],
            '1 行の最大の文字数 1001' => [['code' => 'memo', 'name' => 'メモ', 'type' => 'string', 'max_length' => 1001], ['max_length' => '最大の文字数は 1〜1000 にしてください']],
            '複数行の最大の文字数 20001' => [['code' => 'memo', 'name' => 'メモ', 'type' => 'string', 'is_multiline' => true, 'max_length' => 20001], ['max_length' => '最大の文字数は 1〜20000 にしてください']],
            '整数の最大の文字数' => [['code' => 'budget', 'name' => '予算', 'type' => 'integer', 'max_length' => 10], ['max_length' => '最大の文字数は文字列の項目だけに設定できます']],
            '真偽の必須' => [['code' => 'urgent', 'name' => '至急', 'type' => 'boolean', 'is_required' => true], ['is_required' => '真偽の項目は必須にできません(常に値があります)']],
            '最大の文字数を超える既定値' => [['code' => 'memo', 'name' => 'メモ', 'type' => 'string', 'max_length' => 2, 'default_value' => 'あいう'], ['default_value' => '既定値は 2 文字以内で入力してください']],
            '選択肢に無い既定値' => [['code' => 'state', 'name' => '状態', 'type' => 'choice', 'options' => [['code' => 'open', 'name' => '未着手']], 'default_value' => 'done'], ['default_value' => '既定値の値が正しくありません']],
            '選択肢の無い選択' => [['code' => 'state', 'name' => '状態', 'type' => 'choice', 'options' => []], ['options' => '選択肢を 1 つ以上入力してください']],
            '選択肢のコードの重なりと書式' => [['code' => 'state', 'name' => '状態', 'type' => 'choice', 'options' => [['code' => 'open', 'name' => 'a'], ['code' => 'open', 'name' => 'b'], ['code' => 'Done', 'name' => '']]], [
                'options.1.code' => '同じコードが 2 度あります',
                'options.2.code' => 'コードは英小文字・数字・_ の 50 文字までにしてください',
                'options.2.name' => '名称を入力してください',
            ]],
            '参照の項目の既定値' => [['code' => 'owner', 'name' => '担当', 'type' => 'user', 'default_value' => '1'], ['default_value' => 'この型の項目には既定値を設定できません']],
            '明細の一覧の旗' => [['code' => 'tasks', 'name' => 'タスク', 'type' => 'details', 'child_module' => ['code' => 'project_tasks', 'name' => 'タスク'], 'is_listed' => true], ['is_listed' => '明細の項目は一覧に表示できません']],
            '明細のモジュールのコードの重なり' => [['code' => 'tasks', 'name' => 'タスク', 'type' => 'details', 'child_module' => ['code' => 'projects', 'name' => 'タスク']], ['child_module.code' => 'このコードは既に使用されています']],
            '参照先の無い他モジュール参照' => [['code' => 'customer', 'name' => '顧客', 'type' => 'record', 'referenced_module' => 'nothing'], ['referenced_module' => '参照先の業務モジュールを選択してください(明細のモジュールは選択できません)']],
        ];
    }

    public function test_adds_a_field_without_changing_existing_records(): void
    {
        $module = $this->projects();
        $record = Record::factory()->create(['record_module_id' => $module->id, 'field_values' => [$module->recordFields->firstOrFail()->id => '既存']]);
        $before = $record->refresh()->getAttributes();

        $field = $this->add(['code' => 'state', 'name' => '状態', 'type' => 'choice', 'options' => [['code' => 'open', 'name' => '未着手'], ['code' => 'done', 'name' => '完了']], 'default_value' => 'open', 'is_listed' => true, 'sort_key' => 5]);

        $this->assertSame([RecordFieldType::Choice, 'open', true, 5], [$field->type, $field->default_value, $field->is_listed, $field->sort_key]);
        $this->assertSame([['open', 0], ['done', 1]], $field->recordFieldOptions->map(fn (RecordFieldOption $option) => [$option->code, $option->sort_key])->all());
        $this->assertSame($before, $record->refresh()->getAttributes());
    }

    public function test_adds_a_details_field_with_its_details_module_and_a_reference_field(): void
    {
        $module = $this->projects();
        $customers = $this->recordModule('customers');

        $details = $this->add(['code' => 'tasks', 'name' => 'タスク', 'type' => 'details', 'child_module' => ['code' => 'project_tasks', 'name' => 'プロジェクトのタスク']]);
        $reference = $this->add(['code' => 'customer', 'name' => '顧客', 'type' => 'record', 'referenced_module' => 'customers']);

        $child = RecordModule::query()->where('code', 'project_tasks')->sole();
        $this->assertSame([$child->id, $module->id, 'プロジェクトのタスク'], [$details->child_record_module_id, $child->parent_record_module_id, $child->name]);
        $this->assertSame($customers->id, $reference->referenced_record_module_id);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $expected
     */
    #[DataProvider('rejectedNewFields')]
    public function test_rejects_a_new_field_that_breaks_the_rules_and_writes_nothing(array $input, array $expected): void
    {
        $this->projects();
        $fields = RecordField::query()->count();

        $this->assertSame($expected, $this->validationErrors(fn () => $this->add($input)));
        $this->assertSame($fields, RecordField::query()->count());
    }

    public function test_cannot_add_a_details_field_to_a_details_module_or_refer_to_one(): void
    {
        $module = $this->projects();
        $this->detailsField($module, 'tasks');

        $nested = $this->validationErrors(fn () => app(AddRecordField::class)->execute('tasks_rows', ['code' => 'sub', 'name' => '下位', 'type' => 'details', 'child_module' => ['code' => 'sub_rows', 'name' => '下位']]));
        $toDetails = $this->validationErrors(fn () => $this->add(['code' => 'task', 'name' => 'タスク', 'type' => 'record', 'referenced_module' => 'tasks_rows']));

        $this->assertSame(['type' => '明細のモジュールに明細の項目は追加できません(明細は 1 段だけ)'], $nested);
        $this->assertSame(['referenced_module'], array_keys($toDetails));
    }

    public function test_changes_attributes_and_checks_the_default_value_with_the_changed_attributes(): void
    {
        $this->projects();
        $this->add(['code' => 'memo', 'name' => 'メモ', 'type' => 'string', 'default_value' => 'あいうえお']);

        $errors = $this->validationErrors(fn () => $this->change('memo', ['max_length' => 3]));
        $field = $this->change('memo', ['name' => 'ノート', 'max_length' => 5, 'is_searchable' => true]);

        $this->assertSame(['default_value' => '既定値は 3 文字以内で入力してください'], $errors);
        $this->assertSame(['ノート', 5, true, 'あいうえお'], [$field->name, $field->max_length, $field->is_searchable, $field->default_value]);
    }

    public function test_cannot_change_the_code_or_the_type(): void
    {
        $module = $this->projects();

        $errors = $this->validationErrors(fn () => $this->change('title', ['code' => 'subject', 'type' => 'integer']));

        $this->assertSame(['code' => '追加後は変更できません', 'type' => '追加後は変更できません'], $errors);
        $this->assertSame(RecordFieldType::String, RecordField::query()->where('record_module_id', $module->id)->where('code', 'title')->sole()->type);
    }

    public function test_cannot_change_single_line_or_multiline_but_accepts_the_same_value_or_none(): void
    {
        $module = $this->projects();
        $this->field($module, 'summary', attributes: ['is_multiline' => true]);

        $toMultiline = $this->validationErrors(fn () => $this->change('title', ['is_multiline' => true, 'name' => '']));
        $toSingleLine = $this->validationErrors(fn () => $this->change('summary', ['is_multiline' => false]));
        $same = $this->change('summary', ['is_multiline' => true, 'name' => '概要']);
        $omitted = $this->change('title', ['name' => '件名']);

        $this->assertSame(['is_multiline' => '追加後は変更できません', 'name' => '名前を入力してください'], $toMultiline);
        $this->assertSame(['is_multiline' => '追加後は変更できません'], $toSingleLine);
        $this->assertSame([true, '概要', false, '件名'], [$same->is_multiline, $same->name, $omitted->is_multiline, $omitted->name]);
    }

    public function test_a_null_flag_is_unspecified_so_a_change_keeps_the_current_value_and_an_add_takes_the_default(): void
    {
        $module = $this->projects();
        $this->field($module, 'summary', attributes: ['is_multiline' => true, 'is_required' => true, 'is_listed' => true, 'is_searchable' => true]);
        $nulls = ['is_required' => null, 'is_multiline' => null, 'is_listed' => null, 'is_searchable' => null, 'is_title' => null, 'sort_key' => null];

        $summary = $this->change('summary', ['name' => '概要', ...$nulls]);
        $title = $this->change('title', $nulls);
        $added = $this->add(['code' => 'note', 'name' => '備考', 'type' => 'string', ...$nulls]);

        $this->assertSame([true, true, true, true, false], [$summary->is_multiline, $summary->is_required, $summary->is_listed, $summary->is_searchable, $summary->is_title]);
        $this->assertTrue($title->is_title);
        $this->assertSame([false, false, false, false, false], [$added->is_multiline, $added->is_required, $added->is_listed, $added->is_searchable, $added->is_title]);
    }

    public function test_a_null_value_key_is_empty_so_max_length_and_default_value_are_cleared_and_the_name_is_rejected(): void
    {
        $this->projects();
        $this->add(['code' => 'memo', 'name' => 'メモ', 'type' => 'string', 'max_length' => 10, 'default_value' => 'あ']);

        $errors = $this->validationErrors(fn () => $this->change('memo', ['name' => null]));
        $field = $this->change('memo', ['max_length' => null, 'default_value' => null]);

        $this->assertSame(['name' => '名前を入力してください'], $errors);
        $this->assertSame(['メモ', null, null], [$field->name, $field->max_length, $field->default_value]);
    }

    public function test_a_non_boolean_multiline_flag_gets_the_flag_message_and_multiline_on_a_non_string_field_gets_the_combination_message(): void
    {
        $module = $this->projects();
        $this->field($module, 'amount', RecordFieldType::Integer);

        $notBoolean = $this->validationErrors(fn () => $this->change('title', ['is_multiline' => 'x']));
        $notBooleanOnInteger = $this->validationErrors(fn () => $this->change('amount', ['is_multiline' => 'x']));
        $multilineOnInteger = $this->validationErrors(fn () => $this->change('amount', ['is_multiline' => true]));
        $singleLineOnInteger = $this->change('amount', ['is_multiline' => false, 'name' => '金額']);

        $this->assertSame(['is_multiline' => '真偽(true・false)で指定してください'], $notBoolean);
        $this->assertSame(['is_multiline' => '真偽(true・false)で指定してください'], $notBooleanOnInteger);
        $this->assertSame(['is_multiline' => '複数行は文字列の項目だけに設定できます'], $multilineOnInteger);
        $this->assertSame(['金額', false], [$singleLineOnInteger->name, $singleLineOnInteger->is_multiline]);
    }

    public function test_replaces_options_and_rejects_removing_an_option_in_use(): void
    {
        $module = $this->projects();
        $state = $this->choiceField($module, 'state', ['open', 'done', 'hold']);
        $target = $this->field($module, 'reason');
        Record::factory()->create(['record_module_id' => $module->id, 'field_values' => [$state->id => 'done']]);
        RecordRule::factory()->create(['record_module_id' => $module->id, 'kind' => RecordRuleKind::RequiredWhen, 'target_record_field_id' => $target->id, 'condition' => ['all' => [['field' => 'state', 'op' => 'in', 'value' => ['hold']]]]]);
        $open = ['code' => 'open', 'name' => '未着手'];

        $usedByRecord = $this->validationErrors(fn () => $this->change('state', ['options' => [$open, ['code' => 'hold', 'name' => '保留']]]));
        $usedByRule = $this->validationErrors(fn () => $this->change('state', ['options' => [$open, ['code' => 'done', 'name' => '完了']]]));
        $usedByDefault = $this->validationErrors(fn () => $this->change('state', ['default_value' => 'gone', 'options' => [$open, ['code' => 'done', 'name' => '完了'], ['code' => 'hold', 'name' => '保留']]]));
        $field = $this->change('state', ['options' => [['code' => 'hold', 'name' => '保留中'], ['code' => 'done', 'name' => '完了'], $open, ['code' => 'new', 'name' => '新規']]]);

        $this->assertSame(['options' => '削除する選択肢が、レコードの値か規則の条件で使用されています'], $usedByRecord);
        $this->assertSame(['options' => '削除する選択肢が、レコードの値か規則の条件で使用されています'], $usedByRule);
        $this->assertSame(['default_value' => '既定値の値が正しくありません'], $usedByDefault);
        $this->assertSame([['hold', '保留中', 0], ['done', '完了', 1], ['open', '未着手', 2], ['new', '新規', 3]], $field->recordFieldOptions->map(fn (RecordFieldOption $option) => [$option->code, $option->name, $option->sort_key])->values()->all());
    }

    public function test_removes_an_unused_option(): void
    {
        $module = $this->projects();
        $this->choiceField($module, 'state', ['open', 'done']);

        $field = $this->change('state', ['options' => [['code' => 'open', 'name' => '未着手']]]);

        $this->assertSame(['open'], $field->recordFieldOptions->pluck('code')->all());
    }

    public function test_rejects_deleting_a_field_used_by_a_rule(): void
    {
        $module = $this->projects();
        $start = $this->field($module, 'start', RecordFieldType::Date);
        $end = $this->field($module, 'end', RecordFieldType::Date);
        $reason = $this->field($module, 'reason');
        $this->field($module, 'status');
        RecordRule::factory()->compare()->create(['record_module_id' => $module->id, 'target_record_field_id' => $end->id, 'compared_record_field_id' => $start->id]);
        RecordRule::factory()->create(['record_module_id' => $module->id, 'target_record_field_id' => $reason->id, 'condition' => ['all' => [['field' => 'status', 'op' => 'eq', 'value' => 'done']]]]);

        foreach (['start', 'end', 'reason', 'status'] as $code) {
            $this->assertSame(['field' => "{$code}を使用する規則があります。先に規則を削除してください"], $this->validationErrors(fn () => app(DeleteRecordField::class)->execute('projects', $code)));
        }

        $this->assertSame(5, RecordField::query()->where('record_module_id', $module->id)->count());
    }

    public function test_deleting_a_field_hides_its_values_and_removes_its_references_and_a_new_field_of_the_same_code_starts_empty(): void
    {
        $module = $this->projects();
        $note = $this->field($module, 'note');
        $owner = $this->field($module, 'owner', RecordFieldType::User);
        $record = Record::factory()->create(['record_module_id' => $module->id, 'field_values' => [$note->id => '消す前の値']]);
        RecordReference::factory()->create(['record_module_id' => $module->id, 'record_id' => $record->id, 'record_field_id' => $owner->id]);

        app(DeleteRecordField::class)->execute('projects', 'note');
        app(DeleteRecordField::class)->execute('projects', 'owner');
        $this->add(['code' => 'note', 'name' => 'メモ', 'type' => 'string']);

        $this->assertSame(0, RecordReference::query()->where('record_id', $record->id)->count());
        $this->assertEquals([$note->id => '消す前の値'], $record->refresh()->field_values);
        $this->assertSame(['title' => null, 'note' => null], $this->show($record)['values']);
    }

    public function test_deleting_a_details_field_removes_its_module_fields_rules_and_rows(): void
    {
        $module = $this->projects();
        $tasks = $this->detailsField($module, 'tasks');
        $child = $this->childOf($tasks);
        $hours = $this->field($child, 'hours', RecordFieldType::Integer);
        $note = $this->field($child, 'note');
        $assignee = $this->field($child, 'assignee', RecordFieldType::User);
        RecordRule::factory()->create(['record_module_id' => $child->id, 'target_record_field_id' => $note->id, 'condition' => ['all' => [['field' => 'hours', 'op' => 'gt', 'value' => 8]]]]);
        $record = Record::factory()->create(['record_module_id' => $module->id]);
        $row = Record::factory()->create(['record_module_id' => $child->id, 'parent_record_module_id' => $module->id, 'parent_record_id' => $record->id, 'field_values' => [$hours->id => 9]]);
        RecordReference::factory()->create(['record_module_id' => $child->id, 'record_id' => $row->id, 'record_field_id' => $assignee->id]);

        app(DeleteRecordField::class)->execute('projects', 'tasks');

        $this->assertModelMissing($child);
        $this->assertModelMissing($tasks);
        $this->assertModelMissing($row);
        $this->assertSame([0, 0], [RecordField::query()->where('record_module_id', $child->id)->count(), RecordRule::query()->where('record_module_id', $child->id)->count()]);
        $this->assertModelExists($record);
    }

    /**
     * 業務モジュール projects(表示の項目 title を持つ)。
     */
    private function projects(): RecordModule
    {
        $module = $this->recordModule('projects');
        $this->field($module, 'title', attributes: ['is_title' => true]);

        return $module->load('recordFields');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function add(array $input): RecordField
    {
        return app(AddRecordField::class)->execute('projects', $input);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function change(string $fieldCode, array $input): RecordField
    {
        return app(UpdateRecordField::class)->execute('projects', $fieldCode, $input);
    }

    /**
     * @return array<string, mixed>
     */
    private function show(Record $record): array
    {
        $requester = new Requester(null, null);
        $date = CarbonImmutable::parse('2026-10-11');

        return app(RecordQuery::class)->show($requester, app(RecordPageQuery::class)->open($requester, 'projects', $date, RecordModuleAction::Read), $record->id, $date, 'ja');
    }
}
