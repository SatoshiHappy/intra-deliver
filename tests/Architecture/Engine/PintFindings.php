<?php

namespace Tests\Architecture\Engine;

use JsonException;
use UnexpectedValueException;

/**
 * Pint の JSON 出力(pint --test -v --format=json)を指摘({@see Finding})の一覧に変換する(汎用)。規則 ID = 差分を出した fixer の名前、行は無い。
 *
 * Pint はエージェントの中で動くと --format の指定より自前の agent 形式を優先するので、次の 2 形を受ける(どちらでも判定は同じ):
 * - agent 形式   = {"result": "passed" | "fail", "files": [{"path": …, "fixers": [名前…]}]}(passed のときは files が無い)
 * - JSON 形式    = {"files": [{"name": …, "appliedFixers": [名前…]}]}(PHP CS Fixer の JSON。fixer の名前は -v のときだけ出る)
 */
final class PintFindings
{
    /**
     * @return list<Finding>
     *
     * @throws JsonException JSON として読めないとき
     * @throws UnexpectedValueException 形が違うとき(files が無い・ファイルのパスや fixer の一覧が無い)
     */
    public static function fromJson(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new UnexpectedValueException('Pint の JSON 出力がオブジェクトでない');
        }

        if (! array_key_exists('files', $decoded) && ($decoded['result'] ?? null) === 'passed') {
            return [];
        }

        if (! is_array($decoded['files'] ?? null) || ! array_is_list($decoded['files'])) {
            throw new UnexpectedValueException('Pint の JSON 出力に files の配列が無い');
        }

        $findings = [];

        foreach ($decoded['files'] as $file) {
            array_push($findings, ...self::fromFile($file));
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     *
     * @throws UnexpectedValueException
     */
    private static function fromFile(mixed $file): array
    {
        $path = is_array($file) ? ($file['path'] ?? $file['name'] ?? null) : null;
        $fixers = is_array($file) ? ($file['fixers'] ?? $file['appliedFixers'] ?? null) : null;

        if (! is_string($path) || ! is_array($fixers) || $fixers === []) {
            throw new UnexpectedValueException('Pint の JSON 出力のファイルにパスか fixer の一覧が無い(-v を付けて実行する): '.json_encode($file));
        }

        return array_values(array_map(
            fn (mixed $fixer): Finding => is_string($fixer)
                ? new Finding($fixer, $path, null)
                : throw new UnexpectedValueException('Pint の JSON 出力の fixer の名前が文字列でない: '.json_encode($file)),
            $fixers,
        ));
    }
}
