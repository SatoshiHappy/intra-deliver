<?php

namespace Tests\Architecture\Engine;

use Dotenv\Dotenv;
use UnexpectedValueException;

/**
 * 文書の木(docs)の場所を決める(汎用。決め方 = docs の 設計/コード構成/検査.md 6 節。変数の名前と本体の作業木のときの場所は呼び出し側から渡す)。
 * 別の作業木から本体の docs を黙って読まないため、どれにも当たらなければ失敗する。相対パスは web の根から読む。
 */
final class DocsLocation
{
    /**
     * @param  string  $webRoot  web の根(絶対パス)
     * @param  string  $mainCheckoutDocs  web が本体の作業木のときの docs の場所(web の根からの相対パス)
     *
     * @throws UnexpectedValueException 場所が決まらないとき
     */
    public static function resolve(string $webRoot, string $variable, string $mainCheckoutDocs): string
    {
        $value = getenv($variable);

        if (! is_string($value) || $value === '') {
            $value = self::fromDotenv($webRoot, $variable);
        }

        if ($value === null && self::isMainWorktree($webRoot)) {
            $value = $mainCheckoutDocs;
        }

        if ($value === null) {
            throw new UnexpectedValueException("docs の場所が決まらない(作業木では環境変数か .env に {$variable} を書く)");
        }

        return str_starts_with($value, '/') ? $value : Paths::normalize($webRoot.'/'.$value);
    }

    private static function fromDotenv(string $webRoot, string $variable): ?string
    {
        $file = $webRoot.'/.env';
        $values = is_file($file) ? Dotenv::parse((string) file_get_contents($file)) : [];
        $value = $values[$variable] ?? null;

        return $value === null || $value === '' ? null : $value;
    }

    /**
     * web が git の本体の作業木か(`git rev-parse` の git-dir と git-common-dir が同じ)。
     *
     * @throws UnexpectedValueException git の情報を得られないとき
     */
    private static function isMainWorktree(string $webRoot): bool
    {
        $command = ['git', 'rev-parse', '--path-format=absolute', '--git-dir', '--git-common-dir'];
        $result = Process::run($command, $webRoot);
        $lines = explode("\n", trim($result->stdout));

        if ($result->exitCode !== 0 || count($lines) !== 2) {
            throw new UnexpectedValueException("git の作業木の情報を得られない\n".$result->describe());
        }

        return $lines[0] === $lines[1];
    }
}
