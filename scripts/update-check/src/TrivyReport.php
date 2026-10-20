<?php

namespace UpdateCheck;

/**
 * Trivy の JSON(--format json)から、脆弱性と OS を読む。出力の形で判定し、終了コードは使わない。
 * 要る鍵: SchemaVersion(整数)、各脆弱性の VulnerabilityID・PkgName・InstalledVersion・Severity(文字列)。
 * 任意の鍵(正常でも省かれる): Results(無ければ 0 件)、各結果の Vulnerabilities(無ければ 0 件)、FixedVersion(無ければ修正版なし)、Metadata.OS(無ければ OS 不明)。
 * 任意の鍵でも、あれば型が合っていなければならない(型が違えば確認できなかった。黙って 0 件にしない)。
 */
final class TrivyReport
{
    /**
     * @throws UnconfirmedException 読めない・要る鍵が無い・型が違う
     */
    public static function parse(string $json, string $image): ScanResult
    {
        $what = "trivy({$image})";
        $data = Json::parse($json, $what);

        if (! is_int($data['SchemaVersion'] ?? null)) {
            throw new UnconfirmedException("{$what} の出力に SchemaVersion が無い(または整数でない)");
        }

        $metadata = Json::optionalTable($data, 'Metadata', $what) ?? [];
        $os = Json::optionalTable($metadata, 'OS', "{$what} の Metadata") ?? [];
        $results = Json::tables(Json::optionalTable($data, 'Results', $what) ?? [], "{$what} の Results");

        return new ScanResult(
            $image,
            self::vulnerabilities($results, $what),
            Json::nullableString($os, 'Family', "{$what} の Metadata.OS") ?? '',
            Json::nullableString($os, 'Name', "{$what} の Metadata.OS") ?? '',
        );
    }

    /**
     * @param  list<array<array-key, mixed>>  $results
     * @return list<Vulnerability>
     *
     * @throws UnconfirmedException
     */
    private static function vulnerabilities(array $results, string $what): array
    {
        $found = [];

        foreach ($results as $result) {
            $items = Json::tables(Json::optionalTable($result, 'Vulnerabilities', "{$what} の Results") ?? [], "{$what} の Vulnerabilities");

            foreach ($items as $item) {
                $found[] = self::vulnerability($item, $what);
            }
        }

        return $found;
    }

    /**
     * @param  array<array-key, mixed>  $item
     *
     * @throws UnconfirmedException
     */
    private static function vulnerability(array $item, string $what): Vulnerability
    {
        $part = "{$what} の脆弱性";
        $fixed = Json::nullableString($item, 'FixedVersion', $part);

        return new Vulnerability(
            Json::string($item, 'VulnerabilityID', $part),
            Json::string($item, 'PkgName', $part),
            Json::string($item, 'InstalledVersion', $part),
            $fixed === null || $fixed === '' ? null : $fixed,
            Json::string($item, 'Severity', $part),
        );
    }
}
