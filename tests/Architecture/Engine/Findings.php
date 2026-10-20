<?php

namespace Tests\Architecture\Engine;

use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;

/**
 * 検査ツールの出力を指摘({@see Finding})の一覧に変換する(汎用)。JSON と行テキストの 2 形を受ける。
 */
final class Findings
{
    /**
     * JSON の出力(指摘の配列)を変換する。
     *
     * 例 = [{"RuleID": "generic-api-key", "File": "a.env", "StartLine": 3}] なら
     * Findings::fromJson($stdout, 'RuleID', 'File', 'StartLine')。
     * 指摘の配列が最上位でなくキーの下にあるときは $listKey にそのキーを渡す。
     *
     * @return list<Finding>
     *
     * @throws JsonException JSON として読めないとき
     * @throws UnexpectedValueException 形が違うとき(配列でない・キーが無い・型が違う)
     */
    public static function fromJson(string $json, string $ruleKey, string $fileKey, ?string $lineKey = null, ?string $listKey = null): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if ($listKey !== null) {
            if (! is_array($decoded) || ! array_key_exists($listKey, $decoded)) {
                throw new UnexpectedValueException("指摘の配列のキーが無い: {$listKey}");
            }

            $decoded = $decoded[$listKey];
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new UnexpectedValueException('指摘の配列ではない');
        }

        return array_map(
            fn (mixed $item): Finding => self::fromJsonItem($item, $ruleKey, $fileKey, $lineKey),
            $decoded,
        );
    }

    /**
     * 行テキストの出力を変換する。名前付きグループ rule・file と、任意の line を持つ正規表現に一致した行だけを指摘にする
     * (見出しや集計の行は一致しないので捨てる)。
     *
     * 例 = "src/A.php:12:no-static" の形なら Findings::fromLines($stdout, '/^(?<file>[^:]+):(?<line>\d+):(?<rule>\S+)$/')。
     *
     * @return list<Finding>
     *
     * @throws InvalidArgumentException 正規表現で照合できないか、名前付きグループ rule・file が無いとき
     */
    public static function fromLines(string $text, string $pattern): array
    {
        $lines = preg_split('/\R/', $text);

        if ($lines === false) {
            throw new InvalidArgumentException('出力を行に分けられない: '.preg_last_error_msg());
        }

        $findings = [];

        foreach ($lines as $line) {
            $matched = preg_match($pattern, $line, $groups);

            if ($matched === false) {
                throw new InvalidArgumentException("正規表現で照合できない({$pattern}): ".preg_last_error_msg());
            }

            if ($matched === 0) {
                continue;
            }

            if (! isset($groups['rule'], $groups['file'])) {
                throw new InvalidArgumentException("名前付きグループ rule・file が無い: {$pattern}");
            }

            $findings[] = new Finding(
                $groups['rule'],
                $groups['file'],
                isset($groups['line']) && $groups['line'] !== '' ? (int) $groups['line'] : null,
            );
        }

        return $findings;
    }

    /**
     * @throws UnexpectedValueException
     */
    private static function fromJsonItem(mixed $item, string $ruleKey, string $fileKey, ?string $lineKey): Finding
    {
        if (! is_array($item) || ! is_string($item[$ruleKey] ?? null) || ! is_string($item[$fileKey] ?? null)) {
            throw new UnexpectedValueException("指摘に文字列の {$ruleKey}・{$fileKey} が無い: ".json_encode($item));
        }

        $line = $lineKey === null ? null : ($item[$lineKey] ?? null);

        if ($line !== null && ! is_int($line)) {
            throw new UnexpectedValueException("指摘の {$lineKey} が整数でない: ".json_encode($item));
        }

        return new Finding($item[$ruleKey], $item[$fileKey], $line);
    }
}
