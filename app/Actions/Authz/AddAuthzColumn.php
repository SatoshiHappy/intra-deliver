<?php

namespace App\Actions\Authz;

use App\Actions\Master\Api\CommonMasterWriteLock;
use App\Enums\Authz\SubjectColumnKind;
use App\Enums\Authz\SubjectCombination;
use App\Enums\Authz\SubjectGroupPurpose;
use App\Models\Authz\AuthzSubjectGroup;
use App\Queries\Authz\SubjectColumnNames;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * 認可設定の列(対象者)を足す(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 3.1)。
 * 対象者の作り方(カテゴリを含む)は {@see CreateSubjectGroup} に任せ、ここは相手の確かめ({@see SubjectColumnNames})と重複の拒否だけを持つ。
 */
class AddAuthzColumn
{
    use CommonMasterWriteLock;

    public function __construct(
        private SubjectColumnNames $columnNames,
        private CreateSubjectGroup $createSubjectGroup,
    ) {}

    /**
     * @throws ValidationException 相手が無い・d に有効でないとき、同じ条件の列があるとき(どちらも target_id)
     */
    public function execute(SubjectColumnKind $kind, int $targetId, DateTimeInterface $date): AuthzSubjectGroup
    {
        return $this->writeCommonMaster(function () use ($kind, $targetId, $date) {
            $names = $this->columnNames->namesOn($kind, $targetId, $date)
                ?? throw ValidationException::withMessages(['target_id' => ['選択した相手は今日の時点で有効ではありません']]);
            $condition = $this->conditionOf($kind, $targetId);

            if ($this->hasColumnWith($condition)) {
                throw ValidationException::withMessages(['target_id' => ['同じ条件の列がすでにあります']]);
            }

            return $this->createSubjectGroup->execute(
                SubjectCombination::All,
                [$condition],
                array_map(fn (string $name) => ['display_name' => $name], $names),
            );
        });
    }

    private function conditionOf(SubjectColumnKind $kind, int $targetId): SubjectConditionInput
    {
        return match ($kind) {
            SubjectColumnKind::User => new SubjectConditionInput(userId: $targetId),
            SubjectColumnKind::Role => new SubjectConditionInput(roleId: $targetId),
            SubjectColumnKind::Department => new SubjectConditionInput(departmentId: $targetId),
            SubjectColumnKind::CompanyPost => new SubjectConditionInput(companyPostId: $targetId),
        };
    }

    /**
     * その条件 1 つだけを持つ用途 policy の対象者(列)があるか(組み方は問わない)。
     */
    private function hasColumnWith(SubjectConditionInput $condition): bool
    {
        return AuthzSubjectGroup::query()
            ->where('purpose', SubjectGroupPurpose::Policy)
            ->has('authzSubjectConditions', '=', 1)
            ->whereHas('authzSubjectConditions', fn (Builder $rows) => $rows->where($condition->attributes()))
            ->exists();
    }
}
