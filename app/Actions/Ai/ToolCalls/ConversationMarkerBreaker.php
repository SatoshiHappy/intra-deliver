<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Instructions\InstructionBuilder;

/**
 * 外から来る文の中の会話の印の形を、印と読めない形に直す部品(docs の 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.6 の (1))。
 * 送る時の道具の結果と、1 段目が作る道具の説明に入れる名前の写しが、同じ直し方を使う。形の一覧は版ごとに違うので、結んだ版から引く。
 */
final class ConversationMarkerBreaker
{
    /**
     * 版の会話の印の形(長い順。長い形の中の短い形を先に崩さないため)。
     *
     * @return list<string>
     */
    public static function forms(InstructionBuilder $builder): array
    {
        $forms = $builder->conversationMarkerForms();
        usort($forms, fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

        return $forms;
    }

    /**
     * 文の中の会話の印の形を、先頭の 1 字の後に空白を挟んで崩す。
     *
     * @param  list<string>  $forms  {@see self::forms()} の形
     */
    public static function broken(string $text, array $forms): string
    {
        foreach ($forms as $form) {
            $text = str_replace($form, mb_substr($form, 0, 1).' '.mb_substr($form, 1), $text);
        }

        return $text;
    }
}
