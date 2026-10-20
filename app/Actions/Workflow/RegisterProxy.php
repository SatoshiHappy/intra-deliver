<?php

namespace App\Actions\Workflow;

use App\Models\Workflow\WorkflowProxy;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 代理の登録(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.9 節)。自分の代理と代理の管理(認可はルート)の両方の入口がこれを呼ぶ(規則を
 * {@see ProxyRules} の 1 か所に保つため)。案件の行の鍵を取らない(設計/ワークフロー/案件の運用/データモデル/README.md の 4.2 節)。
 */
class RegisterProxy
{
    public function __construct(private ProxyRules $rules) {}

    /**
     * @param  int|null  $principalUserId  代理元(自分の代理 = 自分、代理の管理 = 入力 principal_user_id)
     * @return int 書いた代理の行の id
     *
     * @throws AuthorizationException ゲストのとき
     * @throws ValidationException 規則に外れたとき
     */
    public function execute(AccessContext $context, ?int $principalUserId, ProxyInput $input): int
    {
        $registeredBy = $context->userId ?? throw new AuthorizationException;

        return DB::transaction(function () use ($context, $principalUserId, $input, $registeredBy) {
            $row = $this->rules->validated($context, $principalUserId, $input);

            return WorkflowProxy::query()->create([...$row, 'registered_by_user_id' => $registeredBy])->id;
        });
    }
}
