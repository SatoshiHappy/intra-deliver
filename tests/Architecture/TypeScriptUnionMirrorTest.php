<?php

namespace Tests\Architecture;

use App\Enums\Records\RecordComparisonOperator;
use App\Enums\Records\RecordConditionOperator;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordRuleKind;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use BackedEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 画面の手書きのユニオン(TypeScript の型の別名)と、値の正本の PHP の値つき列挙が同じ値(docs の 設計/コード構成/検査/README.md 6.2)。
 * 列挙から型を生成する道具を足さず、値を比べる(依存を増やさない。食い違いは片側にだけある値を文言に出す)。
 *
 * 自己証明: 読み(型の別名の値)と差分は、片側にだけ値がある固定の文字列を渡して両方向の指摘になること、一致する文字列で指摘にならないことを確かめる。
 */
final class TypeScriptUnionMirrorTest extends TestCase
{
    /** ワークフローの列挙を写すユニオンのファイル */
    private const WORKFLOW_VALUES = 'resources/js/types/workflowValues.ts';

    /** 片側にだけ値がある型の別名(自己証明) */
    private const UNION_SOURCE = <<<'TS'
export type Sample =
    | 'open'
    | 'done';
TS;

    /**
     * 型の別名 → 型の別名・それを書くファイル・列挙。
     *
     * @return array<string, array{string, string, class-string<BackedEnum>}>
     */
    public static function unions(): array
    {
        return [
            'WorkflowCaseState' => ['WorkflowCaseState', self::WORKFLOW_VALUES, WorkflowCaseState::class],
            'WorkflowCompletionReason' => ['WorkflowCompletionReason', self::WORKFLOW_VALUES, WorkflowCompletionReason::class],
            'WorkflowNodeState' => ['WorkflowNodeState', self::WORKFLOW_VALUES, WorkflowNodeState::class],
            'WorkflowNodeKind' => ['WorkflowNodeKind', self::WORKFLOW_VALUES, WorkflowNodeKind::class],
            'WorkflowAction' => ['WorkflowAction', self::WORKFLOW_VALUES, WorkflowAction::class],
            'RecordFieldType' => ['RecordFieldType', 'resources/js/types/records.ts', RecordFieldType::class],
            'RecordConditionOperator' => ['RecordConditionOperator', 'resources/js/types/records.ts', RecordConditionOperator::class],
            'RecordRuleKind' => ['RecordRuleKind', 'resources/js/types/records.ts', RecordRuleKind::class],
            'RecordComparisonOperator' => ['RecordComparisonOperator', 'resources/js/types/records.ts', RecordComparisonOperator::class],
        ];
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     */
    #[DataProvider('unions')]
    public function test_the_type_script_union_lists_exactly_the_enum_values(string $alias, string $file, string $enum): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$file);

        $this->assertIsString($source);
        $this->assertSame(
            [],
            self::differences(self::unionValues($source, $alias), array_map(fn (BackedEnum $case): string => (string) $case->value, $enum::cases())),
            "{$file} の {$alias} と {$enum} が食い違う",
        );
    }

    public function test_detects_a_value_only_in_the_union_and_a_value_only_in_the_enum(): void
    {
        $this->assertSame(['open', 'done'], self::unionValues(self::UNION_SOURCE, 'Sample'));
        $this->assertSame(
            ['PHP にだけある値: hold', 'TS にだけある値: done'],
            self::differences(self::unionValues(self::UNION_SOURCE, 'Sample'), ['open', 'hold']),
        );
        $this->assertSame([], self::differences(self::unionValues(self::UNION_SOURCE, 'Sample'), ['done', 'open']));
    }

    /**
     * 型の別名(type <名前> = 'a' | 'b' …;)の文字列の値。
     *
     * @return list<string>
     */
    private static function unionValues(string $source, string $alias): array
    {
        if (preg_match('/^(?:export )?type '.$alias.' =([^;]*);/m', $source, $definition) !== 1) {
            throw new RuntimeException("型の別名 {$alias} が無い");
        }

        preg_match_all("/'([^']*)'/", $definition[1], $values);

        return $values[1];
    }

    /**
     * 片側にだけある値の指摘(無ければ空)。
     *
     * @param  list<string>  $typeScript
     * @param  list<string>  $php
     * @return list<string>
     */
    private static function differences(array $typeScript, array $php): array
    {
        return [
            ...array_map(fn (string $value): string => "PHP にだけある値: {$value}", array_values(array_diff($php, $typeScript))),
            ...array_map(fn (string $value): string => "TS にだけある値: {$value}", array_values(array_diff($typeScript, $php))),
        ];
    }
}
