<?php

namespace Tests\Architecture\Policy;

/**
 * 経緯の語の照合(tests/Architecture/HistoryWordsTest.php)の語(docs の 設計/コード構成/検査/README.md 6.2)。
 * 漢字を CJK 統合漢字の区画と `々` で書くのは、`\p{Han}` が句読点の `、`・`。` も含むため。
 */
final class HistoryWords
{
    /** 修正の方針の文書の名前は符号位置で書く(このファイル自身を数えない) */
    public const PATTERN = '/(?<![A-Za-z0-9_])\\x{4FEE}\\x{6B63}\\x{65B9}\\x{91DD}'
        .'|(?<![\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}々A-Za-z0-9_])段[ \t]*[0-9]++(?:[ab](?:-[0-9]++)?+)?+'
        .'|(?<![A-Za-z0-9_])区切り[ \t]*[0-9]++(?:-[0-9]++)?+'
        .'|(?<![A-Za-z0-9_\-\[.#\\\\])[0-9]++[ab](?:-[0-9]++)?+(?![A-Za-z0-9_\]])(?!-[A-Za-z])/u';
}
