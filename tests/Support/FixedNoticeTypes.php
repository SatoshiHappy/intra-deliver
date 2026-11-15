<?php

namespace Tests\Support;

use App\Queries\Notice\Api\NoticeTypeEntry;
use App\Queries\Notice\Api\NoticeTypes;

/**
 * テストの中だけで使う通知の種類の一覧の実装(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/種類と作る規則.md の 1 節の口):
 * 決めた種類を決めた順に答える。答えた回数を覚える(束ねるのが 1 要求に 1 回かを確かめるため)。
 */
final class FixedNoticeTypes implements NoticeTypes
{
    public int $calls = 0;

    /**
     * @param  list<NoticeTypeEntry>  $entries
     */
    public function __construct(private readonly array $entries) {}

    public function types(): array
    {
        $this->calls++;

        return $this->entries;
    }
}
