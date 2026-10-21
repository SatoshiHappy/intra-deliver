<?php

namespace App\Queries\Records;

use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordApplyEntries;
use App\Queries\Records\Api\RecordApplyEntry;
use DateTimeInterface;
use Illuminate\Container\Attributes\Tag;

/**
 * レコードから申請できる入口の全体 = タグ {@see RecordApplyEntries::TAG} で束ねた実装を登録の順に呼び、結果をつなぐ 1 か所(内部。公開の口に出さない。
 * docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請.md の 2 節)。実装が 0 件なら入口は無い。
 */
class RecordApplyEntryCatalog
{
    /**
     * @param  iterable<RecordApplyEntries>  $recordApplyEntries
     */
    public function __construct(#[Tag(RecordApplyEntries::TAG)] private iterable $recordApplyEntries) {}

    /**
     * @return list<RecordApplyEntry>
     */
    public function entriesFor(Requester $requester, int $recordId, DateTimeInterface $date): array
    {
        $entries = [];

        foreach ($this->recordApplyEntries as $recordApplyEntries) {
            $entries = [...$entries, ...$recordApplyEntries->entriesFor($requester, $recordId, $date)];
        }

        return $entries;
    }
}
