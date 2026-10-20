<?php

namespace Tests\Architecture\Engine;

use UnexpectedValueException;

/**
 * Pint の設定(pint.json)の除外を、台帳のパスパターンの書き方にそろえて読む(汎用)。
 *
 * - exclude = ディレクトリ(Symfony Finder の exclude)→ `<dir>/**`
 * - notPath = パス → そのまま(正規化だけ)
 * - notName = ファイル名 → 任意の階層の下の `<name>`(`**` と `/<name>` をつないだパターン)
 *
 * 走査対象を全体から絞る設定(`in`・`extend`)は {@see self::narrowing()} で別に読む。
 */
final class PintConfig
{
    /**
     * @param  array<mixed>  $config
     * @return list<array{key: string, kind: string, value: string}>
     *
     * @throws UnexpectedValueException 値が文字列の一覧でないとき
     */
    public static function exclusions(array $config): array
    {
        $normalizers = [
            'exclude' => fn (string $value): string => Paths::normalize($value).'/**',
            'notPath' => fn (string $value): string => Paths::normalize($value),
            'notName' => fn (string $value): string => '**/'.$value,
        ];

        $exclusions = [];

        foreach ($normalizers as $key => $normalize) {
            foreach (Values::optionalStrings($config, $key, 'pint.json') as $value) {
                $exclusions[] = ['key' => $key, 'kind' => 'path', 'value' => $normalize($value)];
            }
        }

        return $exclusions;
    }

    /**
     * 走査対象を全体から絞る設定(key = 設定のキー、value = 値)。Pint の走査対象は既定でアプリのルート全体で、
     * `in` はそれを指定のディレクトリに絞る。`extend` は別の設定ファイルの除外と対象を取り込む(このファイルだけでは照合できない)。
     *
     * @param  array<mixed>  $config
     * @return list<array{key: string, value: string}>
     */
    public static function narrowing(array $config): array
    {
        $narrowing = [];

        foreach (['in', 'extend'] as $key) {
            $values = array_key_exists($key, $config) ? (array) $config[$key] : [];

            foreach ($values as $value) {
                $narrowing[] = ['key' => $key, 'value' => self::describe($value)];
            }
        }

        return $narrowing;
    }

    private static function describe(mixed $value): string
    {
        return is_string($value) ? $value : (string) json_encode($value);
    }
}
