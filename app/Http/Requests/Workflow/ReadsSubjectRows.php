<?php

namespace App\Http\Requests\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\FlowDefinitionErrors;
use App\Enums\Authz\SelectionConditionKind;
use Closure;

/**
 * 対象者の行 { type, id } の並び(ステップの設定の targets・参照者の referrers = docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 5.1 節・5.2 節)の封筒。
 * 行の形の誤りはその行のキー(<欄>.<添字>)に 6 節の対象者の行の文で返す。相手が在るかは業務処理が確かめる。
 */
trait ReadsSubjectRows
{
    /**
     * 行の規則(<欄>.* に付ける)。
     *
     * @return list<string|Closure>
     */
    private function subjectRowRules(): array
    {
        return ['bail', 'required', 'array', function (string $attribute, mixed $row, Closure $fail): void {
            if (! is_array($row) || self::kindOf($row) === null || self::idOf($row) === null) {
                $fail(FlowDefinitionErrors::SUBJECT_MISSING);
            }
        }];
    }

    /**
     * 行の規則の文言(行の形の誤りの文はどの規則でも同じ。並び全体の規則の文言に掛からないよう、規則ごとに書く)。
     *
     * @return array<string, string>
     */
    private function subjectRowMessages(string $key): array
    {
        $missing = FlowDefinitionErrors::SUBJECT_MISSING;

        return ["{$key}.*.required" => $missing, "{$key}.*.array" => $missing];
    }

    /**
     * 規則が形を確かめた後の行(無ければ空)。
     *
     * @return list<SelectionCondition>
     */
    private function subjectConditions(string $key): array
    {
        $rows = $this->input($key);
        $conditions = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $kind = is_array($row) ? self::kindOf($row) : null;
            $id = is_array($row) ? self::idOf($row) : null;

            if ($kind !== null && $id !== null) {
                $conditions[] = new SelectionCondition($kind, $id);
            }
        }

        return $conditions;
    }

    /**
     * @param  array<mixed>  $row
     */
    private static function kindOf(array $row): ?SelectionConditionKind
    {
        $type = $row['type'] ?? null;

        return is_string($type) ? SelectionConditionKind::tryFrom($type) : null;
    }

    /**
     * @param  array<mixed>  $row
     */
    private static function idOf(array $row): ?int
    {
        $id = $row['id'] ?? null;

        return is_int($id) || (is_string($id) && ctype_digit($id) && strlen($id) <= 18) ? (int) $id : null;
    }
}
