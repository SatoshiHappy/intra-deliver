<?php

namespace App\Queries\Records\Api;

use App\Queries\Records\RecordModuleVisibilityStopCatalog;

/**
 * 業務モジュールの表示の止めの口(依存の逆転 = docs の 設計/コード構成/README.md 3 節。ADR 0031 の決定 2。口の形の正本 =
 * 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の表、規則の正本 = 設計/業務データの入れ物/項目と規則/規則と業務処理/失敗と定義の変更.md
 * 11 節の「表示の止め」)。止められている業務モジュールは、業務モジュールを変える業務処理で表示にできない。
 *
 * 後ろのモジュール(ワークフロー)が実装し、起動(App\Providers)がタグ {@see self::TAG} で登録する。業務データの入れ物は実装を直接呼ばず、
 * 束ねる 1 か所(内部の {@see RecordModuleVisibilityStopCatalog})を通す。業務データの入れ物は止めの理由だけを知り、止める側が何かを知らない。
 */
interface RecordModuleVisibilityStops
{
    /** 実装を束ねるコンテナのタグ */
    public const TAG = 'records.record-module-visibility-stops';

    /**
     * その業務モジュール(親の無いもの)の表示を止めるなら理由の文言、止めないなら null。鍵を取らずに読む。例外を投げない(DB の例外は上へ)
     */
    public function stopReason(int $recordModuleId): ?string;
}
