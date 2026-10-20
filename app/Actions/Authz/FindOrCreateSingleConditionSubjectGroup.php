<?php

namespace App\Actions\Authz;

use App\Actions\Master\WritesCommonMaster;
use App\Enums\Authz\ConditionOperator;
use App\Enums\Authz\SubjectCombination;
use App\Enums\Authz\SubjectGroupPurpose;
use App\Models\Authz\AuthzSubjectGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * 1 条件の対象者を引くか作る(docs の 設計/共通マスタ/ロールと認可/対象者.md の authz_subject_groups、設計/メニュー/初期データ.md 4 節の対象者の引き方)。
 * 対象者はコードを持たないので、初期データは対象者を表示名でなく条件で引く。
 *
 * 条件 1 つ(除外でない、比較 eq)を受け、その条件だけを持つ用途 policy の対象者(組み方は問わない。用途 selection の対象者は探さない =
 * 対象者.md の「用途 `selection` の規則」)があれば、そのうち id の最も小さいものを返す
 * (同じ式の対象者が 2 つ以上あるときも同じ)。無ければ all の対象者を、受けた表示名で {@see CreateSubjectGroup} で作って返す(式の重複を作らない)。
 * 引くのと作るのを 1 つのトランザクション(共通マスタの勧告ロック)で行い、同時に 2 つ作らない。
 */
class FindOrCreateSingleConditionSubjectGroup
{
    use WritesCommonMaster;

    public function __construct(private CreateSubjectGroup $createSubjectGroup) {}

    /**
     * @param  array<string, array{display_name: string, description?: string|null}>  $translations  作るときの、ロケールのコード → 表示名・説明
     *
     * @throws ValidationException 条件が除外か、比較が eq でないとき(作るときの規則に反するときも)
     */
    public function execute(SubjectConditionInput $condition, array $translations): AuthzSubjectGroup
    {
        if ($condition->isNegated || $condition->operator !== ConditionOperator::Equal) {
            throw ValidationException::withMessages(['condition' => ['取得または作成する条件は、除外でなく比較が eq のものにしてください']]);
        }

        return $this->writeCommonMaster(fn () => AuthzSubjectGroup::query()
            ->where('purpose', SubjectGroupPurpose::Policy)
            ->has('authzSubjectConditions', '=', 1)
            ->whereHas('authzSubjectConditions', fn (Builder $conditions) => $conditions->where($condition->attributes()))
            ->orderBy('id')
            ->first()
            ?? $this->createSubjectGroup->execute(SubjectCombination::All, [$condition], $translations));
    }
}
