<?php

namespace Tests\Architecture\Engine;

use UnexpectedValueException;

/**
 * gitleaks の設定(.gitleaks.toml を配列にしたもの)の除外を読む(汎用)。
 *
 * 対象 = 全体と規則ごとの allowlist(旧形式の `[allowlist]` と `[[allowlists]]`・`[[rules.allowlists]]`)と `[extend]` の disabledRules。
 * - paths = 正規表現を書いたまま(pattern。台帳の文字列と完全一致で照合する)
 * - regexes・stopwords・commits と disabledRules = rule
 * - 除外の効き方を変えるだけの項目(description・condition・regexTarget・targetRules)は数えない
 * - それ以外の項目は、登録の形を持たない除外として種類 unknown にする(台帳に登録できないので常に未登録)
 *
 * `[extend]` の path(取り込む設定ファイル)は {@see self::extendPath()} で書かれたまま読む。取り込んだ設定の除外はここでは数えない
 * (取り込んだ側の設定を別に読んで照合する)。
 */
final class GitleaksConfig
{
    private const MODIFIERS = ['description', 'condition', 'regexTarget', 'targetRules'];

    private const RULE_KEYS = ['regexes', 'stopwords', 'commits'];

    /**
     * @param  array<mixed>  $config
     * @return list<array{key: string, kind: string, value: string}>
     *
     * @throws UnexpectedValueException 形が違うとき
     */
    public static function exclusions(array $config): array
    {
        $exclusions = [];

        foreach (self::allowlists($config) as $allowlist) {
            foreach ($allowlist as $key => $value) {
                array_push($exclusions, ...self::allowlistEntry((string) $key, $value));
            }
        }

        $extend = Values::table($config['extend'] ?? [], 'gitleaks の extend');

        foreach (Values::optionalStrings($extend, 'disabledRules', 'gitleaks の extend') as $rule) {
            $exclusions[] = ['key' => 'disabledRules', 'kind' => 'rule', 'value' => $rule];
        }

        return $exclusions;
    }

    /**
     * `[extend]` の path(書かれたまま。gitleaks はプロセスの作業ディレクトリ基点で解決する)。書かれていなければ null。
     *
     * @param  array<mixed>  $config
     *
     * @throws UnexpectedValueException 形が違うとき
     */
    public static function extendPath(array $config): ?string
    {
        $extend = Values::table($config['extend'] ?? [], 'gitleaks の extend');

        if (! array_key_exists('path', $extend)) {
            return null;
        }

        if (! is_string($extend['path'])) {
            throw new UnexpectedValueException('gitleaks の extend の path が文字列でない');
        }

        return $extend['path'];
    }

    /**
     * @param  array<mixed>  $config
     * @return list<array<mixed>>
     *
     * @throws UnexpectedValueException
     */
    private static function allowlists(array $config): array
    {
        $owners = [$config, ...Values::table($config['rules'] ?? [], 'gitleaks の rules')];
        $allowlists = [];

        foreach ($owners as $owner) {
            $owner = Values::table($owner, 'gitleaks の規則');

            if (array_key_exists('allowlist', $owner)) {
                $allowlists[] = Values::table($owner['allowlist'], 'gitleaks の allowlist');
            }

            foreach (Values::table($owner['allowlists'] ?? [], 'gitleaks の allowlists') as $allowlist) {
                $allowlists[] = Values::table($allowlist, 'gitleaks の allowlists の要素');
            }
        }

        return $allowlists;
    }

    /**
     * @return list<array{key: string, kind: string, value: string}>
     *
     * @throws UnexpectedValueException
     */
    private static function allowlistEntry(string $key, mixed $value): array
    {
        if (in_array($key, self::MODIFIERS, true)) {
            return [];
        }

        $kind = match (true) {
            $key === 'paths' => 'pattern',
            in_array($key, self::RULE_KEYS, true) => 'rule',
            default => 'unknown',
        };

        if ($kind === 'unknown') {
            return [['key' => $key, 'kind' => $kind, 'value' => (string) json_encode($value)]];
        }

        return array_map(
            fn (string $item): array => ['key' => $key, 'kind' => $kind, 'value' => $item],
            Values::strings($value, "gitleaks の allowlist の {$key}"),
        );
    }
}
