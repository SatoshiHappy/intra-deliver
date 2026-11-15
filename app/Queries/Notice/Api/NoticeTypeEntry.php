<?php

namespace App\Queries\Notice\Api;

/**
 * 通知の種類 1 つ({@see NoticeTypes::types()} の答えの値。docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/種類と作る規則.md の 1 節)。
 */
final readonly class NoticeTypeEntry
{
    /**
     * @param  string  $type  種類「モジュール.種類」(表 notices の type。例 workflow.process_request)
     * @param  bool  $switchable  利用者が受け取るかを切り替えられるか(通知が無くても中身を見る場所があるか)
     */
    public function __construct(
        public string $type,
        public bool $switchable,
    ) {}
}
