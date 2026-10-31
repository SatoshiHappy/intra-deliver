<?php

namespace Tests\Feature\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldOption;
use App\Models\Records\RecordModule;
use App\Queries\Records\Api\RecordConditions;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 条件の文の口 {@see RecordConditions::describe()}(図の枝の札 = docs の 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md の 8 節): 規則の画面の要約の条件の部分と
 * 同じ文。同じ入力の組を resources/js/lib/Records/recordDefinitions.test.ts にも置き、2 か所の文のずれを止める(組を変えるときは両方を変える)。
 *
 * @see resources/js/lib/Records/recordDefinitions.test.ts
 */
final class RecordConditionsDescribeTest extends TransactionalTestCase
{
    /**
     * 式 → 文(TS の recordDefinitions.test.ts の CONDITION_TEXTS と同じ組)。
     *
     * @return array<string, array{list<array<string, mixed>>, string}>
     */
    public static function texts(): array
    {
        $one = fn (string $field, string $op, mixed $value = null) => [
            $value === null ? ['field' => $field, 'op' => $op] : ['field' => $field, 'op' => $op, 'value' => $value],
        ];

        return [
            'lt の記号' => [$one('budget', 'lt', 300), '予算(万円) < 300'],
            'ge の記号' => [$one('budget', 'ge', 300), '予算(万円) ≧ 300'],
            'le の小数' => [$one('rate', 'le', '1.5'), '率 ≦ 1.5'],
            'eq の選択肢は名称' => [$one('status', 'eq', 'done'), '状態 が 完了'],
            'ne の選択肢' => [$one('status', 'ne', 'done'), '状態 が 完了 以外'],
            'in は名称を「・」で' => [$one('status', 'in', ['open', 'done']), '状態 が 未着手・完了 のどれか'],
            '無い選択肢はコードのまま' => [$one('status', 'eq', 'hold'), '状態 が hold'],
            'empty' => [$one('owner', 'empty'), '担当者 が空'],
            'not_empty' => [$one('owner', 'not_empty'), '担当者 が空でない'],
            '真偽の真' => [$one('urgent', 'eq', true), '至急 が はい'],
            '真偽の偽' => [$one('urgent', 'eq', false), '至急 が いいえ'],
            '日付の表示の形' => [$one('start', 'ge', '2026-10-16'), '開始日 ≧ 2026/10/16'],
            '日時は Asia/Tokyo の表示の形' => [$one('due', 'gt', '2026-10-11T00:30:00Z'), '期限 > 2026/10/11 09:30'],
            '消えた項目はコードのまま' => [$one('gone', 'eq', 'a'), 'gone が a'],
            '式どうしは「、」' => [[...$one('budget', 'ge', 300), ...$one('status', 'eq', 'done')], '予算(万円) ≧ 300、状態 が 完了'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $expressions
     */
    #[DataProvider('texts')]
    public function test_the_text_is_the_same_as_the_rule_summary_of_the_screen(array $expressions, string $text): void
    {
        $this->assertSame($text, $this->conditions()->describe($this->module()->id, ['all' => $expressions]));
    }

    public function test_a_field_name_is_read_from_the_current_definition(): void
    {
        $module = $this->module();
        RecordField::query()->where('record_module_id', $module->id)->where('code', 'budget')->update(['name' => '予算']);

        $condition = ['all' => [['field' => 'budget', 'op' => 'lt', 'value' => 300]]];

        $this->assertSame('予算 < 300', $this->conditions()->describe($module->id, $condition));
    }

    public function test_a_malformed_condition_or_a_details_module_is_the_callers_mistake(): void
    {
        $module = $this->module();
        $child = RecordModule::factory()->create(['code' => 'rows', 'parent_record_module_id' => $module->id]);
        $describe = fn (int $moduleId, array $expressions) => fn () => $this->conditions()
            ->describe($moduleId, ['all' => $expressions]);

        $this->assertThrows($describe($module->id, []), LogicException::class);
        $this->assertThrows($describe($module->id, [['field' => 'budget', 'op' => 'about']]), LogicException::class);
        $this->assertThrows($describe($child->id, [['field' => 'x', 'op' => 'empty']]), ModelNotFoundException::class);
    }

    /**
     * 文の組が使う項目(TS の組の業務モジュールと同じ名前・型・選択肢)。
     */
    private function module(): RecordModule
    {
        $module = RecordModule::factory()->create(['code' => 'projects', 'name' => 'プロジェクト']);
        $fields = [
            ['budget', '予算(万円)', RecordFieldType::Integer], ['rate', '率', RecordFieldType::Decimal],
            ['status', '状態', RecordFieldType::Choice], ['owner', '担当者', RecordFieldType::User],
            ['urgent', '至急', RecordFieldType::Boolean], ['start', '開始日', RecordFieldType::Date],
            ['due', '期限', RecordFieldType::Datetime],
        ];

        foreach ($fields as $index => [$code, $name, $type]) {
            $field = RecordField::factory()->create([
                'record_module_id' => $module->id, 'code' => $code, 'name' => $name, 'type' => $type,
                'sort_key' => $index,
            ]);

            if ($type === RecordFieldType::Choice) {
                foreach ([['open', '未着手'], ['done', '完了']] as $sortKey => [$optionCode, $optionName]) {
                    RecordFieldOption::factory()->create([
                        'record_field_id' => $field->id, 'code' => $optionCode, 'name' => $optionName,
                        'sort_key' => $sortKey,
                    ]);
                }
            }
        }

        return $module;
    }

    private function conditions(): RecordConditions
    {
        return app(RecordConditions::class);
    }
}
