<?php

namespace Tests\Feature\Migrations;

use App\Models\Ai\AiToolCall;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\TransactionalTestCase;

/**
 * 確認の版の列を文字列に広げるマイグレーション(docs の 設計/AI/実行の記録.md 2.4): down は整数(int の範囲)に読める版だけなら戻り、
 * 範囲の外の数と数でない版の行があると何も変えずに止まる。DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class AiToolCallConfirmationVersionMigrationTest extends TransactionalTestCase
{
    private const MIGRATION = '2026_11_17_100001_widen_ai_tool_call_confirmation_version.php';

    protected function setUp(): void
    {
        parent::setUp();
        AiToolCall::query()->whereNotNull('confirmation_version')->delete();
    }

    /** `::integer` で int に読める版(10 桁の値・負の値・前の 0・符号・前後の空白・16 進の 0x を含む)は戻り、列が整数に戻る */
    public function test_down_returns_versions_within_the_integer_range(): void
    {
        $versions = ['1', '1500000000', '2147483647', '-2147483648', '00000000001', '+5', ' 5 ', '0x10'];
        $ids = array_map(fn (string $version): int => $this->asked($version), $versions);

        $this->down();

        $this->assertSame('integer', $this->columnType());
        $this->assertSame([1, 1_500_000_000, 2_147_483_647, -2_147_483_648, 1, 5, 5, 16], array_map(
            fn (int $id): mixed => DB::table('ai_tool_calls')->where('id', $id)->value('confirmation_version'),
            $ids,
        ));
        $this->up();
        $this->assertSame('text', $this->columnType());
    }

    /** int の範囲の外の数・数でない版の行があると止まり、列は文字列のまま */
    public function test_down_stops_on_a_version_outside_the_integer_range(): void
    {
        $outside = ['2147483648', '-2147483649', '12345678901', '+2147483648', ' 2147483648 ', '-02147483649'];

        foreach ([...$outside, '2026-11-17T09:00:00.000000Z', "\u{3000}5", ''] as $version) {
            $id = $this->asked($version);

            $this->assertThrows(
                fn () => $this->down(),
                RuntimeException::class,
                '整数に読めない確認の版の道具の行があるので戻せない',
            );
            $this->assertSame('text', $this->columnType());
            AiToolCall::query()->whereKey($id)->delete();
        }
    }

    private function asked(string $version): int
    {
        return AiToolCall::factory()->asked()->create(['confirmation_version' => $version])->id;
    }

    private function columnType(): string
    {
        return (string) DB::table('information_schema.columns')
            ->where('table_name', 'ai_tool_calls')->where('column_name', 'confirmation_version')->value('data_type');
    }

    private function down(): void
    {
        $this->migrator()->reset([database_path('migrations/'.self::MIGRATION)]);
    }

    private function up(): void
    {
        $this->migrator()->run([database_path('migrations/'.self::MIGRATION)]);
    }

    private function migrator(): Migrator
    {
        return $this->app->make(Migrator::class);
    }
}
