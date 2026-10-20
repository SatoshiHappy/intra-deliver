<?php

namespace App\Queries\Workflow;

use App\Support\AccessContext;
use LogicException;

/**
 * 案件を操作する人(処理者と実行者の組。docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 3.2 節)。
 * 値だけを持ち、DB を読まない(処理者が自分の代理元であることは、作る前に業務処理が {@see WorkflowProxies} で確かめる)。
 * 作り方はこのクラスだけに置く(書き込みの部品に置かず、処理者の決め方を 1 か所に保つため)。
 */
final readonly class CaseActor
{
    /**
     * @param  int  $processorUserId  処理者(users.id)
     * @param  int  $executorUserId  実行者(users.id)
     */
    public function __construct(
        public int $processorUserId,
        public int $executorUserId,
    ) {}

    /**
     * 処理者 = 実行者 = その利用者(d に有効と判定済みの利用者の id から作る)。
     */
    public static function of(int $userId): self
    {
        return new self($userId, $userId);
    }

    /**
     * 判定を通った後の作り方: 処理者 = その人(自分か、確かめた代理元・一時保存の持ち主)、実行者 = `$context->userId`。
     *
     * @throws LogicException 利用者がいないとき(判定の不変の条件が破れた)
     */
    public static function forProcessor(int $processorUserId, AccessContext $context): self
    {
        return new self($processorUserId, self::executorOf($context));
    }

    /**
     * 記録する実行者(処理者と違うとき = 代理のときだけ実行者。本人の実行は null = 履歴の executor_user_id と更新履歴の版の契機の実行者)。
     */
    public function recordedExecutorUserId(): ?int
    {
        return $this->executorUserId === $this->processorUserId ? null : $this->executorUserId;
    }

    /**
     * 前処理者(この操作が起こす到達の起点で指定の前処理者 = 処理対象者.md 4.3 の手順 1。代理でも処理者)。計画の入力と申請の画面の先読みが使う 1 か所。
     */
    public function previousUserId(): int
    {
        return $this->processorUserId;
    }

    /**
     * 前処理者の起点の組織を決める、計画に渡す選んだ組織(設計/ワークフロー/案件の運用/案件の操作/README.md の 3.2 節)。
     * 実行者の選んだ組織は、実行者でない処理者の起点の組織を決めない。
     *
     * @param  int  $applicantUserId  案件の申請者
     * @param  int|null  $applicantDepartmentId  案件の担当組織
     */
    public function previousSelectedDepartmentId(
        AccessContext $context,
        int $applicantUserId,
        ?int $applicantDepartmentId,
    ): ?int {
        if ($this->processorUserId === $this->executorUserId) {
            return $context->selectedDepartmentId;
        }

        return $this->processorUserId === $applicantUserId ? $applicantDepartmentId : null;
    }

    /**
     * @throws LogicException 利用者がいないとき
     */
    private static function executorOf(AccessContext $context): int
    {
        return $context->userId ?? throw new LogicException('判定を通った利用者がいない(AccessContext に利用者が無い)');
    }
}
