<?php

namespace App\Actions\Ai\Api;

/**
 * AI の依頼の口が断るときの文のうち、呼び手の入口も使うもの(依頼の口でないクラスに置き、文だけを使う場所が依頼の口に依存しないようにする =
 * docs の 設計/AI/公開の口.md 3 節の「依頼の時点の確かめ」の (b)・設計/業務ツール/機械検査.md の検査 (b))。
 */
final class RunRequestMessages
{
    /** ゲストの依頼を断る文(403。2 つの依頼の口と、依頼の前に断る呼び手の入口が同じ文を返す) */
    public const GUEST = 'ゲストは AI に依頼できません';
}
