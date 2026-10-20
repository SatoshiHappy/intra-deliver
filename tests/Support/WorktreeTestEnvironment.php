<?php

namespace Tests\Support;

use Dotenv\Dotenv;
use UnexpectedValueException;

/**
 * 作業木ごとのテスト DB の名前と E2E のポートを決める。複数の作業木で検査を同時に走らせても、互いの DB・ポートを壊さないため。
 * 使う所 = tests/bootstrap.php と scripts/ci/front(決め方 = docs の 設計/コード構成/検査.md 6 節)。
 * .env だけから引き、OS の環境変数は見ない(bootstrap が置いた DB_DATABASE を paratest の worker が引き継ぐので、見ると接尾辞が重なる)。
 */
final class WorktreeTestEnvironment
{
    /**
     * この作業木のテスト DB の名前。
     *
     * @throws UnexpectedValueException .env に DB_DATABASE が無い(空を含む)とき、接尾辞が空のとき、開発用 DB の名前が接尾辞を含むとき
     */
    public static function testDatabaseName(string $appRoot): string
    {
        $worktree = basename($appRoot);
        $development = self::dotenvValue('DB_DATABASE', $appRoot);

        if ($development === null) {
            throw new UnexpectedValueException(
                "テスト DB の名前を決められない(作業木 {$worktree} の .env に DB_DATABASE が無い、または空)。"
                ."{$worktree}/.env に `DB_DATABASE=<この作業木の開発用 DB の名前>` を書く。"
                .'テスト DB はその名前 + test_db_suffix(config/guardrails.php。_test)になり、DB コンテナの中に作っておく',
            );
        }

        $suffix = self::testDatabaseSuffix($appRoot);

        if (str_contains($development, $suffix)) {
            throw new UnexpectedValueException(
                "{$worktree}/.env の DB_DATABASE({$development})が test_db_suffix({$suffix})を含む。"
                ."開発用 DB の名前は {$suffix} を含まない名前にする(別の作業木のテスト DB と区別できないため)",
            );
        }

        return $development.$suffix;
    }

    /**
     * この作業木の E2E のポート。
     *
     * @throws UnexpectedValueException .env に SERVER_PORT が無い(空を含む)とき、整数のポートでないとき
     */
    public static function e2ePort(string $appRoot): int
    {
        $worktree = basename($appRoot);
        $value = self::dotenvValue('SERVER_PORT', $appRoot);

        if ($value === null) {
            throw new UnexpectedValueException(
                "E2E のポートを決められない(作業木 {$worktree} の .env に SERVER_PORT が無い、または空)。"
                ."{$worktree}/.env に `SERVER_PORT=<この作業木の開発用サーバーのポート>` を書く。E2E のポートはその値 + 1 になる",
            );
        }

        if (preg_match('/\A[1-9][0-9]{0,4}\z/', $value) !== 1 || (int) $value >= 65535) {
            throw new UnexpectedValueException(
                "SERVER_PORT が 1〜65534 の整数でない(値 = {$value})ので、E2E のポート(その値 + 1)を決められない",
            );
        }

        return (int) $value + 1;
    }

    private static function testDatabaseSuffix(string $appRoot): string
    {
        $path = $appRoot.'/config/guardrails.php';
        $guardrails = is_file($path) ? require $path : null;
        $suffix = is_array($guardrails) ? ($guardrails['test_db_suffix'] ?? null) : null;

        if (! is_string($suffix) || $suffix === '') {
            throw new UnexpectedValueException(
                'テスト DB の接尾辞(config/guardrails.php の test_db_suffix)が空か無いので、テスト DB の名前を決められない',
            );
        }

        return $suffix;
    }

    /**
     * アプリのルートの .env の値。ファイルが無い・キーが無い・空は null。
     */
    private static function dotenvValue(string $name, string $appRoot): ?string
    {
        $path = $appRoot.'/.env';
        $contents = is_file($path) ? file_get_contents($path) : false;
        $value = $contents === false ? null : (Dotenv::parse($contents)[$name] ?? null);

        return $value === null || $value === '' ? null : $value;
    }
}
