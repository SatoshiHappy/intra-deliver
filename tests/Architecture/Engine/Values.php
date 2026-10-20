<?php

namespace Tests\Architecture\Engine;

use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;

/**
 * 台帳・設定ファイルから読んだ値の形を確かめる(汎用)。形が違えば例外(黙って空にしない)。
 */
final class Values
{
    /**
     * @return list<string>
     *
     * @throws UnexpectedValueException
     */
    public static function strings(mixed $value, string $label): array
    {
        if (! is_array($value) || ! array_is_list($value) || array_filter($value, 'is_string') !== $value) {
            throw new UnexpectedValueException("{$label} が文字列の一覧でない");
        }

        return $value;
    }

    /**
     * @return array<mixed>
     *
     * @throws UnexpectedValueException
     */
    public static function table(mixed $value, string $label): array
    {
        if (! is_array($value)) {
            throw new UnexpectedValueException("{$label} が配列でない");
        }

        return $value;
    }

    /**
     * 表 $value のキー $key の値を文字列の一覧として読む。キーが無ければ空の一覧(書かれていない = 0 件)。
     *
     * @param  array<mixed>  $value
     * @return list<string>
     *
     * @throws UnexpectedValueException
     */
    public static function optionalStrings(array $value, string $key, string $label): array
    {
        return array_key_exists($key, $value) ? self::strings($value[$key], "{$label} の {$key}") : [];
    }

    /**
     * JSON ファイルを読んで最上位の表を返す。
     *
     * @return array<mixed>
     *
     * @throws InvalidArgumentException ファイルが無いとき
     * @throws UnexpectedValueException 読めない・JSON でない・最上位が表でないとき
     */
    public static function jsonFile(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("JSON ファイルが無い: {$path}");
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new UnexpectedValueException("JSON ファイルを読めない: {$path}");
        }

        try {
            return self::table(json_decode($content, true, 512, JSON_THROW_ON_ERROR), $path);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException("JSON として読めない: {$path}({$exception->getMessage()})", previous: $exception);
        }
    }
}
