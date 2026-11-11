<?php

namespace App\Queries\Ai;

/**
 * config/ai_runs.php の種類によらない表(単価・予算の区分・見積もり)と、キューの配り直すまでの時間の形の規則(内部。読む 1 か所 = {@see AiSettings})。
 * 規則を 1 か所に置くため、どの読み方も値と食い違いの文の組を返し、値を使う {@see AiSettings} は文があれば最初の文で作りの誤りにする。
 */
final class AiSettingsShape
{
    /** 起動の時に形を確かめる表(単価の中身は使う接続先とモデルごとに {@see self::entryProblems()}) */
    private const TABLES = ['pricing', 'budget', 'estimate'];

    /** 見積もりの値(数) */
    public const ESTIMATE_KEYS = ['chars_factor', 'overhead_tokens'];

    /** 単価の段の単価(数) */
    private const TIER_PRICES = ['input', 'output', 'cache_read', 'cache_write'];

    /** 音声の分単価(数) */
    private const AUDIO_PRICES = ['input', 'output'];

    private const RETRY_AFTER_PROBLEM = 'config/queue.php の connections.database.retry_after が正の整数でない';

    /**
     * 起動の時の確かめの、種類によらない表の形の食い違い(docs の 設計/AI/データと失敗と検査.md 8 節。無ければ空)。
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        $problems = [];

        foreach (self::TABLES as $key) {
            $problems = [...$problems, ...self::table($key)[1]];
        }

        foreach (self::table('estimate')[1] === [] ? self::ESTIMATE_KEYS : [] as $key) {
            $problems = [...$problems, ...self::estimate($key)[1]];
        }

        return [...$problems, ...self::budgetProblems(), ...self::retryAfter()[1]];
    }

    /**
     * 表 $key と、配列でないときの文。
     *
     * @return array{array<array-key, mixed>, list<never>}|array{null, non-empty-list<string>}
     */
    public static function table(string $key): array
    {
        $value = config("ai_runs.{$key}");

        return is_array($value) ? [$value, []] : [null, ["config/ai_runs.php の {$key} が配列でない"]];
    }

    /**
     * 接続先・モデルの単価の設定の値(段の一覧か分単価。無ければ null)と、表が配列でないときの文。
     *
     * @return array{mixed, list<string>}
     */
    public static function price(string $provider, string $model): array
    {
        [$pricing, $problems] = self::table('pricing');
        $byProvider = $pricing[$provider] ?? null;

        return [is_array($byProvider) ? ($byProvider[$model] ?? null) : null, $problems];
    }

    /**
     * 単価の設定の値 $price(接続先.モデル = $label)の形の食い違い(段の形と段の単価・音声の分単価が配列で数)。単価が無ければ空。
     *
     * @return list<string>
     */
    public static function entryProblems(mixed $price, string $label): array
    {
        if (! is_array($price)) {
            return [];
        }

        if (self::isAudioMinutePrice($price)) {
            return self::audioProblems($price['per_audio_minute'], "pricing.{$label}.per_audio_minute");
        }

        $problems = [];

        foreach ($price as $tier) {
            $problems = [...$problems, ...self::tierProblems($tier, $label)];
        }

        return $problems;
    }

    /**
     * 単価の設定の値 $price が音声の分単価か(トークンの段でないか)。
     *
     * @param  array<array-key, mixed>  $price
     */
    public static function isAudioMinutePrice(array $price): bool
    {
        return array_key_exists('per_audio_minute', $price);
    }

    /**
     * 予算の区分の名前(表の鍵)と、表が配列でないときの文。
     *
     * @return array{list<string>, list<string>}
     */
    public static function budgetNames(): array
    {
        [$budgets, $problems] = self::table('budget');

        return [array_map(strval(...), array_keys($budgets ?? [])), $problems];
    }

