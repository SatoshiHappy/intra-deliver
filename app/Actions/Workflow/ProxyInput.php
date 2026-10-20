<?php

namespace App\Actions\Workflow;

/**
 * 代理の登録の入力(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.9 節)。代理元は {@see RegisterProxy} の引数で受ける。
 * 値は入口から受けたまま(規則は {@see ProxyRules})。
 */
final readonly class ProxyInput
{
    /**
     * @param  int|null  $proxyUserId  代理先(proxy_user_id)
     * @param  string|null  $kind  別(kind = apply・approve)
     * @param  string|null  $flowCode  対象のフローのコード(workflow_flow_code。null = すべてのフロー)
     * @param  string|null  $startDate  開始日(start_date)
     * @param  string|null  $endDate  終了日(end_date = 最後の有効な日。null = 終了日なし)
     */
    public function __construct(
        public ?int $proxyUserId,
        public ?string $kind,
        public ?string $flowCode,
        public ?string $startDate,
        public ?string $endDate,
    ) {}
}
