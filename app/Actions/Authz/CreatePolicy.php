<?php

namespace App\Actions\Authz;

use App\Actions\Master\WritesCommonMaster;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\SubjectGroupPurpose;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzSubjectGroup;
use App\Queries\Authz\ResourceTypeCatalog;
use Illuminate\Validation\ValidationException;

/**
 * 認可のポリシーを作る(docs の 設計/メニュー/認可との結び付け.md 2.4、設計/共通マスタ/ロールと認可/ポリシーと判定.md の authz_policies)。
 *
 * 止める規則: (リソースタイプ, アクション)の組が列挙({@see ResourceTypeCatalog})にある。リソースを持つリソースグループにも持たないリソースグループにも、
 * どのリソースタイプのポリシーでも置ける(リソースのタイプと揃えることは求めない)。用途 selection の対象者は指さない(docs の
 * 設計/共通マスタ/ロールと認可/対象者.md の authz_subject_groups の「用途 `selection` の規則」)。同じ組の重なりと、指す行の実在は DB が止める。
 */
class CreatePolicy
{
    use WritesCommonMaster;

    public function __construct(private ResourceTypeCatalog $resourceTypes) {}

    /**
     * @throws ValidationException (リソースタイプ, アクション)の組が列挙に無いとき、対象者の用途が selection のとき
     */
    public function execute(int $resourceGroupId, int $subjectGroupId, string $resourceType, string $action, PolicyEffect $effect): AuthzPolicy
    {
        if (! $this->resourceTypes->hasAction($resourceType, $action)) {
            throw ValidationException::withMessages(['action' => ["リソースタイプ {$resourceType} にアクション {$action} はありません"]]);
        }

        return $this->writeCommonMaster(function () use ($resourceGroupId, $subjectGroupId, $resourceType, $action, $effect) {
            if (AuthzSubjectGroup::query()->whereKey($subjectGroupId)->where('purpose', SubjectGroupPurpose::Selection)->exists()) {
                throw ValidationException::withMessages(['authz_subject_group_id' => ['ほかのモジュールの指定のための対象者は、ポリシーに使用できません']]);
            }

            return AuthzPolicy::query()->create([
                'authz_resource_group_id' => $resourceGroupId,
                'authz_subject_group_id' => $subjectGroupId,
                'resource_type' => $resourceType,
                'action' => $action,
                'effect' => $effect,
            ]);
        });
    }
}