    /**
     * 予算の区分の期間の上限(null は上限なし)と、形の食い違い(表が配列でない・区分が無いか配列でない・数と数の文字列のほか)。
     *
     * @return array{float|null, list<string>}
     */
    public static function budget(string $budget, string $period): array
    {
        [$budgets, $problems] = self::table('budget');
        $limits = $budgets[$budget] ?? null;

        if ($problems !== []) {
            return [null, $problems];
        }

        if (! is_array($limits)) {
            return [null, ["config/ai_runs.php の budget の区分 {$budget} が無いか配列でない"]];
        }

        $value = $limits[$period] ?? null;

        return match (true) {
            $value === null => [null, []],
            is_numeric($value) => [(float) $value, []],
            default => [null, self::numberProblems($value, "budget.{$budget}.{$period}")],
        };
    }

    /**
     * 見積もりの値 $key と、形の食い違い(表が配列でない・数でない)。
     *
     * @return array{float, list<never>}|array{null, non-empty-list<string>}
     */
    public static function estimate(string $key): array
    {
        [$estimate, $problems] = self::table('estimate');

        if ($problems !== []) {
            return [null, $problems];
        }

        $value = $estimate[$key] ?? null;
        $problems = self::numberProblems($value, "estimate.{$key}");

        return $problems === [] ? [(float) $value, []] : [null, $problems];
    }

    /**
     * キューが走っている仕事を配り直すまでの時間(秒)と、正の整数でないときの文。
     *
     * @return array{int, list<never>}|array{null, non-empty-list<string>}
     */
    public static function retryAfter(): array
    {
        $seconds = config('queue.connections.database.retry_after');

        return is_int($seconds) && $seconds > 0 ? [$seconds, []] : [null, [self::RETRY_AFTER_PROBLEM]];
    }

    /**
     * 単価の段の形の食い違い(段の形と、段の単価が数)。
     *
     * @return list<string>
     */
    public static function tierProblems(mixed $tier, string $label): array
    {
        $limit = is_array($tier) && array_key_exists('up_to_input_tokens', $tier) ? $tier['up_to_input_tokens'] : false;

        if (! is_array($tier) || ($limit !== null && ! is_int($limit))) {
            return ["config/ai_runs.php の pricing.{$label} の段の形が合わない"];
        }

        $problems = [];

        foreach (self::TIER_PRICES as $key) {
            $problems = [...$problems, ...self::numberProblems($tier[$key] ?? null, "pricing.{$label}.{$key}")];
        }

        return $problems;
    }

    /**
     * 値が数でないときの文(数なら空)。
     *
     * @return list<string>
     */
    public static function numberProblems(mixed $value, string $label): array
    {
        return is_int($value) || is_float($value) ? [] : ["config/ai_runs.php の {$label} が数でない"];
    }

    /**
     * 音声の分単価 $perMinute が配列で、単価が数か。
     *
     * @return list<string>
     */
    private static function audioProblems(mixed $perMinute, string $label): array
    {
        if (! is_array($perMinute)) {
            return ["config/ai_runs.php の {$label} の形が合わない"];
        }

        $problems = [];

        foreach (self::AUDIO_PRICES as $key) {
            $problems = [...$problems, ...self::numberProblems($perMinute[$key] ?? null, "{$label}.{$key}")];
        }

        return $problems;
    }

    /**
     * 区分ごとの期間の上限の食い違い(表が配列でなければ空 = 表の文は {@see self::problems()} が出す)。
     *
     * @return list<string>
     */
    private static function budgetProblems(): array
    {
        [$names, $tableProblems] = self::budgetNames();
        $problems = [];

        foreach ($tableProblems === [] ? $names : [] as $budget) {
            $region = [];

            foreach (array_keys(AiSettings::BUDGET_PERIODS) as $period) {
                $region = [...$region, ...self::budget($budget, $period)[1]];
            }

            $problems = [...$problems, ...array_unique($region)];
        }

        return $problems;
    }
}
