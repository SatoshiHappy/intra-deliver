<?php

namespace App\Queries\Records\Api;

use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordHoldNoticeCatalog;
use DateTimeInterface;

/**
 * 持ち主が業務データの入れ物の画面に出す案内の口(依存の逆転。口の形の正本 = docs の
 * 設計/ワークフロー/業務データとの接続/公開の口/表示と関係の口.md の 7.3、責務 = docs の
 * 設計/adr/0047-業務データの入れ物の画面に出す持ち主の案内は書き込みの判定と別の依存の逆転の口に置き持ち主の鍵と版と業務モジュールから引く.md)。
 * 表示だけで、書き込みの経路と {@see RecordHolds} の判定からは呼ばない。後ろのモジュールが実装し、起動がタグ {@see self::TAG} で登録する。
 * 業務データの入れ物は実装を直接呼ばず、束ねる 1 か所(内部の {@see RecordHoldNoticeCatalog})を通す。
 *
 * 予期する失敗は実装が狭い型で捕まえて報告し、null・空を返す。予期しない例外は投げたままにする(束ねる 1 か所が報告して案内の無い形にする)。
 */
interface RecordHoldNotices
{
    /** 実装を束ねるコンテナのタグ */
    public const TAG = 'records.record-hold-notices';

    /**
     * 持ち主の鍵({@see RecordHold::$holder})の持ち主の画面へのリンクと状態の札。判定を求める者が持ち主の画面を閲覧できるときだけ返す
     * (閲覧の判定は実装が行う)。自分の鍵でない・持ち主が見つからない・閲覧できないときは null(閲覧できないときは報告しない)。
     */
    public function stateOf(Requester $requester, string $holder, DateTimeInterface $date): ?RecordHoldState;

    /**
     * 渡した版(このレコードの契機のある版)のうち、実装が自分の書き込みと分かる版の id → 持ち主の画面へのリンク(分からない版は入れない)。
     * 判定しない(開いた先の画面が判定する)。
     *
     * @param  list<int>  $revisionIds
     * @return array<int, RecordHoldLink>
     */
    public function revisionLinks(int $recordId, array $revisionIds): array;

    /**
     * その業務モジュール(親の無いもの)の添付の許可を外すと、持ち主の鍵で添付を付けられなくなるレコードがあれば、その数を含む文。無ければ null。
     * 判定しない。
     */
    public function attachmentDisableNotice(int $recordModuleId): ?string;
}
