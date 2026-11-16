<?php

namespace Tests\Feature\Ai;

/**
 * モデルに渡す自前の文の確かめ(docs の 設計/AI/設定とプロンプト/使う文と組み立て.md 11.8 の「モデルに渡す文はすべて日本語」と、
 * 公開の側に接続先の会社と製品の名前を入れないこと = 設計/AI/README.md の採った方針の 18): 英語に戻った文・英語の語の並び・禁止の語を拾う。
 * 既定の文と道具の説明が同じ確かめを使う。
 */
trait ChecksModelTexts
{
    /** 接続先の会社と製品の名前(大文字小文字を問わず語の境で見る) */
    private const VENDOR_WORDS = [
        'Claude', 'Anthropic', 'OpenAI', 'ChatGPT', 'GPT', 'Gemini', 'Google', 'Mistral', 'Bedrock', 'Azure',
    ];

    /** 上の名前のカタカナの言い方(前と後ろの字がカタカナでない所で見る) */
    private const VENDOR_KATAKANA_WORDS = [
        'クロード', 'アンソロピック', 'アンスロピック', 'オープンエーアイ', 'チャットジーピーティー', 'ジェミニ', 'グーグル',
        'ミストラル', 'ベッドロック', 'アジュール',
    ];

    /** カタカナの字(長音の「ー」を含む。語の境を見る字) */
    private const KATAKANA = '[\x{30A1}-\x{30FA}\x{30FC}-\x{30FE}]';

    /** 日本語の字(ひらがな・カタカナ・漢字・々) */
    private const JAPANESE_LETTER = '/[\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}々]/u';

    /** 英語の語(英字だけの語。前後の句読点と所有格を許す。識別子のような英字でない字を含む語は当たらない) */
    private const ENGLISH_WORD = '/^[("\']?[A-Za-z]+(?:[\'’][A-Za-z]+)?[.,;:!?)"\']*\z/u';

    /** 英語の語がこの数以上、空白だけで続けば英語の文と見る */
    private const ENGLISH_RUN = 6;

    /**
     * 置き換え口と `[...]` の角かっこを除いて、日本語の字を 1 つも含まない文の鍵。
     *
     * @param  array<string, string>  $texts
     * @return list<string>
     */
    private static function textsNotInJapanese(array $texts): array
    {
        $keys = [];

        foreach ($texts as $key => $text) {
            $rest = str_replace(['[', ']'], '', self::withoutPlaceholders($text));

            if (preg_match(self::JAPANESE_LETTER, $rest) !== 1) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * 置き換え口とバッククォートの中を除いて、英語の語が ENGLISH_RUN 語以上、空白だけで続く所を持つ文の鍵。
     *
     * @param  array<string, string>  $texts
     * @return list<string>
     */
    private static function textsWithEnglishRuns(array $texts): array
    {
        $keys = [];

        foreach ($texts as $key => $text) {
            $plain = preg_replace('/`[^`]*`/u', ' ', self::withoutPlaceholders($text)) ?? $text;
            $run = 0;

            foreach (preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $run = preg_match(self::ENGLISH_WORD, $token) === 1 ? $run + 1 : 0;

                if ($run >= self::ENGLISH_RUN) {
                    $keys[] = $key;
                    break;
                }
            }
        }

        return $keys;
    }

    /**
     * 文に現れた禁止の語(<文の鍵> の <語>)。
     *
     * @param  array<string, string>  $texts
     * @param  list<string>  $words  英語の語(大文字小文字を問わず語の境で見る)
     * @param  list<string>  $katakanaWords  カタカナの語(前と後ろの字がカタカナでない所で見る)
     * @return list<string>
     */
    private static function wordProblems(array $texts, array $words, array $katakanaWords): array
    {
        $patterns = [];

        foreach ($words as $word) {
            $patterns[$word] = '/\b'.str_replace(' ', '\s+', preg_quote($word, '/')).'\b/i';
        }

        foreach ($katakanaWords as $word) {
            $patterns[$word] = '/(?<!'.self::KATAKANA.')'.preg_quote($word, '/').'(?!'.self::KATAKANA.')/u';
        }

        $problems = [];

        foreach ($texts as $key => $text) {
            foreach ($patterns as $word => $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    $problems[] = "{$key} の {$word}";
                }
            }
        }

        return $problems;
    }

    private static function withoutPlaceholders(string $text): string
    {
        return preg_replace('/\{\{[a-z_]+\}\}/', ' ', $text) ?? $text;
    }
}
