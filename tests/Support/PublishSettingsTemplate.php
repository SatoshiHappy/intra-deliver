<?php

namespace Tests\Support;

use Dotenv\Dotenv;
use LogicException;

/**
 * 公開用の設定ファイルの雛形 .env.publish.example(docs の 設計/公開の配備/DBと秘密.md の 4.4 節)を、設定ファイルごとの節に分けて読む。
 * 節は「# ==== <設定ファイルの名前>(…) ====」の行から次の節の行の前まで。値は Laravel が .env を読むのと同じ Dotenv の読み方で読む。
 * アプリの起動より前(環境変数を置いて起動するテストの environmentOverrides())からも呼べるよう、アプリの関数を使わない。
 */
final class PublishSettingsTemplate
{
    private const HEADING = '/^# ==== ([A-Za-z0-9._-]+)/';

    /**
     * 設定ファイル $file の節の変数(名前 => 値。空の値は空の文字列)。
     *
     * @return array<string, string>
     *
     * @throws LogicException 節が無いとき
     */
    public static function section(string $file): array
    {
        $lines = file(dirname(__DIR__, 2).'/.env.publish.example', FILE_IGNORE_NEW_LINES);
        $sections = [];
        $current = null;

        foreach ($lines === false ? [] : $lines as $line) {
            if (preg_match(self::HEADING, $line, $matches) === 1) {
                $current = $matches[1];
                $sections[$current] = [];
            } elseif ($current !== null) {
                $sections[$current][] = $line;
            }
        }

        if (! isset($sections[$file])) {
            throw new LogicException(".env.publish.example に {$file} の節が無い");
        }

        $values = Dotenv::parse(implode("\n", $sections[$file]));

        return array_map(fn (?string $value): string => (string) $value, $values);
    }
}
