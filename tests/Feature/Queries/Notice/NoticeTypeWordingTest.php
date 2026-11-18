<?php

namespace Tests\Feature\Queries\Notice;

use App\Queries\Notice\NoticeTypeCatalog;
use Tests\TestCase;

/**
 * 種類の一覧の全部が画面の語を持つ(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/ルートと文言と検査.md の 8 節の「種類の一覧」)。
 * 語は TS にあり PHP から呼べないので、ソースの case を照らす(ワークフローの種類の照合 WorkflowNoticeCatalogTest と同じ作法)。
 */
final class NoticeTypeWordingTest extends TestCase
{
    private const TYPE_WORDS = 'resources/js/lib/Notice/noticeText.ts';

    private const SURFACE_HEADINGS = 'resources/js/lib/Notice/noticeSettings.ts';

    public function test_every_type_of_the_catalog_has_its_label_case(): void
    {
        $code = $this->sourceOf(self::TYPE_WORDS);

        foreach ($this->types() as $type) {
            $this->assertStringContainsString("case '{$type}':", $code, $type);
        }
    }

    public function test_every_prefix_of_the_catalog_has_its_case_in_the_heading_function(): void
    {
        $code = $this->sourceOf(self::SURFACE_HEADINGS);
        $heading = preg_match('/export function surfaceHeading\(.*?\n}\n/s', $code, $match) === 1 ? $match[0] : '';
        $this->assertNotSame('', $heading);
        $prefixes = array_unique(array_map(
            fn (string $type) => explode('.', $type, 2)[0],
            $this->types(),
        ));

        $this->assertNotSame([], $prefixes);

        foreach ($prefixes as $prefix) {
            $this->assertStringContainsString("case '{$prefix}':", $heading, $prefix);
        }
    }

    /**
     * @return list<string>
     */
    private function types(): array
    {
        return app(NoticeTypeCatalog::class)->all();
    }

    private function sourceOf(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }
}
