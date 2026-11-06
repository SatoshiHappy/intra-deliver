<?php

namespace App\Actions\Workflow;

use App\Actions\Authz\Api\CreateSelectionSubjectGroup;
use App\Actions\Authz\Api\SelectionCondition;
use App\Enums\Authz\ConditionOperator;
use App\Enums\Authz\SubjectCombination;
use App\Models\Master\Locale;
use App\Queries\Authz\Api\SelectionSubjects;
use App\Support\AccessContext;
use App\Support\DefinitionText;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * ステップの設定の fixed の対象者・参照者・確認する人を指し直す 1 か所(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md の 5 節、
 * 確認する人 = 設計/ワークフロー/案件の運用/定義の管理.md の 2 節)。
 * 前の対象者を書き換えず消さないのは、版の間と案件の写しが共有するため。フローの行の鍵の後に呼ぶ
 * (設計/ワークフロー/ルートの深さ/データモデル/直列化と検査.md の 4.2 節)。
 */
class SelectionSubjectGroupWriter
{
    public function __construct(
        private CreateSelectionSubjectGroup $createSelection,
        private SelectionSubjects $subjectGroups,
    ) {}

    /**
     * @param  int|null  $currentId  今指している対象者
     * @param  list<SelectionCondition>  $conditions  入力の行
     * @param  string  $displayName  作るときの表示名
     * @param  string  $key  入力の行のキー(targets・referrers・confirmers)
     * @param  string  $missingMessage  相手が存在しない行の文言(確認する人は「確認する人を選択してください」= 案件の運用/定義の管理.md の 5 節)
     * @return int|null 指す対象者(行が無ければ null)
     *
     * @throws ValidationException 相手が存在しない行(<key>.<入力の添字>)
     * @throws LogicException 対象者の口が相手のほかの誤りを返したとき
     */
    public function repoint(
        ?int $currentId,
        array $conditions,
        string $displayName,
        string $key,
        string $missingMessage = FlowDefinitionErrors::SUBJECT_MISSING,
    ): ?int {
        $unique = $this->unique($conditions);

        if ($unique === []) {
            return null;
        }

        if ($currentId !== null && $this->hasSameConditions($currentId, $unique)) {
            return $currentId;
        }

        $name = mb_substr($displayName, 0, DefinitionText::NAME_MAX);
        $translations = array_fill_keys(Locale::query()->orderBy('code')->pluck('code')->all(), $name);

        try {
            return $this->createSelection->execute(array_values($unique), $translations);
        } catch (ValidationException $exception) {
            throw $this->remapped($exception, array_keys($unique), $key, $missingMessage);
        }
    }

    /**
     * @param  list<SelectionCondition>  $conditions
     * @return array<int, SelectionCondition> 入力の添字 → 条件(最初に現れた行だけ)
     */
    private function unique(array $conditions): array
    {
        $unique = [];
        $seen = [];

        foreach ($conditions as $index => $condition) {
            if (! isset($seen[self::keyOf($condition)])) {
                $seen[self::keyOf($condition)] = true;
                $unique[$index] = $condition;
            }
        }

        return $unique;
    }

    /**
     * 今の対象者の条件が入力と同じか(ルートの深さ/定義の管理.md の 5.1 節)。
     *
     * @param  array<int, SelectionCondition>  $conditions  重複を除いた入力
     */
    private function hasSameConditions(int $currentId, array $conditions): bool
    {
        $today = CarbonImmutable::today(AccessContext::TIMEZONE);
        $current = $this->subjectGroups
            ->conditionsOf([$currentId], $today, AccessContext::LOCALE_CODE)[$currentId] ?? null;

        if ($current === null || $current['combination'] !== SubjectCombination::Any) {
            return false;
        }

        $keys = [];

        foreach ($current['conditions'] as $condition) {
            if ($condition['comparison'] !== ConditionOperator::Equal) {
                return false;
            }

            $keys[self::keyOf(new SelectionCondition($condition['kind'], $condition['id']))] = true;
        }

        $expected = array_fill_keys(array_map(self::keyOf(...), array_values($conditions)), true);

        return array_diff_key($keys, $expected) === [] && array_diff_key($expected, $keys) === [];
    }

    /**
     * 対象者の口の相手の誤りを、入力の行のキーと文言に付け替える(ルートの深さ/定義の管理.md の 6 節)。
     *
     * @param  list<int>  $inputIndexes  口に渡した添字 → 入力の添字
     *
     * @throws LogicException 相手の誤りのほかの誤りがあるとき
     */
    private function remapped(
        ValidationException $exception,
        array $inputIndexes,
        string $key,
        string $missingMessage,
    ): ValidationException {
        $errors = [];

        foreach (array_keys($exception->errors()) as $errorKey) {
            if (preg_match('/^conditions\.(\d+)\./', $errorKey, $matches) !== 1) {
                throw new LogicException("対象者の口が呼び手の誤りを返しました: {$errorKey}", previous: $exception);
            }

            $errors["{$key}.{$inputIndexes[(int) $matches[1]]}"] = [$missingMessage];
        }

        return ValidationException::withMessages($errors);
    }

    private static function keyOf(SelectionCondition $condition): string
    {
        return "{$condition->kind->value}:{$condition->id}";
    }
}
