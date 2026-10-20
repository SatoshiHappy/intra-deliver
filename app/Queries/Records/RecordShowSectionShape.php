<?php

namespace App\Queries\Records;

use App\Queries\Records\Api\RecordShowSections;

/**
 * 業務ツールの節の中身({@see RecordShowSections::section()})が口の型どおりの形か(キーの集合と値の型。docs の
 * 設計/業務データの入れ物/レコードの共通機能/詳細の画面/節の口.md の 2 節の 4)。形の違う中身は呼び手が失敗の形にして報告する。
 * 種類を足すときはここの表に足す(型の正本は口の @phpstan-type)。
 */
class RecordShowSectionShape
{
    private const LINK = ['label' => 'string', 'url' => 'string'];

    private const TONES = ['pending', 'returned', 'settled', 'rejected'];

    private const COLUMN_TYPES = ['title', 'text', 'date', 'datetime', 'status'];

    /**
     * 種類 → キー → 値の形(文字列 = 素の値、[形, 中身] = 一覧・入れ子・列挙)。
     *
     * @var array<string, array<string, string|array{string, mixed}>>
     */
    private const SHAPES = [
        'table' => [
            'kind' => 'string',
            'count' => '?int',
            'columns' => ['list', ['code' => 'string', 'label' => 'string', 'type' => ['enum', self::COLUMN_TYPES]]],
            'rows' => ['list', [
                'code' => 'string',
                'url' => '?string',
                'cells' => ['list', ['text' => 'string', 'tone' => ['?enum', self::TONES]]],
            ]],
            'emptyText' => 'string',
            'more' => ['?shape', self::LINK],
        ],
        'list' => [
            'kind' => 'string',
            'count' => '?int',
            'items' => ['list', [
                'code' => 'string', 'title' => 'string', 'url' => '?string', 'note' => '?string', 'at' => '?string',
            ]],
            'emptyText' => 'string',
            'more' => ['?shape', self::LINK],
        ],
        'lines' => [
            'kind' => 'string',
            'count' => '?int',
            'note' => '?string',
            'items' => ['list', ['time' => '?string', 'label' => '?string', 'text' => 'string']],
            'emptyText' => 'string',
        ],
        'failed' => ['kind' => 'string'],
    ];

    public function isValid(mixed $section): bool
    {
        $kind = is_array($section) ? ($section['kind'] ?? null) : null;
        $shape = is_string($kind) ? (self::SHAPES[$kind] ?? null) : null;

        return $shape !== null && $this->matchesShape($section, $shape);
    }

    /**
     * @param  array<string, mixed>  $shape
     */
    private function matchesShape(mixed $value, array $shape): bool
    {
        if (! is_array($value)) {
            return false;
        }

        $keys = array_keys($value);
        $expected = array_keys($shape);
        sort($keys);
        sort($expected);

        if ($keys !== $expected) {
            return false;
        }

        foreach ($shape as $key => $form) {
            if (! $this->matches($value[$key], $form)) {
                return false;
            }
        }

        return true;
    }

    private function matches(mixed $value, mixed $form): bool
    {
        if (is_string($form)) {
            return match ($form) {
                'string' => is_string($value),
                '?string' => $value === null || is_string($value),
                '?int' => $value === null || is_int($value),
                default => false,
            };
        }

        [$kind, $inner] = is_array($form) ? $form : ['', []];

        return match ($kind) {
            'list' => is_array($value) && array_is_list($value) && $this->allMatch($value, $inner),
            '?shape' => $value === null || (is_array($inner) && $this->matchesShape($value, $inner)),
            '?enum' => $value === null || (is_array($inner) && in_array($value, $inner, true)),
            'enum' => is_array($inner) && in_array($value, $inner, true),
            default => false,
        };
    }

    /**
     * @param  list<mixed>  $values
     */
    private function allMatch(array $values, mixed $shape): bool
    {
        foreach ($values as $value) {
            if (! is_array($shape) || ! $this->matchesShape($value, $shape)) {
                return false;
            }
        }

        return true;
    }
}
