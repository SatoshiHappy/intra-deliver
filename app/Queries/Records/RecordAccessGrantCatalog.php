<?php

namespace App\Queries\Records;

use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordAccessGrants;
use DateTimeInterface;
use Illuminate\Container\Attributes\Tag;

/**
 * タグ {@see RecordAccessGrants::TAG} で集めた申請の経路の実装を引く口(docs の 設計/業務データの入れ物/レコードの共通機能/認可と初期データ.md の
 * 1.2 節)。同じレコードに 2 つの画面が返ったら先の実装の画面にする(登録の順で決まるようにするため)。
 */
class RecordAccessGrantCatalog
{
    /**
     * @param  iterable<RecordAccessGrants>  $grants
     */
    public function __construct(#[Tag(RecordAccessGrants::TAG)] private iterable $grants) {}

    /**
     * @param  list<int>  $recordIds  親の無いレコードの id
     * @return array<int, string> 見られるレコードの id → その画面の URL
     */
    public function grantedViews(Requester $requester, array $recordIds, DateTimeInterface $date): array
    {
        $views = [];

        foreach ($recordIds === [] ? [] : $this->grants as $accessRoute) {
            $views += $accessRoute->grantedViews($requester, $recordIds, $date);
        }

        return $views;
    }
}
