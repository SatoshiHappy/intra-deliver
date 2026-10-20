<?php

namespace App\Queries\Authz;

use App\Models\Authz\AuthzPolicy;

/**
 * 列の削除の確かめに使う件数と指紋を作る 1 か所(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 3.2)。
 * 画面の props と列を削除する業務処理が同じこれを使い、値が食い違わないようにする。
 *
 * @phpstan-type PolicyFingerprint array{policyCount: int, fingerprint: string}
 */
class SubjectGroupPolicyFingerprints
{
    /**
     * 対象者ごとの件数と指紋(渡した対象者のすべて。ポリシーが無ければ件数 0 と空の一覧の指紋)。1 回の問い合わせで引く。
     *
     * @param  list<int>  $subjectGroupIds
     * @return array<int, PolicyFingerprint>
     */
    public function of(array $subjectGroupIds): array
    {
        $policies = AuthzPolicy::query()->whereIn('authz_subject_group_id', $subjectGroupIds)->get()
            ->groupBy('authz_subject_group_id');
        $fingerprints = [];

        foreach ($subjectGroupIds as $subjectGroupId) {
            $entries = array_values($policies->get($subjectGroupId, collect())->map(fn (AuthzPolicy $policy) => [
                $policy->resource_type,
                $policy->authz_resource_group_id,
                $policy->action,
                $policy->effect->value,
            ])->all());
            usort($entries, fn (array $a, array $b) => strcmp($a[0], $b[0])
                ?: ($a[1] <=> $b[1])
                ?: strcmp($a[2], $b[2]));
            $fingerprints[$subjectGroupId] = [
                'policyCount' => count($entries),
                'fingerprint' => hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR)),
            ];
        }

        return $fingerprints;
    }
}
