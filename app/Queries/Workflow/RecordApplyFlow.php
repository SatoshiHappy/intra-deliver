<?php

namespace App\Queries\Workflow;

/**
 * 既存のデータからの申請で選べるフロー({@see RecordApplyFlows::flowsFor()} が返す値。docs の
 * 設計/ワークフロー/申請の周辺/既存のデータからの申請.md の 1 節)。
 */
final readonly class RecordApplyFlow
{
    /**
     * @param  string  $code  フローのコード(申請の画面のルートの引数)
     * @param  string|null  $description  フローの説明
     */
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public ?string $description,
    ) {}
}
