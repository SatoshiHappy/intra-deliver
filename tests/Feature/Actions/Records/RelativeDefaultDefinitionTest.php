<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\AddRecordField;
use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\UpdateRecordField;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RelativeDefault;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BuildsRecordModules;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 項目の定義の相対の既定値と、複数行の最大の文字数の上限(docs の 設計/業務データの入れ物/項目と規則/README.md 1 節の最後・3 節、
 * 設計/業務データの入れ物/項目と規則/規則と業務処理/画面と検査.md 13 節の「既定値」と「定義の変更」)。業務処理を直接呼ぶ
 * (HTTP の要求と同じ失敗になることは RelativeDefaultControllerTest)。
 */
final class RelativeDefaultDefinitionTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use CatchesValidationErrors;

    private const DURATION_ERROR = '登録の時からの期間は 0〜999 の日・か月・年で指定してください';

    /** 日付の項目の入力(既定値は各テストが足す) */
    private const HELD_ON = ['code' => 'held_on', 'name' => '開催日', 'type' => 'date'];

    /** 文字列の項目の入力 */
    private const MEMO = ['code' => 'memo', 'name' => 'メモ', 'type' => 'string'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordModule('meetings');
    }

    /**
     * 日付・日時の項目が拒む既定値のオブジェクト(期間の形の誤り)。
     *
     * @return array<string, array{mixed}>
     */
    public static function malformedRelativeDefaults(): array
    {
        return [
            '1000 日' => [['relative' => 'P1000D']],
            '週' => [['relative' => 'P1W']],
            '時間' => [['relative' => 'PT1H']],
            '負の数' => [['relative' => 'P-1D']],
            '小文字の単位' => [['relative' => 'P6m']],
            '先頭の 0' => [['relative' => 'P06M']],
            '空文字' => [['relative' => '']],
            '数の期間' => [['relative' => 6]],
            'キーの違うオブジェクト' => [['after' => 'P6M']],
            'relative 以外のキーを足したオブジェクト' => [['relative' => 'P6M', 'note' => 'x']],
            '空のオブジェクト' => [[]],
        ];
    }

    #[TestWith(['date'])]
    #[TestWith(['datetime'])]
    public function test_keeps_a_relative_default_and_reads_it_as_the_value_type(string $type): void
    {
        $added = $this->add([...self::HELD_ON, 'type' => $type, 'default_value' => ['relative' => ' P6M ']]);
        $reread = RecordField::query()->findOrFail($added->id)->default_value;

        $this->assertInstanceOf(RelativeDefault::class, $reread);
        $this->assertSame('P6M', $reread->duration);
    }

    #[DataProvider('malformedRelativeDefaults')]
    public function test_rejects_a_malformed_relative_default_and_writes_nothing(mixed $defaultValue): void
    {
        $fields = RecordField::query()->count();

        $errors = $this->validationErrors(fn () => $this->add([...self::HELD_ON, 'default_value' => $defaultValue]));

        $this->assertSame(['default_value' => self::DURATION_ERROR], $errors);
        $this->assertSame($fields, RecordField::query()->count());
    }

    /**
     * 日付・日時でない型のオブジェクトと値の型(業務処理を直接呼ぶ呼び手が渡せる)は、今の既定値の型の誤りと同じ文言。
     * 足すときも変えるときも同じで、何も書かれない。
     *
     * @param  array<string, mixed>  $extra
     */
    #[TestWith(['string', [], '既定値は文字列で入力してください'])]
    #[TestWith(['integer', [], '既定値は整数で入力してください'])]
    #[TestWith(['choice', ['options' => [['code' => 'open', 'name' => '未着手']]], '既定値の値が正しくありません'])]
    #[TestWith(['boolean', [], '既定値は真偽(true・false)で指定してください'])]
    public function test_rejects_a_relative_default_on_another_type(string $type, array $extra, string $message): void
    {
        $input = [...self::MEMO, 'type' => $type, ...$extra];
        $relative = RelativeDefault::tryFromDuration('P6M');
        $this->add([...$input, 'code' => 'saved', 'name' => '保存済み']);
        $before = RecordField::query()->orderBy('id')->get()->toArray();

        foreach ([['relative' => 'P6M'], $relative] as $defaultValue) {
            $added = $this->validationErrors(fn () => $this->add([...$input, 'default_value' => $defaultValue]));
            $changed = $this->validationErrors(fn () => $this->change('saved', ['default_value' => $defaultValue]));

            $this->assertSame(['default_value' => $message], $added);
            $this->assertSame(['default_value' => $message], $changed);
        }

        $this->assertSame($before, RecordField::query()->orderBy('id')->get()->toArray());
    }

    public function test_changing_other_attributes_keeps_the_relative_default_and_it_can_become_a_fixed_value(): void
    {
        $this->add([...self::HELD_ON, 'default_value' => ['relative' => 'P30D']]);

        $renamed = $this->change('held_on', ['name' => '開催予定日'])->default_value;
        $this->assertInstanceOf(RelativeDefault::class, $renamed);
        $this->assertSame('P30D', $renamed->duration);

        $this->assertSame('2026-12-01', $this->change('held_on', ['default_value' => '2026-12-01'])->default_value);
        $this->assertNull($this->change('held_on', ['default_value' => null])->default_value);
    }

    public function test_a_multiline_field_accepts_a_max_length_up_to_20000(): void
    {
        $multiline = [...self::MEMO, 'is_multiline' => true];

        $field = $this->add([...$multiline, 'max_length' => 20000]);
        $tooLong = [...$multiline, 'code' => 'memo2', 'name' => 'メモ2', 'max_length' => 20001];
        $singleLine = [...self::MEMO, 'code' => 'memo3', 'name' => 'メモ3', 'max_length' => 1001];

        $this->assertSame(20000, $field->max_length);
        $this->assertSame(
            ['max_length' => '最大の文字数は 1〜20000 にしてください'],
            $this->validationErrors(fn () => $this->add($tooLong)),
        );
        $this->assertSame(
            ['max_length' => '最大の文字数は 1〜1000 にしてください'],
            $this->validationErrors(fn () => $this->add($singleLine)),
        );
    }

    public function test_a_multiline_value_is_limited_by_the_max_length_and_by_4000_without_it(): void
    {
        $module = $this->recordModule('minutes');
        $minutes = $this->field($module, 'minutes', attributes: ['is_multiline' => true, 'max_length' => 20000]);
        $this->field($module, 'summary', attributes: ['is_multiline' => true]);

        $record = $this->createRecord(['minutes' => str_repeat('あ', 20000), 'summary' => str_repeat('い', 4000)]);
        $tooLong = ['minutes' => str_repeat('あ', 20001), 'summary' => str_repeat('い', 4001)];

        $this->assertSame(str_repeat('あ', 20000), $record->field_values[$minutes->id] ?? null);
        $this->assertSame([
            'values.minutes' => 'minutesは 20000 文字以内で入力してください',
            'values.summary' => 'summaryは 4000 文字以内で入力してください',
        ], $this->validationErrors(fn () => $this->createRecord($tooLong)));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function add(array $input): RecordField
    {
        return app(AddRecordField::class)->execute('meetings', $input);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function change(string $fieldCode, array $input): RecordField
    {
        return app(UpdateRecordField::class)->execute('meetings', $fieldCode, $input);
    }

    /**
     * 業務モジュール minutes にレコードを登録する。
     *
     * @param  array<string, mixed>  $values
     */
    private function createRecord(array $values): Record
    {
        $id = app(CreateRecord::class)->execute($this->contextFor(), 'minutes', new RecordInput($values, []));

        return Record::query()->findOrFail($id);
    }
}
