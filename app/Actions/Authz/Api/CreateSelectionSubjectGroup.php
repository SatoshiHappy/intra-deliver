<?php

namespace App\Actions\Authz\Api;

use App\Actions\Authz\CreateSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Actions\Master\WritesCommonMaster;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Authz\SubjectCombination;
use App\Enums\Authz\SubjectGroupPurpose;
use App\Models\Authz\Role;
use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\Locale;
use App\Models\Master\User;
use Illuminate\Validation\ValidationException;

/**
 * ほかのモジュールの指定(ワークフローの処理対象者・参照者)が指す、用途 selection の専用の対象者を作る
 * (docs の 設計/共通マスタ/ロールと認可/対象者.md の authz_subject_groups の「用途 `selection` の規則」)。
 * 組み方 any、条件は {@see SelectionCondition} ごとに 1 つ(比較 eq、除外・入れ子なし)。中で {@see CreateSubjectGroup} を使う。
 * 認可の判定をしない(定義の業務処理。段 2 は初期データだけが呼ぶ)。
 *
 * Authz の公開の口(主体・認可・検証の範囲・ロック・原子性・結果・失敗の正本 = docs の 設計/ワークフロー/業務データとの接続/公開の口.md 8.1 の表)。
 * 共通マスタの勧告ロックを取る({@see WritesCommonMaster})。自分のトランザクションを持ち、外側のトランザクションの中では savepoint で外側に含まれる。
 */
class CreateSelectionSubjectGroup
{
    use WritesCommonMaster;

    public function __construct(private CreateSubjectGroup $createSubjectGroup) {}

    /**
     * @param  list<SelectionCondition>  $conditions  1 件以上
     * @param  array<string, string>  $translations  表示名(locales のロケールのコード → 空でない名前。1 件以上)
     * @return int 作った対象者の id(用途 selection)
     *
     * @throws ValidationException 条件が無い(キー conditions。ほかの誤りより先に単独で返る)。その後は勧告ロックの中で、相手の無い条件(conditions.<添字>.id。すべての添字)と
     *                             表示名の誤り(translations。表示名が無い → 無いロケール → 空の名前の順で最初の 1 つ)を集めて一度に返す
     */
    public function execute(array $conditions, array $translations): int
    {
        if ($conditions === []) {
            throw ValidationException::withMessages(['conditions' => ['条件を 1 件以上指定してください']]);
        }

        return $this->writeCommonMaster(function () use ($conditions, $translations) {
            $errors = [...$this->missingTargetErrors($conditions), ...$this->translationErrors($translations)];

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            return $this->createSubjectGroup->execute(
                SubjectCombination::Any,
                array_map(fn (SelectionCondition $condition) => $this->conditionInput($condition), $conditions),
                array_map(fn (string $name) => ['display_name' => $name], $translations),
                purpose: SubjectGroupPurpose::Selection,
            )->id;
        });
    }

    /**
     * 相手が無い条件の誤り(キー conditions.<添字>.id)。
     *
     * @param  list<SelectionCondition>  $conditions
     * @return array<string, list<string>>
     */
    private function missingTargetErrors(array $conditions): array
    {
        $existing = [];

        foreach (SelectionConditionKind::cases() as $kind) {
            $ids = array_map(fn (SelectionCondition $condition) => $condition->id, array_filter($conditions, fn (SelectionCondition $condition) => $condition->kind === $kind));
            $existing[$kind->value] = $ids === [] ? [] : $this->existingIds($kind, array_values($ids));
        }

        $errors = [];

        foreach ($conditions as $index => $condition) {
            if (! in_array($condition->id, $existing[$condition->kind->value], true)) {
                $errors["conditions.{$index}.id"] = ["条件の相手がありません: {$condition->kind->value} {$condition->id}"];
            }
        }

        return $errors;
    }

    /**
     * 種類の表にある id。
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function existingIds(SelectionConditionKind $kind, array $ids): array
    {
        $query = match ($kind) {
            SelectionConditionKind::User => User::query(),
            SelectionConditionKind::Department => Department::query(),
            SelectionConditionKind::CompanyPost => CompanyPost::query(),
            SelectionConditionKind::Role => Role::query(),
        };

        return array_values(array_map('intval', $query->whereKey($ids)->pluck('id')->all()));
    }

    /**
     * 表示名の誤り(キー translations)。
     *
     * @param  array<string, string>  $translations
     * @return array<string, list<string>>
     */
    private function translationErrors(array $translations): array
    {
        if ($translations === []) {
            return ['translations' => ['表示名を 1 件以上指定してください']];
        }

        $codes = array_map('strval', array_keys($translations));
        $unknown = array_diff($codes, Locale::query()->whereIn('code', $codes)->pluck('code')->all());

        if ($unknown !== []) {
            return ['translations' => ['無いロケールです: '.implode(', ', $unknown)]];
        }

        if (in_array('', $translations, true)) {
            return ['translations' => ['表示名を空にできません']];
        }

        return [];
    }

    private function conditionInput(SelectionCondition $condition): SubjectConditionInput
    {
        return match ($condition->kind) {
            SelectionConditionKind::User => new SubjectConditionInput(userId: $condition->id),
            SelectionConditionKind::Department => new SubjectConditionInput(departmentId: $condition->id),
            SelectionConditionKind::CompanyPost => new SubjectConditionInput(companyPostId: $condition->id),
            SelectionConditionKind::Role => new SubjectConditionInput(roleId: $condition->id),
        };
    }
}
