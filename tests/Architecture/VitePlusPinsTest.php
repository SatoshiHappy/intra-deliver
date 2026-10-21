<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * vite-plus だけを上げると、別名が古い core を全体に押し付け、vitest も古いまま黙って残るため、
 * 版・別名・固定を lock に合わせる(docs の 設計/コード構成/検査/README.md の 6.2)。
 */
final class VitePlusPinsTest extends TestCase
{
    private const FIXTURES = __DIR__.'/fixtures/VitePlusPins';

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('roots')]
    public function test_the_pins_follow_the_vite_plus_in_the_lock(string $root, array $expected): void
    {
        $this->assertSame($expected, array_keys(self::mismatches($root)));
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function roots(): array
    {
        return [
            'アプリ' => [dirname(__DIR__, 2), []],
            '正常の見本' => [self::FIXTURES.'/passing', []],
            '違反の見本' => [
                self::FIXTURES.'/violating',
                ['devDependencies.vite', 'overrides.vite', 'overrides.vitest'],
            ],
        ];
    }

    public function test_a_lock_without_the_vite_plus_entry_is_an_error_not_a_mismatch(): void
    {
        $this->expectException(UnexpectedValueException::class);

        self::required(['packages' => []]);
    }

    /**
     * 食い違う項目 => [package.json の値, lock に合う値]。
     *
     * @return array<string, array{mixed, mixed}>
     */
    private static function mismatches(string $root): array
    {
        $package = self::json($root.'/package.json');
        $vitePlus = self::required(self::json($root.'/package-lock.json'));
        $dev = $package['devDependencies'] ?? [];
        $overrides = $package['overrides'] ?? [];
        $requires = $vitePlus['dependencies'] ?? [];
        $pairs = [
            'devDependencies.vite-plus' => [$dev['vite-plus'] ?? null, $vitePlus['version'] ?? null],
            'devDependencies.vite' => [$dev['vite'] ?? null, $requires['vite'] ?? null],
            'overrides.vite' => [$overrides['vite'] ?? null, '$vite'],
            'overrides.vitest' => [$overrides['vitest'] ?? null, $requires['vitest'] ?? null],
        ];

        return array_filter($pairs, static fn (array $pair): bool => $pair[0] !== $pair[1]);
    }

    /**
     * lock の vite-plus の行(無ければ lock が想定の形でないので、版の食い違いとは別に止める)。
     *
     * @param  array<string, mixed>  $lock
     * @return array<string, mixed>
     *
     * @throws UnexpectedValueException lock に vite-plus の行が無いとき
     */
    private static function required(array $lock): array
    {
        $entry = $lock['packages']['node_modules/vite-plus'] ?? null;

        if (! is_array($entry)) {
            throw new UnexpectedValueException('lock に node_modules/vite-plus の行が無い(lock の形か置き場が変わった)');
        }

        return $entry;
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $file): array
    {
        $decoded = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, "{$file} が JSON のオブジェクトでない");

        return $decoded;
    }
}
