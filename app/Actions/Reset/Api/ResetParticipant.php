<?php

namespace App\Actions\Reset\Api;

/**
 * 毎日の初期化に加わる口(依存の逆転 = docs の 設計/コード構成/README.md 3 節。呼ぶ時と保証の正本 = 設計/公開の配備/毎日の初期化.md 7 節の表)。
 * 初期化で消えては困る表を持つモジュールが業務処理の層に実装し、起動(App\Providers)がタグ {@see self::TAG} で登録する。
 * Reset は実装を直接名指さず、タグで束ねて呼ぶ。
 */
interface ResetParticipant
{
    /** 実装を束ねるコンテナのタグ */
    public const TAG = 'reset.participants';

    /**
     * 初期化で空にしない表の名前(public のスキーマの表)。空にする表を外部キーで指さない表だけを返す(設計/公開の配備/毎日の初期化.md 1 節)。
     *
     * @return list<string>
     */
    public function keptTables(): array;

    /**
     * 表を空にする直前に、初期化 1 回につき 1 回呼ばれる(入れ直しと同じ接続・同じトランザクションの中)。呼ばれた後、同じトランザクションで
     * {@see self::keptTables()} のほかの表がすべて空になる前提で書いてよい。例外を投げると入れ直しの全体が巻き戻る。
     */
    public function beforeTruncate(): void;
}
