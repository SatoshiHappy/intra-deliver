<?php

namespace App\Actions\Authz;

/**
 * 認可設定の格子のセル 1 つ = ポリシーの一意の組(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 2.2): 行のリソースグループ・列の対象者・
 * 格子の種類(リソースタイプ)・列のアクション。セルを切り替える業務処理 {@see ChangeAuthzCell} に渡す。
 */
final readonly class AuthzCellInput
{
    public function __construct(
        public int $resourceGroupId,
        public int $subjectGroupId,
        public string $resourceType,
        public string $action,
    ) {}

    /**
     * authz_policies のその組の列の値。
     *
     * @return array{authz_resource_group_id: int, authz_subject_group_id: int, resource_type: string, action: string}
     */
    public function policyKey(): array
    {
        return [
            'authz_resource_group_id' => $this->resourceGroupId,
            'authz_subject_group_id' => $this->subjectGroupId,
            'resource_type' => $this->resourceType,
            'action' => $this->action,
        ];
    }
}
