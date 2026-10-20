<?php

namespace Tests\Unit\UpdateCheck;

use UpdateCheck\ImageCatalog;
use UpdateCheck\ImageReader;

/**
 * イメージの固定(方針 8 節): 土台・ビルド用のイメージは「タグ@digest」で書き、同じイメージを書く場所は同じ値にそろえる。
 */
final class PinnedImagesTest extends UpdateCheckTestCase
{
    public function test_固定しているイメージはすべてタグと_digest_が読める(): void
    {
        foreach (ImageCatalog::all() as $spec) {
            $ref = (new ImageReader(self::root()))->read($spec);

            $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/D', (string) $ref->digest, $spec->label);
        }
    }

    public function test_frankenphp_のタグは_php_のパッチ版まで書く(): void
    {
        $specs = array_values(array_filter(ImageCatalog::all(), static fn ($spec): bool => $spec->label === 'frankenphp'));

        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+-php\d+\.\d+\.\d+-trixie$/D', (new ImageReader(self::root()))->read($specs[0])->tag);
    }

    public function test_dockerfile_の外部のイメージはすべて_digest_付き(): void
    {
        foreach (['Dockerfile', 'docker/db/Dockerfile'] as $file) {
            preg_match_all('/^(?:FROM\s+|COPY\s+--from=)(\S+)/m', (string) file_get_contents(self::root().'/'.$file), $matches);

            foreach ($matches[1] as $image) {
                // 段の名前(base など)は外部のイメージでない
                if (str_contains($image, ':') || str_contains($image, '/')) {
                    $this->assertMatchesRegularExpression('/^\S+:\S+@sha256:[0-9a-f]{64}$/D', $image, "{$file}: {$image}");
                }
            }
        }
    }

    public function test_compose_のイメージのうち外部のものは_digest_付き(): void
    {
        foreach (['compose.yml', 'compose.publish.yml'] as $file) {
            preg_match_all('/^\s+image:\s*(\S+)/m', (string) file_get_contents(self::root().'/'.$file), $matches);

            foreach ($matches[1] as $image) {
                // 自分で build するイメージ(intra-deliver-portfolio-publish-*)は外部のイメージでない
                if (! str_starts_with($image, 'intra-deliver-portfolio-publish-')) {
                    $this->assertMatchesRegularExpression('/^\S+:\S+@sha256:[0-9a-f]{64}$/D', $image, "{$file}: {$image}");
                }
            }
        }
    }

    public function test_土台は_build_の引数で差し替えられ_既定は固定したタグ_digest(): void
    {
        $app = (string) file_get_contents(self::root().'/Dockerfile');
        $db = (string) file_get_contents(self::root().'/docker/db/Dockerfile');
        $compose = (string) file_get_contents(self::root().'/compose.yml');

        preg_match('/^ARG FRANKENPHP_IMAGE=(\S+)$/m', $app, $appArg);
        preg_match('/^ARG PGROONGA_IMAGE=(\S+)$/m', $db, $dbArg);
        preg_match('/image:\s*(groonga\/pgroonga:\S+)/', $compose, $composeImage);

        $this->assertStringContainsString('FROM ${FRANKENPHP_IMAGE} AS base', $app);
        $this->assertStringContainsString('FROM ${PGROONGA_IMAGE}', $db);
        $this->assertMatchesRegularExpression('/^dunglas\/frankenphp:\S+@sha256:[0-9a-f]{64}$/D', $appArg[1] ?? '');
        $this->assertSame($composeImage[1] ?? null, $dbArg[1] ?? '');
    }
}
