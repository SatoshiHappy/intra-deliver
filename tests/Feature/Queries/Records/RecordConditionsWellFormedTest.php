<?php

namespace Tests\Feature\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Queries\Records\Api\RecordConditions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 規則の条件の公開の口の形だけの確かめ(isWellFormed。docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 3 節の G-6 が呼ぶ)。
 * RecordConditionsTest が 300 行の上限に近いので別のファイルにしてある。
 */
final class RecordConditionsWellFormedTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;

    /**
     * 形だけの確かめ(isWellFormed)の表。期待は 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 8.2 の形(all だけ・式 1〜10 個・式のキーは field・op・value の中・
     * field と op は文字列)から手で書く。
     *
     * @return array<string, array{mixed, bool}>
     */
    public static function wellFormedness(): array
    {
        $expression = ['field' => 'title', 'op' => 'eq', 'value' => 'a'];

        return [
            '式 1 個' => [['all' => [$expression]], true],
            '式 10 個' => [['all' => array_fill(0, 10, $expression)], true],
            '値を持たない式' => [['all' => [['field' => 'title', 'op' => 'empty']]], true],
            '式 0 個' => [['all' => []], false],
            '式 11 個' => [['all' => array_fill(0, 11, $expression)], false],
            'all 以外のキー' => [['any' => [$expression]], false],
            'all のほかのキーを持つ条件' => [['all' => [$expression], 'any' => []], false],
            'リストでない all' => [['all' => ['a' => $expression]], false],
            '配列でない条件' => ['x', false],
            'null' => [null, false],
            '配列でない式' => [['all' => ['x']], false],
            '余分な式のキー' => [['all' => [[...$expression, 'extra' => 1]]], false],
            'field が文字列でない' => [['all' => [['field' => 1, 'op' => 'eq', 'value' => 'a']]], false],
            'field が無い' => [['all' => [['op' => 'eq', 'value' => 'a']]], false],
            'op が文字列でない' => [['all' => [['field' => 'title', 'op' => ['eq'], 'value' => 'a']]], false],
            '2 つ目の式だけ誤り' => [['all' => [$expression, ['field' => 'title']]], false],
        ];
    }

    #[DataProvider('wellFormedness')]
    public function test_is_well_formed_checks_only_the_outer_shape_of_the_condition(mixed $condition, bool $expected): void
    {
        $this->assertSame($expected, $this->conditions()->isWellFormed($condition));
    }

    public function test_a_condition_returned_by_validate_is_well_formed(): void
    {
        $module = $this->recordModule('projects');
        $this->field($module, 'title');
        $this->field($module, 'budget', RecordFieldType::Integer);

        $written = $this->conditions()->validate($module->id, ['all' => [
            ['field' => ' title ', 'op' => 'eq', 'value' => '  申請  '],
            ['field' => 'budget', 'op' => 'empty'],
        ]]);

        $this->assertTrue($this->conditions()->isWellFormed($written));
    }

    private function conditions(): RecordConditions
    {
        return app(RecordConditions::class);
    }
}
