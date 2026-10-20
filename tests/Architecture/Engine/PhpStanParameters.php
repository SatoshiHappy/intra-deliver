<?php

namespace Tests\Architecture\Engine;

use UnexpectedValueException;

/**
 * phpstan が解決した設定(`phpstan dump-parameters --json`)を読む(汎用)。
 * includes・baseline を取り込んだ後の値を phpstan 自身に出させるので、設定ファイルを自前で解釈しない。
 */
final class PhpStanParameters
{
    /**
     * 設定 $configuration を解決したパラメータ。
     *
     * @return array<mixed>
     *
     * @throws UnexpectedValueException phpstan が失敗したとき・出力が表でないとき
     */
    public static function dump(string $toolRoot, string $configuration): array
    {
        return Values::table(
            JsonCommand::run([$toolRoot.'/vendor/bin/phpstan', 'dump-parameters', '--json', '--configuration='.$configuration], $toolRoot),
            'phpstan dump-parameters の出力',
        );
    }

    /**
     * 除外と抑制(excludePaths の各パターン = path / ignoreErrors の各項目 = rule)。パスは $root 起点の相対にする。
     * ignoreErrors の項目は識別子とメッセージを 1 つずつ rule にし、どちらも無い項目は項目の JSON を rule にする
     * (baseline の項目も ignoreErrors に入る)。
     *
     * @param  array<mixed>  $parameters
     * @return list<array{key: string, kind: string, value: string}>
     *
     * @throws UnexpectedValueException 形が違うとき
     */
    public static function exclusions(array $parameters, string $root): array
    {
        $exclusions = [];

        foreach (self::excludePaths($parameters) as $path) {
            $exclusions[] = ['key' => 'excludePaths', 'kind' => 'path', 'value' => Paths::relative($root, $path)];
        }

        foreach (Values::table($parameters['ignoreErrors'] ?? null, 'phpstan の ignoreErrors') as $entry) {
            foreach (self::ignoredRules($entry) as $rule) {
                $exclusions[] = ['key' => 'ignoreErrors', 'kind' => 'rule', 'value' => $rule];
            }
        }

        return $exclusions;
    }

    /**
     * 設定の走査対象(paths)を $root 起点の相対パスで返す($root そのものは `.`)。
     *
     * @param  array<mixed>  $parameters
     * @return list<string>
     *
     * @throws UnexpectedValueException
     */
    public static function analysedPaths(array $parameters, string $root): array
    {
        return array_map(
            fn (string $path): string => Paths::relative($root, $path) === '' ? '.' : Paths::relative($root, $path),
            Values::strings($parameters['paths'] ?? null, 'phpstan の paths'),
        );
    }

    /**
     * 取り込まれた設定ファイル(phpstan 本体の同梱分を除く)を $root 起点の相対パスで返す。
     *
     * @param  array<mixed>  $parameters
     * @return list<string>
     *
     * @throws UnexpectedValueException
     */
    public static function configFiles(array $parameters, string $root): array
    {
        $files = Values::strings($parameters['allConfigFiles'] ?? null, 'phpstan の allConfigFiles');

        return array_values(array_map(
            fn (string $file): string => Paths::relative($root, $file),
            array_filter($files, fn (string $file): bool => ! str_starts_with($file, 'phar://')),
        ));
    }

    /**
     * @param  array<mixed>  $parameters
     * @return list<string>
     *
     * @throws UnexpectedValueException
     */
    private static function excludePaths(array $parameters): array
    {
        $excludePaths = Values::table($parameters['excludePaths'] ?? null, 'phpstan の excludePaths');
        $lists = array_is_list($excludePaths) ? [$excludePaths] : array_values($excludePaths);

        return array_merge(...array_map(fn (mixed $list): array => Values::strings($list, 'phpstan の excludePaths'), $lists));
    }

    /**
     * @return list<string>
     */
    private static function ignoredRules(mixed $entry): array
    {
        if (is_string($entry)) {
            return [$entry];
        }

        $rules = [];

        foreach (['identifier', 'identifiers', 'message', 'messages', 'rawMessage', 'rawMessages'] as $key) {
            if (is_array($entry) && array_key_exists($key, $entry)) {
                array_push($rules, ...Values::strings((array) $entry[$key], "phpstan の ignoreErrors の {$key}"));
            }
        }

        return $rules !== [] ? $rules : [(string) json_encode($entry)];
    }
}
