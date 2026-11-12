<?php

namespace App\Queries\Ai\Api;

use LogicException;

/**
 * 結果のレコードの印の部品(③ の公開の側に 1 つ。docs の 設計/AI/道具と権限/README.md 10.2 の「結果のレコードの印と根拠」・
 * まとめた実行と送るデータ.md 10.6 の (1))。印を付け、答えの文から印を取り出し(① が使う = 設計/AI/エージェントの実行/口と契約.md 9.12 の (14))、
 * 外から来る値の中の印と同じ形の文字列を印と読めない形に直す。印の形は会話の印の形(設計/AI/設定とプロンプト/README.md 11.4)のどれとも重ならない。
 */
final class RecordMarkers
{
    /** 印の始まり(直すときは、この最初の 1 字の後に空白を 1 つ挟む = 会話の印の形の直しと同じ規則) */
    private const OPENING = '[[rec:';

    /** 印の形(業務モジュールのコードと、先頭が 0 でない 10 進の id) */
    private const PATTERN = '/\[\[rec:([a-z][a-z0-9_]*):([1-9][0-9]*)\]\]/';

    /** 業務モジュールのコードの形 */
    private const MODULE_PATTERN = '/^[a-z][a-z0-9_]*$/D';

    /**
     * レコードの印。
     *
     * @throws LogicException 業務モジュールのコードか id が印の形に合わないとき(作りの誤り)
     */
    public static function of(string $module, int $id): string
    {
        if (preg_match(self::MODULE_PATTERN, $module) !== 1 || $id < 1) {
            throw new LogicException("レコードの印にできない業務モジュールのコードか id: {$module}・{$id}");
        }

        return self::OPENING."{$module}:{$id}]]";
    }

    /**
     * 文の中の印(最初に出た順。同じ印は 1 つ。形の合わない物は拾わない)。
     *
     * @return list<array{module: string, id: int}>
     */
    public static function extract(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER);
        $markers = [];

        foreach ($matches as $match) {
            $markers[$match[0]] ??= ['module' => $match[1], 'id' => (int) $match[2]];
        }

        return array_values($markers);
    }

    /** 外から来る値の中の、印と同じ形の文字列を印と読めない形に直す */
    public static function neutralize(string $value): string
    {
        return str_replace(self::OPENING, '[ '.substr(self::OPENING, 1), $value);
    }
}
