<?php

namespace App\Actions\Authz;

use App\Actions\Master\Api\CommonMasterWriteLock;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\SubjectGroupPurpose;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzSubjectGroup;
use App\Queries\Authz\AuthzEditGuard;
use App\Queries\Authz\OverviewTree;
use App\Queries\Authz\ResourceTypeCatalog;
use App\Support\AccessContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 認可設定のセルを from から to へ切り替える(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 2.3)。
 * 画面を開いた後の別の人の変更は from との食い違いで止め、守り({@see AuthzEditGuard})が外れれば全体を巻き戻す。
 */
class ChangeAuthzCell
{
    use CommonMasterWriteLock;

    /** 今の直接の設定が画面の見ていたものと違うときの文 */
    public const STALE_MESSAGE = 'ほかの利用者が変更しました。画面を再読み込みしてください。';

    public function __construct(
        private ResourceTypeCatalog $resourceTypes,
        private OverviewTree $tree,
        private CreatePolicy $createPolicy,
        private UpdatePolicyEffect $updatePolicyEffect,
        private DeletePolicy $deletePolicy,
        private AuthzEditGuard $guard,
    ) {}

    /**
     * @param  PolicyEffect|null  $from  画面が見ていた直接の設定(継承・設定なしは null)
     * @param  PolicyEffect|null  $to  変えた後の直接の設定(null = 未設定に戻す)
     *
     * @throws ModelNotFoundException<AuthzResourceGroup|AuthzSubjectGroup> リソースグループ・対象者が無いとき
     * @throws ValidationException 相手が規則に反するとき、今の設定が from と違うとき、守りに外れたとき
     */
    public function execute(AccessContext $context, AuthzCellInput $cell, ?PolicyEffect $from, ?PolicyEffect $to): void
    {
        $this->writeCommonMaster(function () use ($context, $cell, $from, $to) {
            $this->assertCell($cell);
            $policy = AuthzPolicy::query()->where($cell->policyKey())->first();

            if ($policy?->effect !== $from) {
                throw ValidationException::withMessages(['cell' => [self::STALE_MESSAGE]]);
            }

            if ($from === $to) {
                return;
            }

            $this->write($cell, $policy, $to);
            $this->guard->assertKept($context, 'cell');
        });
    }

    /**
     * セルの相手を確かめる(リソースグループ・対象者の実在 → 対象者の用途 → 種類とアクション → 種類の格子の行)。
     *
     * @throws ModelNotFoundException<AuthzResourceGroup|AuthzSubjectGroup>
     * @throws ValidationException
     */
    private function assertCell(AuthzCellInput $cell): void
    {
        AuthzResourceGroup::query()->findOrFail($cell->resourceGroupId);
        $subjectGroup = AuthzSubjectGroup::query()->findOrFail($cell->subjectGroupId);

        if ($subjectGroup->purpose === SubjectGroupPurpose::Selection) {
            throw ValidationException::withMessages(['subject_group_id' => ['ほかのモジュールの指定のための対象者は、ポリシーに使用できません']]);
        }

        if (! $this->resourceTypes->hasResourceType($cell->resourceType)) {
            throw ValidationException::withMessages(['type' => ["リソースの種類 {$cell->resourceType} はありません"]]);
        }

        if (! $this->resourceTypes->hasAction($cell->resourceType, $cell->action)) {
            $message = "リソースの種類 {$cell->resourceType} にアクション {$cell->action} はありません";

            throw ValidationException::withMessages(['action' => [$message]]);
        }

        if (! $this->tree->isRowOf($cell->resourceType, $cell->resourceGroupId)) {
            throw ValidationException::withMessages(['resource_group_id' => ['このリソースの種類の格子の行ではありません']]);
        }
    }

    /**
     * 未設定から設定 = 作成、設定から未設定 = 削除、許可と禁止の入れ替え = エフェクトの変更(from と to は違う)。
     */
    private function write(AuthzCellInput $cell, ?AuthzPolicy $policy, ?PolicyEffect $to): void
    {
        if ($policy === null) {
            $effect = $to ?? throw new LogicException('未設定から未設定への切替は呼び手が除く');
            $this->createPolicy->execute(
                $cell->resourceGroupId,
                $cell->subjectGroupId,
                $cell->resourceType,
                $cell->action,
                $effect,
            );
        } elseif ($to === null) {
            $this->deletePolicy->execute($policy->id);
        } else {
            $this->updatePolicyEffect->execute($policy->id, $to);
        }
    }
}
