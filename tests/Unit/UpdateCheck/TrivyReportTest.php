<?php

namespace Tests\Unit\UpdateCheck;

use UpdateCheck\TrivyReport;
use UpdateCheck\UnconfirmedException;

/**
 * #5: Trivy の JSON を読んで区分する(修正版があれば要対応、無ければ修正版なし)。構築や検査が確認できなければ、その部分だけ確認できなかった。
 */
final class TrivyReportTest extends UpdateCheckTestCase
{
    private const TRIVY_JSON = [
        'SchemaVersion' => 2,
        'Metadata' => ['OS' => ['Family' => 'debian', 'Name' => '13.1']],
        'Results' => [
            ['Target' => 'os', 'Vulnerabilities' => [
                ['VulnerabilityID' => 'CVE-2', 'PkgName' => 'libb', 'InstalledVersion' => '1', 'Severity' => 'HIGH'],
                ['VulnerabilityID' => 'CVE-1', 'PkgName' => 'liba', 'InstalledVersion' => '1', 'FixedVersion' => '2', 'Severity' => 'HIGH'],
                ['VulnerabilityID' => 'CVE-3', 'PkgName' => 'libc', 'InstalledVersion' => '1', 'FixedVersion' => '3', 'Severity' => 'CRITICAL'],
            ]],
            ['Target' => 'lang'],
        ],
    ];

    public function test_trivy_の_json_から_os_と脆弱性を読む(): void
    {
        $result = TrivyReport::parse((string) json_encode(self::TRIVY_JSON), 'app');

        $this->assertSame(['debian', '13.1'], [$result->osFamily, $result->osName]);
        $this->assertSame(['CVE-2', 'CVE-1', 'CVE-3'], array_map(static fn ($v): string => $v->id, $result->vulnerabilities));
        $this->assertNull($result->vulnerabilities[0]->fixed);
        $this->assertSame('2', $result->vulnerabilities[1]->fixed);
    }

    public function test_結果が空でも_schemaversion_があれば脆弱性_0_件(): void
    {
        $result = TrivyReport::parse('{"SchemaVersion":2}', 'app');

        $this->assertSame([], $result->vulnerabilities);
        $this->assertSame('', $result->osFamily);
    }

    public function test_読めない出力と_schemaversion_が無いか整数でない出力は確認できなかった(): void
    {
        foreach (['', 'FATAL', '{"Results":[]}', '{"SchemaVersion":null}', '{"SchemaVersion":"2"}', '{"SchemaVersion":"invalid"}', '{"SchemaVersion":true}'] as $json) {
            try {
                TrivyReport::parse($json, 'app');
                $this->fail("例外になるはず: {$json}");
            } catch (UnconfirmedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_trivy_の_json_の型が違えば確認できなかった_存在しても型不正を_0_件にしない(): void
    {
        $bad = [
            '{"SchemaVersion":2,"Results":"failed"}',
            '{"SchemaVersion":2,"Results":["x"]}',
            '{"SchemaVersion":2,"Results":[{"Vulnerabilities":"x"}]}',
            '{"SchemaVersion":2,"Results":[{"Vulnerabilities":["x"]}]}',
            '{"SchemaVersion":2,"Results":[{"Vulnerabilities":[{"PkgName":"a","InstalledVersion":"1","Severity":"HIGH"}]}]}',
            '{"SchemaVersion":2,"Results":[{"Vulnerabilities":[{"VulnerabilityID":"CVE-1","PkgName":"a","InstalledVersion":"1","Severity":"HIGH","FixedVersion":[1]}]}]}',
            '{"SchemaVersion":2,"Metadata":"x"}',
            '{"SchemaVersion":2,"Metadata":{"OS":{"Family":1}}}',
        ];

        foreach ($bad as $json) {
            try {
                TrivyReport::parse($json, 'app');
                $this->fail("例外になるはず: {$json}");
            } catch (UnconfirmedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_trivy_が正常でも省く鍵は_0_件や修正版なしとして読む(): void
    {
        $result = TrivyReport::parse('{"SchemaVersion":2,"Results":[{"Target":"a"},{"Vulnerabilities":[{"VulnerabilityID":"CVE-1","PkgName":"a","InstalledVersion":"1","Severity":"HIGH","FixedVersion":null}]}]}', 'app');

        $this->assertCount(1, $result->vulnerabilities);
        $this->assertNull($result->vulnerabilities[0]->fixed);
    }
}
