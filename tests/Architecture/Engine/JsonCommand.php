<?php

namespace Tests\Architecture\Engine;

use JsonException;
use UnexpectedValueException;

/**
 * 一覧を JSON で出すコマンドを実行して、その JSON を返す(汎用)。一覧は照合のたびに作り直す(古い成果物で補わない)。
 */
final class JsonCommand
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     *
     * @throws UnexpectedValueException コマンドが 0 以外で終わったとき・出力が JSON でないとき(一覧の不在を 0 件にしない)
     */
    public static function run(array $command, string $cwd, array $environment = []): mixed
    {
        $result = Process::run($command, $cwd, $environment);

        if ($result->exitCode !== 0) {
            throw new UnexpectedValueException("一覧のコマンドが失敗した\n".$result->describe());
        }

        try {
            return json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException("一覧のコマンドの出力が JSON でない({$exception->getMessage()})\n".$result->describe(), previous: $exception);
        }
    }

    /**
     * TOML ファイルを配列で読む(Python 標準ライブラリの tomllib で JSON に変換する。TOML の読み手を自作しない)。
     *
     * @return array<mixed>
     *
     * @throws UnexpectedValueException 読めないとき・最上位が表でないとき
     */
    public static function toml(string $path): array
    {
        $decoded = self::run(
            ['python3', '-c', 'import json, sys, tomllib; json.dump(tomllib.load(open(sys.argv[1], "rb")), sys.stdout)', $path],
            dirname($path),
        );

        if (! is_array($decoded)) {
            throw new UnexpectedValueException("TOML の最上位が表でない: {$path}");
        }

        return $decoded;
    }
}
