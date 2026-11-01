<?php

namespace App\Queries\Records\Api;

use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordAccessGrantCatalog;
use DateTimeInterface;

/**
 * 業務モジュールの参照の権限によらずレコードを見られる画面を答える口(依存の逆転 = docs の 設計/コード構成/README.md の 3 節。形と束ね方の正本 =
 * 設計/業務データの入れ物/レコードの共通機能/認可と初期データ.md の 1.2 節)。後ろのモジュール(ワークフロー)が実装し、起動(App\Providers)が
 * タグ {@see self::TAG} で登録する。業務データの入れ物は実装を直接呼ばず、束ねる 1 か所(内部の {@see RecordAccessGrantCatalog})を通す。
 */
interface RecordAccessGrants
{
    /** 実装を束ねるコンテナのタグ */
    public const TAG = 'records.record-access-grants';

    /**
     * 業務モジュールの参照の権限によらず、判定を求める者がレコードを見られる画面。1 回の呼び出しで集合を判定する(行ごとに問い合わせない)。
     *
     * @param  list<int>  $recordIds  親の無いレコードの id
     * @return array<int, string> 見られるレコードの id → その画面の URL(見られないレコードは入れない)
     */
    public function grantedViews(Requester $requester, array $recordIds, DateTimeInterface $date): array;
}
