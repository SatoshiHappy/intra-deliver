<?php

namespace Tests\Architecture\Engine;

use UnexpectedValueException;

/**
 * 依存の一覧(`composer licenses --format=json` と `npm ls --all --json` の出力)から、パッケージごとのライセンスの記録を作る(汎用)。
 */
final class LicenseInventory
{
    /**
     * @param  array<mixed>  $json  `composer licenses --format=json --no-dev` の出力
     * @return list<LicenseRecord>
     *
     * @throws UnexpectedValueException 形が違うとき
     */
    public static function composer(array $json): array
    {
        $records = [];

        foreach (Values::table($json['dependencies'] ?? null, 'composer licenses の dependencies') as $name => $package) {
            $package = Values::table($package, "composer licenses の {$name}");
            $version = is_string($package['version'] ?? null) ? $package['version'] : '';
            $records[] = new LicenseRecord('composer', (string) $name, $version, self::licenses($package['license'] ?? null));
        }

        return $records;
    }

    /**
     * 空の表 `[]` の項目(任意・peer の依存で入っていないもの)は入っていないので数えない。
     * `missing: true` の項目(必須の依存が入っていない。node_modules が無い・依存が消えたとき)は問題として記録する(失敗)。
     * ライセンスは、npm ls --long が各項目に付ける `path`(実際に入っているディレクトリ)の package.json の license 欄から読む。
     * 同じ名前と版は 1 件にまとめる。`path` が無い項目は問題として報告する(子は引き続き調べる)。
     * `extraneous: true` の項目(依存の辺を持たない = --omit=dev が開発の依存と判定できず最上位に残す)とその子孫は、1 件ずつ lock で確かめる。
     * lock が同じパス・同じ版で `dev: true` とする項目だけ数えず、子は同じ規則で調べ続ける(npm ls は同じ項目の子を最初に出会った 1 か所にしか
     * 出さないので、子ごと外すと、本番の依存の子が外した項目の下にしか無いとき黙って落ちる)。確かめられないもの(lock に無い・版が違う・
     * dev でない・ルートの外)は本番として数える。
     *
     * @param  array<mixed>  $tree  `npm ls --all --json --long --omit=dev` の出力(最上位の `path` = ルート)
     * @param  array<mixed>  $lockPackages  package-lock.json の `packages`(キー = ルートからの相対パス)
     * @return list<LicenseRecord>
     *
     * 実物の npm は本番の依存が無いと `dependencies` キー自体を出さないので、そのときは例外になる(このアプリは本番の依存を必ず持つ)。
     *
     * @throws UnexpectedValueException 形が違うとき
     */
    public static function npm(array $tree, array $lockPackages): array
    {
        $root = $tree['path'] ?? null;

        if (! is_string($root) || $root === '') {
            throw new UnexpectedValueException('npm ls の path(ルート)が無い');
        }

        $found = [];
        self::walk(Values::table($tree['dependencies'] ?? null, 'npm ls の dependencies'), $root, $lockPackages, false, $found);

        return array_values($found);
    }

    /**
     * package.json の license 欄(文字列・旧形式の {type} や配列・licenses の配列)や composer の license 配列を、式の一覧にそろえる。
     *
     * @return list<string>
     */
    public static function licenses(mixed $value): array
    {
        if (is_string($value)) {
            return trim($value) === '' ? [] : [trim($value)];
        }

        if (! is_array($value)) {
            return [];
        }

        $names = [];

        foreach (array_is_list($value) ? $value : [$value] as $item) {
            $names = [...$names, ...self::licenses(is_array($item) ? ($item['type'] ?? null) : $item)];
        }

        return $names;
    }

    /**
     * @param  array<mixed>  $dependencies
     * @param  array<mixed>  $lockPackages
     * @param  array<string, LicenseRecord>  $found
     */
    private static function walk(array $dependencies, string $root, array $lockPackages, bool $underExtraneous, array &$found): void
    {
        foreach ($dependencies as $name => $node) {
            if (is_array($node)) {
                self::visit((string) $name, $node, $root, $lockPackages, $underExtraneous, $found);
            }
        }
    }

    /**
     * 1 項目を記録し、子を調べる。extraneous の項目とその子孫($underExtraneous)のうち、lock が開発の依存と確かめたものは記録しない。
     *
     * @param  array<mixed>  $node
     * @param  array<mixed>  $lockPackages
     * @param  array<string, LicenseRecord>  $found
     */
    private static function visit(string $name, array $node, string $root, array $lockPackages, bool $underExtraneous, array &$found): void
    {
        $underExtraneous = $underExtraneous || ($node['extraneous'] ?? false) === true;
        $record = $underExtraneous && self::isDevInLock($node, $root, $lockPackages) ? null : self::entry($name, $node);

        if ($record !== null) {
            $found[$record->name.'@'.$record->version.'|'.$record->problem] ??= $record;
        }

        if (is_array($node['dependencies'] ?? null)) {
            self::walk($node['dependencies'], $root, $lockPackages, $underExtraneous, $found);
        }
    }

    /**
     * lock(package-lock.json の packages)が、この項目と同じパス・同じ版を開発の依存(`dev: true`)とするか。
     * 例 = wrangler(dev)の推移的な optional の依存 @img/sharp-wasm32(2026-10-14。lock は dev: true、npm ls --omit=dev は最上位に extraneous で出す)。
     *
     * @param  array<mixed>  $node
     * @param  array<mixed>  $lockPackages
     */
    private static function isDevInLock(array $node, string $root, array $lockPackages): bool
    {
        $path = $node['path'] ?? null;
        $version = $node['version'] ?? null;

        if (! is_string($path) || ! is_string($version) || ! str_starts_with($path, $root.'/')) {
            return false;
        }

        $entry = $lockPackages[substr($path, strlen($root) + 1)] ?? null;

        return is_array($entry) && ($entry['dev'] ?? false) === true && ($entry['version'] ?? null) === $version;
    }

    /**
     * npm ls の 1 項目の記録。入っていない任意・peer の依存(版も missing も無い空の表)は null(数えない)。
     * `missing: true` は必須の依存が入っていない = 問題(版は required があればそれ)。
     *
     * @param  array<mixed>  $node
     */
    private static function entry(string $name, array $node): ?LicenseRecord
    {
        if (($node['missing'] ?? false) === true) {
            return new LicenseRecord('npm', $name, is_string($node['required'] ?? null) ? $node['required'] : '(未導入)', [], '入っていない(npm ls が missing)');
        }

        if (! is_string($node['version'] ?? null)) {
            return null;
        }

        return self::record($name, $node['version'], is_string($node['path'] ?? null) ? $node['path'] : null);
    }

    private static function record(string $name, string $version, ?string $directory): LicenseRecord
    {
        if ($directory === null) {
            return new LicenseRecord('npm', $name, $version, [], 'npm ls に path が無い');
        }

        if (! is_file($directory.'/package.json')) {
            return new LicenseRecord('npm', $name, $version, [], 'package.json が無い');
        }

        try {
            $manifest = Values::jsonFile($directory.'/package.json');
        } catch (UnexpectedValueException) {
            return new LicenseRecord('npm', $name, $version, [], 'package.json を読めない');
        }

        // license が空のときだけ旧形式の licenses を読む(結合すると、license の GPL を licenses の MIT で通してしまう)
        $licenses = self::licenses($manifest['license'] ?? null) ?: self::licenses($manifest['licenses'] ?? null);

        return new LicenseRecord('npm', $name, $version, $licenses);
    }
}
