<?php

namespace Tests\Architecture\Engine;

use Tests\Architecture\Engine\PhpStan\NamespacePrefixes;
use UnexpectedValueException;

/**
 * 種類ごとの名前空間の一覧(台帳の許可表)が、種類ごとの上限(名前空間。その名前空間と配下)に収まるかを照合する(汎用)。
 */
final class NamespaceBounds
{
    /** 上限の外の名前空間 */
    public const OUTSIDE = 'bounds.outside';

    /** 上限の無い種類 */
    public const UNKNOWN_KIND = 'bounds.unknownKind';

    /**
     * 上限に収まらない名前空間を指摘にする(規則 ID = 種類、file = `種類 名前空間`)。
     *
     * @param  array<string, list<string>>  $bounds  種類 → 上限の名前空間
     * @return list<Finding>
     *
     * @throws UnexpectedValueException 許可表の形が違うとき(種類 → 文字列の一覧でない)
     */
    public static function findings(mixed $table, array $bounds): array
    {
        $findings = [];

        foreach (Values::table($table, '許可表') as $kind => $namespaces) {
            $kind = (string) $kind;

            if (! array_key_exists($kind, $bounds)) {
                $findings[] = new Finding(self::UNKNOWN_KIND, $kind, null);

                continue;
            }

            $limit = new NamespacePrefixes($bounds[$kind]);

            foreach (Values::strings($namespaces, "許可表の {$kind}") as $namespace) {
                if (! $limit->contains(trim($namespace, '\\'))) {
                    $findings[] = new Finding(self::OUTSIDE, "{$kind} {$namespace}", null);
                }
            }
        }

        return $findings;
    }
}
