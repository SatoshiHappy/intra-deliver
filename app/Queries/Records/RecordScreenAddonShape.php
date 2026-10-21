<?php

namespace App\Queries\Records;

use App\Queries\Records\Api\RecordScreenAddons;
use LogicException;

/**
 * 後ろのモジュールが足す操作・札・注({@see RecordScreenAddons})が口の型どおりの形か(キーの集合と値の型。docs の
 * 設計/業務データの入れ物/画面/業務の画面.md の 2.5)。形の違いは実装の作りの誤りなので例外にする(呼び手は握らない)。
 * 型の正本は口の @phpstan-type。
 */
class RecordScreenAddonShape
{
    private const TONES = ['pending', 'returned', 'settled', 'rejected'];

    private const ACTION = [
        'code' => 'string',
        'label' => 'string',
        'dialog' => 'string',
        'props' => 'dialogProps',
        'disabledReason' => '?string',
        'changesValues' => 'bool',
    ];

    private const BADGE = ['label' => 'string', 'tone' => '?tone'];

    /**
     * @throws LogicException 形の違い
     */
    public function ensureIndex(string $source, mixed $addons): void
    {
        $this->ensure($source, 'index()', $this->matchesShape($addons, ['actions' => 'actions']));
    }

    /**
     * @throws LogicException 形の違い
     */
    public function ensureShow(string $source, mixed $addons): void
    {
        $shape = ['actions' => 'actions', 'badges' => 'badges', 'fieldNotes' => 'fieldNotes'];
        $this->ensure($source, 'show()', $this->matchesShape($addons, $shape));
    }

    /**
     * 行の札の形と、渡した行の id だけを返したか。
     *
     * @param  list<int>  $recordIds
     *
     * @throws LogicException 形の違い・渡していない行の id
     */
    public function ensureBadges(string $source, mixed $badges, array $recordIds): void
    {
        if (! is_array($badges)) {
            $this->ensure($source, 'badges()', false);

            return;
        }

        $valid = $this->allMatch($badges, fn (mixed $list) => $this->matches($list, 'badges'));
        $this->ensure($source, 'badges()', $valid);
        $unknown = array_diff(array_keys($badges), $recordIds);

        if ($unknown !== []) {
            throw new LogicException("後ろのモジュールの {$source} の badges() が渡していない行の id を返した: "
                .implode(',', $unknown));
        }
    }

    /**
     * @throws LogicException 形の違い
     */
    private function ensure(string $source, string $method, bool $valid): void
    {
        if (! $valid) {
            throw new LogicException("後ろのモジュールの {$source} の {$method} の形が違う");
        }
    }

    /**
     * @param  array<string, string>  $shape
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

        return $keys === $expected
            && $this->allMatch($shape, fn (string $form, string $key) => $this->matches($value[$key], $form));
    }

    private function matches(mixed $value, string $form): bool
    {
        return match ($form) {
            'string' => is_string($value),
            '?string' => $value === null || is_string($value),
            'bool' => is_bool($value),
            '?tone' => $value === null || in_array($value, self::TONES, true),
            'actions' => $this->isListOf($value, self::ACTION),
            'badges' => $this->isListOf($value, self::BADGE),
            'fieldNotes' => is_array($value) && $this->allMatch(
                $value,
                fn (mixed $note, int|string $code) => is_string($code) && is_string($note),
            ),
            'dialogProps' => is_array($value) && $this->allMatch(
                $value,
                fn (mixed $prop, int|string $key) => is_string($key) && ($prop === null || is_scalar($prop))
                    && ! is_float($prop),
            ),
            default => false,
        };
    }

    /**
     * @param  array<string, string>  $shape
     */
    private function isListOf(mixed $value, array $shape): bool
    {
        return is_array($value) && array_is_list($value)
            && $this->allMatch($value, fn (mixed $item) => $this->matchesShape($item, $shape));
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  callable(mixed, array-key): bool  $test
     */
    private function allMatch(array $values, callable $test): bool
    {
        foreach ($values as $key => $value) {
            if (! $test($value, $key)) {
                return false;
            }
        }

        return true;
    }
}
