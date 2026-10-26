<?php

namespace App\Actions\Workflow;

use App\Actions\Notice\Api\NoticeMessage;
use App\Actions\Notice\Api\SendNotices;
use App\Enums\Workflow\WorkflowNoticeType;
use App\Models\Workflow\WorkflowProxy;
use App\Queries\Master\Api\EffectiveUsers;
use App\Queries\Workflow\WorkflowNoticeTargets;
use App\Queries\Workflow\WorkflowPeople;
use App\Support\AccessContext;

/**
 * 代理の登録の通知「代理」(docs の 設計/ワークフロー/案件の運用/通知.md の 3 節と 4 節)。代理の登録の業務処理が行を書いた後に、
 * 自分のトランザクションの中で呼ぶ(通知の書き込みが失敗すれば登録ごと巻き戻す)。宛先 = 代理先(登録した人と、今日に有効でない人を除く)。
 * 文の値は作った時の写し(代理元の名前・別・フロー名・開始日・最後の有効な日)。
 */
class SendProxyNotice
{
    public function __construct(
        private SendNotices $sendNotices,
        private EffectiveUsers $effectiveUsers,
        private WorkflowPeople $people,
    ) {}

    /**
     * @param  AccessContext  $context  登録した人の判定の入力(登録した人 = 起こした人、今日 = 宛先の有効を見る日)
     */
    public function execute(AccessContext $context, int $proxyId): void
    {
        $proxy = WorkflowProxy::query()->with('workflowFlow')->findOrFail($proxyId);
        $candidates = array_values(array_diff([$proxy->proxy_user_id], [$context->userId]));
        $recipients = $candidates === [] ? [] : $this->effectiveUsers->idsOn($candidates, $context->date);

        if ($recipients === []) {
            return;
        }

        $principalId = $proxy->principal_user_id;
        $principal = $this->people->users([$principalId], $context->date, $context->localeCode)[$principalId];
        $params = [
            'principal_name' => $principal['name'] ?? $principal['code'],
            'kind' => $proxy->kind->value,
            'flow_name' => $proxy->workflowFlow?->name,
            'start_date' => $proxy->start_date->toDateString(),
            // 行の end_date の前日 = 最後の有効な日(設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.9 節)
            'end_date' => $proxy->end_date?->subDay()->toDateString(),
        ];
        $target = WorkflowNoticeTargets::PROXY_TARGET_TYPE;
        $message = new NoticeMessage(WorkflowNoticeType::Proxy->value, $target, $proxy->id, $context->userId, $params);

        $this->sendNotices->execute($message, $recipients);
    }
}
