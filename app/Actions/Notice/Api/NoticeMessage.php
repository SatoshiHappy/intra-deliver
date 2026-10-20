<?php

namespace App\Actions\Notice\Api;

/**
 * 送る通知の中身({@see SendNotices} の入力。宛先ごとに同じ中身の行を書く)。形の検査は {@see SendNotices} が行う。
 * 種類の値の一覧と params のキーは送るモジュールが持つ(docs の 設計/ワークフロー/案件の運用/通知.md の 3 節)。
 */
final readonly class NoticeMessage
{
    /**
     * @param  string  $type  種類「モジュール.種類」(例 workflow.process_request)
     * @param  string|null  $targetType  対象の種類(例 workflow-case)。null = 対象なし
     * @param  int|null  $targetId  対象の id(対象の種類と一緒に null か値)
     * @param  int|null  $actorUserId  起こした人(実際に操作した人)。null = 人によらない通知
     * @param  array<string, string|int|bool|null>  $params  文を作る値(作った時の写し)。空でよい
     */
    public function __construct(
        public string $type,
        public ?string $targetType,
        public ?int $targetId,
        public ?int $actorUserId,
        public array $params,
    ) {}
}
