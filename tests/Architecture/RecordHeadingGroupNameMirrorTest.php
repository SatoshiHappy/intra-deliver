<?php

namespace Tests\Architecture;

use App\Models\Records\RecordFieldHeading;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 固定の見出し「基本情報」の写しの整合(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/見出し.md の 1・4 節)。
 * 画面の resources/js/lib/recordHeadingGroups.ts の HEAD_GROUP_NAME は、見出しの名前の重なりを拒む業務処理が使う
 * RecordFieldHeading::HEAD_GROUP_NAME の写し(二重の定義を受け入れ、このテストで結ぶ。片方だけ変わると、
 * 画面の固定の見出しと同じ名前の見出しを付けられる)。
 *
 * 自己証明: 値を変えた TS で指摘になること。
 */
final class RecordHeadingGroupNameMirrorTest extends TestCase
{
    public function test_the_screen_mirrors_the_fixed_head_name_of_the_business_process(): void
    {
        $this->assertTrue(self::mirrors(self::tsSource()));
    }

    public function test_detects_a_name_that_differs(): void
    {
        $changed = str_replace("'".RecordFieldHeading::HEAD_GROUP_NAME."'", "'基本の情報'", self::tsSource());

        $this->assertFalse(self::mirrors($changed));
    }

    private static function mirrors(string $source): bool
    {
        $declaration = "export const HEAD_GROUP_NAME = '".RecordFieldHeading::HEAD_GROUP_NAME."';";

        return preg_match('/^'.preg_quote($declaration, '/').'$/mu', $source) === 1;
    }

    private static function tsSource(): string
    {
        $path = '/resources/js/lib/recordHeadingGroups.ts';
        $source = file_get_contents(dirname(__DIR__, 2).$path);

        if ($source === false) {
            throw new RuntimeException("{$path} を読めない");
        }

        return $source;
    }
}
