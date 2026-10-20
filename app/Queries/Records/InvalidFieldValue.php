<?php

namespace App\Queries\Records;

use InvalidArgumentException;

/**
 * 項目の値が型・長さ・選択肢に合わない(docs の 設計/業務データの入れ物/項目と規則/README.md 2 節)。メッセージは画面に出す文言(10 節)。
 */
class InvalidFieldValue extends InvalidArgumentException
{
    /**
     * 線引き(M01。項目と規則/規則と業務処理/README.md 9.2 の 6〜8 の注記):
     * - true = 設計/業務データの入れ物/データモデル/値の形と直列化.md 3 節の値の形(型・範囲・桁)に合わない値。正規の形にならないので、その項目を使う規則を評価しない(既定。整数・小数の
     *   桁の超過を含む)。
     * - false = 値の形には合い、今の定義(最大の文字数・選択肢)だけに合わない値。正規の形のまま比べられるので、その項目を使う規則を評価する。
     *
     * @param  bool  $isWrongShape  値の形の誤り(true)か、今の定義だけに合わない誤り(false)か
     */
    public function __construct(string $message, public readonly bool $isWrongShape = true)
    {
        parent::__construct($message);
    }
}
