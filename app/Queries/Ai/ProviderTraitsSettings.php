<?php

namespace App\Queries\Ai;

use Illuminate\Support\Facades\Config;
use LogicException;

/**
 * 会話の型の接続先の性質の値を設定から読む(docs の 設計/AI/会話の中核.md 13.6、置き場 = 設計/AI/実行と費用/接続先と費用.md 5 節)。
 * 形の確かめは起動の時に {@see ConversationSettings::problems()} から呼ぶ(設計/AI/実行と費用/接続先と費用.md 5.4)。
 *
 * @phpstan-type ProviderTraitValues array{caches_instructions: bool, caches_history: bool, estimate_factor: float,
 *     overloaded_marker: string|null, context_overflow_pattern: string|null, retry_header: string|null,
 *     too_long_patterns: list<string>, context_management_models: string|null}
 */
final class ProviderTraitsSettings
{
    private const SECTION = 'config/ai_runs.php の conversation_providers';

    /** 鍵と形(係数と句は別に見る) */
    private const SHAPES = [
        'caches_instructions' => 'bool',
        'caches_history' => 'bool',
        'overloaded_marker' => '?string',
        'context_overflow_pattern' => '?pattern',
        'retry_header' => '?string',
        'context_management_models' => '?pattern',
    ];

    /**
     * 接続先とモデルの組の性質の値(形を確かめた後の値。性質の型へは繋ぎが写す)。
     *
     * @return ProviderTraitValues
     *
     * @throws LogicException 接続先の性質が無い・形が合わないとき(起動の時の確かめで落とす値)
     */
    public function values(string $provider, string $model): array
    {
        if ($this->problems($provider, $model) !== []) {
            throw new LogicException("接続先 {$provider} のモデル {$model} の性質が合わない(".self::SECTION.')');
        }

        $values = $this->raw($provider);

        /** @var list<string> $patterns */
        $patterns = $values['too_long_patterns'] ?? [];

        return [
            'caches_instructions' => (bool) $values['caches_instructions'],
            'caches_history' => (bool) $values['caches_history'],
            'estimate_factor' => (float) $values['estimate_factors'][$model],
            'overloaded_marker' => self::stringOrNull($values['overloaded_marker'] ?? null),
            'context_overflow_pattern' => self::stringOrNull($values['context_overflow_pattern'] ?? null),
            'retry_header' => self::stringOrNull($values['retry_header'] ?? null),
            'too_long_patterns' => $patterns,
            'context_management_models' => self::stringOrNull($values['context_management_models'] ?? null),
        ];
    }

    /**
     * 接続先とモデルの組の性質の形の食い違い(無ければ空)。
     *
     * @return list<string>
     */
    public function problems(string $provider, string $model): array
    {
        $values = $this->raw($provider);
        $label = "接続先 {$provider} の性質";

        if ($values === []) {
            return ["{$label} が無い(".self::SECTION.')'];
        }

        $problems = [];

        foreach (self::SHAPES as $key => $shape) {
            if (! self::fits($values[$key] ?? null, $shape)) {
                $problems[] = "{$label} の {$key} の形が合わない(".self::SECTION.')';
            }
        }

        $factor = $values['estimate_factors'][$model] ?? null;

        if (! (is_int($factor) || is_float($factor)) || $factor <= 0) {
            $problems[] = "{$label} にモデル {$model} の正の見積もりの係数が無い(".self::SECTION.')';
        }

        return self::patternsFit($values['too_long_patterns'] ?? [])
            ? $problems
            : [...$problems, "{$label} の too_long_patterns が正規表現の並びでない(".self::SECTION.')'];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function raw(string $provider): array
    {
        $values = Config::get("ai_runs.conversation_providers.{$provider}");

        return is_array($values) ? $values : [];
    }

    private static function fits(mixed $value, string $shape): bool
    {
        $optional = str_starts_with($shape, '?');

        if ($value === null) {
            return $optional;
        }

        return match (ltrim($shape, '?')) {
            'bool' => is_bool($value),
            'string' => is_string($value) && $value !== '',
            'pattern' => is_string($value) && @preg_match($value, '') !== false,
            default => false,
        };
    }

    private static function patternsFit(mixed $patterns): bool
    {
        if (! is_array($patterns) || ! array_is_list($patterns)) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (! self::fits($pattern, 'pattern')) {
                return false;
            }
        }

        return true;
    }
}
