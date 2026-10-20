<?php

namespace Tests\Architecture\Engine\PhpStan;

/**
 * 名前空間の接頭辞の集合(汎用)。規則を掛ける対象の名前空間を設定から受けて判定する。
 */
final readonly class NamespacePrefixes
{
    /**
     * @param  list<string>  $prefixes  名前空間(例 = App)。その名前空間自身と配下すべてを指す
     */
    public function __construct(private array $prefixes) {}

    /**
     * 名前空間 $namespace が接頭辞のどれかと同じか、その配下かを判定する。名前空間の無いコード(null)は対象外。
     */
    public function contains(?string $namespace): bool
    {
        if ($namespace === null) {
            return false;
        }

        foreach ($this->prefixes as $prefix) {
            $prefix = trim($prefix, '\\');

            if ($namespace === $prefix || str_starts_with($namespace, $prefix.'\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 接頭辞の一覧を人が読む形で返す(指摘の文に使う)。
     */
    public function describe(): string
    {
        return implode('・', array_map(fn (string $prefix): string => trim($prefix, '\\'), $this->prefixes));
    }
}
