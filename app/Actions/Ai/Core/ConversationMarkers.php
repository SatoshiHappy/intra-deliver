<?php

namespace App\Actions\Ai\Core;

/**
 * 外から来る文の中の会話の印の形を、印と読めない形に崩す 1 つの直し方(docs の 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.6 の (1))。
 * 2 つの版が送る道具の結果に当て、③ の道具の説明に入れる名前の写しも同じ直し方を使う。形の一覧は版の物を受け、長い順に並べてから当てる
 * (長い形の中の短い形を先に崩すと、長い形が崩れずに残るため)。
 */
final class ConversationMarkers
{
    /**
     * 文の中の会話の印の形を、先頭の 1 字の後に空白を挟んで崩す。
     *
     * @param  list<string>  $forms  版の会話の印の形(並びは問わない)
     */
    public static function broken(string $text, array $forms): string
    {
        usort($forms, fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

        foreach ($forms as $form) {
            $text = str_replace($form, mb_substr($form, 0, 1).' '.mb_substr($form, 1), $text);
        }

        return $text;
    }
}
