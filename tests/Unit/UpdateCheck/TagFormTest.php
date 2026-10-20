<?php

namespace Tests\Unit\UpdateCheck;

use PHPUnit\Framework\Attributes\DataProvider;
use UpdateCheck\ImageCatalog;
use UpdateCheck\TagForm;

/**
 * タグの「同じ形」(方針 1 節の #4)。数字の部分だけを入れ替えた形で、系列(PHP の 8.4・PostgreSQL の 18・Node の 22)は今の値のまま。
 */
final class TagFormTest extends UpdateCheckTestCase
{
    /**
     * @return array<string, array{string, string|null, string, bool}>
     */
    public static function cases(): array
    {
        $php = '/-php(\d+\.\d+)\./';

        return [
            'frankenphp 本体の版が違う = 同じ形' => ['1.12.7-php8.4.26-trixie', $php, '1.12.8-php8.4.26-trixie', true],
            'frankenphp PHP のパッチ版が違う = 同じ形' => ['1.12.7-php8.4.26-trixie', $php, '1.12.7-php8.4.27-trixie', true],
            'frankenphp PHP の系列が違う = 違う形' => ['1.12.7-php8.4.26-trixie', $php, '1.13.1-php8.5.11-trixie', false],
            'frankenphp OS が違う = 違う形' => ['1.12.7-php8.4.26-trixie', $php, '1.12.7-php8.4.26-bookworm', false],
            'frankenphp 版を省いた形 = 違う形' => ['1.12.7-php8.4.26-trixie', $php, '1.12.7-php8.4-trixie', false],
            'pgroonga 版が違う = 同じ形' => ['4.0.8-alpine-18', '/-alpine-(\d+)$/D', '4.1.0-alpine-18', true],
            'pgroonga 接尾辞が付く = 違う形' => ['4.0.8-alpine-18', '/-alpine-(\d+)$/D', '4.1.0-alpine-18-slim', false],
            'pgroonga PostgreSQL の系列が違う = 違う形' => ['4.0.8-alpine-18', '/-alpine-(\d+)$/D', '4.1.0-alpine-19', false],
            'node 系列が違う = 違う形' => ['22.23.2-trixie-slim', '/^(\d+)\./', '24.1.0-trixie-slim', false],
            'node パッチ版が違う = 同じ形' => ['22.23.2-trixie-slim', '/^(\d+)\./', '22.23.3-trixie-slim', true],
            'cloudflared 日付の版 = 同じ形' => ['2026.9.3', null, '2026.10.0', true],
            'cloudflared latest = 違う形' => ['2026.9.3', null, 'latest', false],
        ];
    }

    #[DataProvider('cases')]
    public function test_同じ形かどうか(string $current, ?string $series, string $candidate, bool $expected): void
    {
        $this->assertSame($expected, (new TagForm($current, $series))->matches($candidate));
    }

    public function test_newest_は同じ形で今より新しいもののうち最も新しいものを返す(): void
    {
        $form = new TagForm('1.12.7-php8.4.26-trixie', '/-php(\d+\.\d+)\./');
        $tags = ['1.12.7-php8.4.26-trixie', '1.12.8-php8.4.26-trixie', '1.12.7-php8.4.27-trixie', '1.13.1-php8.4.27-trixie', '1.13.1-php8.5.11-trixie', 'latest'];

        $this->assertSame('1.13.1-php8.4.27-trixie', $form->newest($tags));
    }

    public function test_newest_は新しいものが無ければ_null(): void
    {
        $form = new TagForm('2026.10.0', null);

        $this->assertNull($form->newest(['2026.9.3', '2026.10.0', 'latest']));
    }

    public function test_数字の比較は桁数でなく数値(): void
    {
        $form = new TagForm('2.9.0', null);

        $this->assertSame('2.10.0', $form->newest(['2.10.0', '2.9.1']));
    }

    public function test_固定しているイメージの定義は_6_つ(): void
    {
        $this->assertSame(['frankenphp', 'pgroonga', 'cloudflared', 'composer', 'node', 'trivy'], array_map(static fn ($spec): string => $spec->label, ImageCatalog::all()));
    }
}
