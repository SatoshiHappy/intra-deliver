<?php

namespace Tests\Architecture;

use JsonException;
use RuntimeException;
use Tests\Architecture\Engine\ExclusiveLock;
use Tests\Architecture\Engine\PhpStanFindings;
use Tests\Architecture\Engine\Process;
use Tests\Architecture\Engine\ProcessResult;
use UnexpectedValueException;

/**
 * phpstan に載せた検査(S 静的解析・Z 大きさ・B1 の静的な規則)の自己証明の基底。
 *
 * fixture 用の設定 tests/Architecture/fixtures/phpstan.neon(通常の phpstan.neon をそのまま取り込み、fixture の走査除外だけを外す)で、
 * 走査ルートだけを fixture の 1 ディレクトリに差し替えて phpstan を走らせる。規則の登録・判定本体・レベル・上限は通常と同じ。
 * 同じ走査ルートの結果は使い回す(同じ入力に phpstan を何度も走らせない)。
 * phpstan の作業用ディレクトリ(tmpDir)はプロセスごとに分ける({@see self::fixturesTmpDir()}。並列実行の各プロセスが同じ場所に
 * nette の Container のキャッシュを書くと、書き途中のファイルを include して落ちる = 2026-10-13 に ci:check で 3 回再現)。
 * fixture の phpstan は作業木ごとに同時に 1 本まで({@see self::lockFile()})。
 */
abstract class PhpStanSelfProofTestCase extends SelfProofTestCase
{
    /** fixture の共通の設定(tests/Architecture/fixtures からの相対パス) */
    protected const CONFIGURATION = 'phpstan.neon';

    /** @var array<string, ProcessResult> */
    private static array $results = [];

    /**
     * 走査ルート $fixtureRoot(tests/Architecture/fixtures からの相対パス)に、設定 $configuration(同じく相対パス。
     * 共通の設定を取り込み、fixture 用の値だけを差し替えたもの)で phpstan を掛けた結果を返す。
     */
    protected static function analyse(string $fixtureRoot, string $configuration = self::CONFIGURATION): ProcessResult
    {
        return self::$results[$configuration.' '.$fixtureRoot] ??= ExclusiveLock::run(self::lockFile(), static fn (): ProcessResult => Process::run(
            [
                'vendor/bin/phpstan',
                'analyse',
                '--configuration=tests/Architecture/fixtures/'.$configuration,
                '--error-format=json',
                '--no-progress',
                '--memory-limit=1G',
                'tests/Architecture/fixtures/'.$fixtureRoot,
            ],
            dirname(__DIR__, 2),
            ['PHPSTAN_FIXTURES_TMP_DIR' => self::fixturesTmpDir()],
        ));
    }

    /**
     * fixture の phpstan を作業木ごとに同時に 1 本までにするロックファイル(作業木の build の中。1 回 約 280MB で、一斉に起動するとスワップする)。
     * 作業木をまたいで絞らない理由と、待ちに上限を置かない理由は docs の 設計/コード構成/検査.md 6 節「並列(T)」。
     *
     * @throws RuntimeException build を作れないとき
     */
    private static function lockFile(): string
    {
        $directory = dirname(__DIR__, 2).'/build';

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("ロックファイルの置き場を作れない: {$directory}");
        }

        return $directory.'/phpstan-fixtures.lock';
    }

    /**
     * fixture 用の phpstan の作業用ディレクトリ(絶対パス)。並列実行(paratest)はプロセスごとの TEST_TOKEN で分け、1 プロセスの実行は serial。
     *
     * @throws RuntimeException TEST_TOKEN を名前にできないとき(黙って共有の名前に戻さない)
     */
    private static function fixturesTmpDir(): string
    {
        $token = getenv('TEST_TOKEN');
        $name = is_string($token) && $token !== '' ? preg_replace('/[^A-Za-z0-9_]/', '_', $token) : 'serial';

        if ($name === null) {
            throw new RuntimeException('TEST_TOKEN を tmpDir の名前にできない');
        }

        return dirname(__DIR__, 2).'/build/phpstan-fixtures/'.$name;
    }

    protected function findings(ProcessResult $result): array
    {
        try {
            return PhpStanFindings::fromJson($result->stdout);
        } catch (JsonException|UnexpectedValueException $exception) {
            throw new UnexpectedValueException("phpstan の出力を読めない: {$exception->getMessage()}\n".$result->describe(), previous: $exception);
        }
    }
}
