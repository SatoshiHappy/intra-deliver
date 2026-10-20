<?php

namespace App\Enums\Records;

/**
 * 業務モジュールを変えたときの表示の変化(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/失敗と定義の変更.md 11 節の「業務モジュールを変える」の戻り値)。
 * 変える前と後の表示中の判定(グローバルナビのアイテムの有無 = 認可とメニュー/メニューと初期データ.md 5.1)を比べたもの。入口はこれでトーストの文言を選ぶ(画面/管理の画面.md 3.5)。
 */
enum RecordModuleVisibilityChange
{
    /** 表示中から非表示にした(隠した) */
    case Hidden;

    /** 非表示から表示中にした(出した) */
    case Shown;

    /** 表示の状態を変えていない(明細のモジュールを含む) */
    case Unchanged;

    /**
     * 変える前と後の表示中の判定から、表示の変化を決める。
     */
    public static function between(bool $wasVisible, bool $isVisible): self
    {
        return match (true) {
            $wasVisible && ! $isVisible => self::Hidden,
            ! $wasVisible && $isVisible => self::Shown,
            default => self::Unchanged,
        };
    }
}
