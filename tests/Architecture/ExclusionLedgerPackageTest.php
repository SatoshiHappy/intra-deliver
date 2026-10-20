<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Architecture\Engine\ExclusionLedger;
use UnexpectedValueException;

/**
 * 検査の除外の台帳(ExclusionLedger)の package の行(依存のライセンス検査 #7 の期限つきの除外)の検証。
 * 期限 expires を必ず持つ・scope を持てない・#7 の下にだけ置ける・同じ値を重ねて書けない。
 */
final class ExclusionLedgerPackageTest extends TestCase
{
    public function test_package_rows_carry_an_expiry_and_are_read_as_package_expirations(): void
    {
        $ledger = ExclusionLedger::fromArray(['#7' => [
            ['package' => 'composer:a/b', 'expires' => '2026-12-31', 'reason' => 'r'],
            ['path' => 'vendor/**', 'reason' => 'r'],
        ]],
            '#7',
        );

        $this->assertSame(['composer:a/b' => '2026-12-31'], $ledger->packageExpirations('#7'));
        $this->assertSame([], $ledger->packageExpirations('#6'));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    #[DataProvider('invalidPackageRows')]
    public function test_a_package_row_without_a_valid_expiry_and_an_expiry_on_another_kind_are_invalid_rows(array $row): void
    {
        $this->expectException(UnexpectedValueException::class);

        ExclusionLedger::fromArray(['#7' => [$row]], '#7');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidPackageRows(): array
    {
        return [
            'no expiry' => [['package' => 'npm:a', 'reason' => 'r']],
            'not a date' => [['package' => 'npm:a', 'expires' => '2026-13-01', 'reason' => 'r']],
            'not zero padded' => [['package' => 'npm:a', 'expires' => '2026-1-1', 'reason' => 'r']],
            'not a string' => [['package' => 'npm:a', 'expires' => 20261231, 'reason' => 'r']],
            'expiry on a path row' => [['path' => 'a', 'expires' => '2026-12-31', 'reason' => 'r']],
            'no prefix' => [['package' => 'left-pad', 'expires' => '2026-12-31', 'reason' => 'r']],
            'misspelled prefix' => [['package' => 'compser:x/y', 'expires' => '2026-12-31', 'reason' => 'r']],
            'trailing newline' => [['package' => "npm:left-pad\n", 'expires' => '2026-12-31', 'reason' => 'r']],
            'prefix only' => [['package' => 'npm:', 'expires' => '2026-12-31', 'reason' => 'r']],
            'scope on a package row' => [['package' => 'npm:a', 'expires' => '2026-12-31', 'scope' => 'dir', 'reason' => 'r']],
        ];
    }

    public function test_duplicate_package_rows_are_invalid(): void
    {
        $this->expectException(UnexpectedValueException::class);

        ExclusionLedger::fromArray(['#7' => [
            ['package' => 'npm:a', 'expires' => '2026-12-31', 'reason' => 'r'],
            ['package' => 'npm:a', 'expires' => '2027-12-31', 'reason' => 'r'],
        ]],
            '#7',
        );
    }

    public function test_a_package_row_under_another_check_is_invalid(): void
    {
        $this->expectException(UnexpectedValueException::class);

        ExclusionLedger::fromArray(['#6' => [['package' => 'npm:a', 'expires' => '2026-12-31', 'reason' => 'r']]], '#7');
    }
}
