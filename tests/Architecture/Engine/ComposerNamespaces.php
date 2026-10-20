<?php

namespace Tests\Architecture\Engine;

use UnexpectedValueException;

/**
 * composer.lock に記録されたパッケージの名前空間と、composer.json のアプリ自身の名前空間を読む(汎用)。
 * 名前空間 = autoload の psr-4 と psr-0 のキー(末尾の \ を外す)。classmap と files だけのパッケージは名前空間を持たない。
 */
final class ComposerNamespaces
{
    /**
     * composer.lock の節 $section(packages = --no-dev で入るもの、packages-dev = require-dev で入るもの)の名前空間を、名前順で返す。
     * アプリ自身の名前空間 $own と重なるもの(同じ・上位・下位)は除く(パッケージの autoload がアプリと同じ名前空間を宣言していても
     * = 例: アプリの形で作られた laravel/pint の App\、アプリのクラスを外部に数えない)。
     *
     * @param  list<string>  $own
     * @return list<non-empty-string>
     *
     * @throws UnexpectedValueException 読めないとき・形が違うとき・空の名前空間(すべてのクラスに一致する)があるとき
     */
    public static function fromLock(string $lockFile, string $section, array $own): array
    {
        $packages = Values::table(Values::jsonFile($lockFile)[$section] ?? null, "{$lockFile} の {$section}");
        $namespaces = [];

        foreach ($packages as $package) {
            array_push($namespaces, ...self::autoloadNamespaces(Values::table($package, "{$lockFile} の {$section} のパッケージ"), $lockFile));
        }

        return self::sorted(array_filter($namespaces, fn (string $namespace): bool => ! self::overlapsAny($namespace, $own)));
    }

    /**
     * composer.json の autoload と autoload-dev の名前空間(アプリ自身の名前空間)を、名前順で返す。
     *
     * @return list<non-empty-string>
     *
     * @throws UnexpectedValueException
     */
    public static function fromManifest(string $manifestFile): array
    {
        $manifest = Values::jsonFile($manifestFile);
        $namespaces = [];

        foreach (['autoload', 'autoload-dev'] as $section) {
            array_push($namespaces, ...self::autoloadNamespaces([$section => $manifest[$section] ?? []], $manifestFile, $section));
        }

        return self::sorted($namespaces);
    }

    /**
     * @param  array<mixed>  $definition
     * @return list<non-empty-string>
     *
     * @throws UnexpectedValueException
     */
    private static function autoloadNamespaces(array $definition, string $file, string $section = 'autoload'): array
    {
        $autoload = Values::table($definition[$section] ?? [], "{$file} の {$section}");
        $namespaces = [];

        foreach (['psr-4', 'psr-0'] as $standard) {
            foreach (array_keys(Values::table($autoload[$standard] ?? [], "{$file} の {$section} の {$standard}")) as $prefix) {
                $namespaces[] = self::namespace((string) $prefix, $file);
            }
        }

        return $namespaces;
    }

    /**
     * @param  list<string>  $others
     */
    private static function overlapsAny(string $namespace, array $others): bool
    {
        foreach ($others as $other) {
            if (str_starts_with($namespace.'\\', $other.'\\') || str_starts_with($other.'\\', $namespace.'\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<non-empty-string>  $namespaces
     * @return list<non-empty-string>
     */
    private static function sorted(array $namespaces): array
    {
        $unique = array_values(array_unique($namespaces));
        sort($unique, SORT_STRING);

        return $unique;
    }

    /**
     * @return non-empty-string
     *
     * @throws UnexpectedValueException
     */
    private static function namespace(string $prefix, string $lockFile): string
    {
        $namespace = trim($prefix, '\\');

        if ($namespace === '') {
            throw new UnexpectedValueException("{$lockFile} に空の名前空間の autoload がある(すべてのクラスに一致するので外部の名前空間にできない)");
        }

        return $namespace;
    }
}
