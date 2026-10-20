<?php

namespace Tests\Architecture;

use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Architecture\Policy\SideEffects;

/**
 * Architecture suite の smoke。
 *
 * 台帳と検査の値の 3 ファイルが配列を返し、必須キーがそろっていることを確かめる。
 * app/ 直下の基底ディレクトリと台帳① の照合は、ほかの置き場の規則と一緒に tests/Architecture/PlacementTest.php で行う。
 * 流用元 = chatpinet_2 の同名のテスト(必須キーを持つ台帳に合わせた)。
 */
final class SmokeTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function ledgerFiles(): array
    {
        return [
            'guardrails' => ['guardrails', [
                'secrets_baseline',
                'artifacts_dir',
                'test_db_suffix',
            ]],
            'guardrails_allow' => ['guardrails_allow', [
                'base_directories',
                ...array_map(fn (string $kind): string => 'capabilities.'.$kind, SideEffects::KINDS),
                'exclusions',
            ]],
            'guardrails_ledger' => ['guardrails_ledger', [
                'dependencies.composer',
                'dependencies.npm',
            ]],
        ];
    }

    /**
     * @param  list<string>  $requiredKeys
     */
    #[DataProvider('ledgerFiles')]
    public function test_ledger_file_returns_array_with_required_keys(string $name, array $requiredKeys): void
    {
        $ledger = self::ledger($name);

        foreach ($requiredKeys as $key) {
            $this->assertTrue(Arr::has($ledger, $key), "config/{$name}.php に必須キー {$key} が無い");
        }
    }

    /**
     * 台帳ファイルを読み、配列を返すことを確かめる。
     *
     * @return array<string, mixed>
     */
    private static function ledger(string $name): array
    {
        $ledger = require self::appRoot()."/config/{$name}.php";

        self::assertIsArray($ledger, "config/{$name}.php が配列を返さない");

        return $ledger;
    }

    private static function appRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
