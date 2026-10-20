<?php

namespace Tests\Feature\Queries\Upload;

use App\Queries\Upload\Api\UploadLimits;
use Tests\Support\BuildsUploads;
use Tests\Support\TransactionalTestCase;

/**
 * 添付ファイルの欄の案内の 1 行(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 3 節の最後): 数は配備の設定から、
 * 形式は今の対応表にあるものだけを案内の並びで作る。
 */
final class UploadLimitsGuideTest extends TransactionalTestCase
{
    use BuildsUploads;

    public function test_the_guide_is_built_from_the_settings_and_the_accepted_formats(): void
    {
        $this->limitUploads(99_614_720, 50 * 1_048_576);

        $this->assertSame(
            '1 ファイル 95MB まで、1 回 3 ファイル・合計 50MB までです。'
            .'使用できる形式は PDF・PNG・JPEG・GIF・Word・Excel・PowerPoint・テキスト・CSV・Markdown です。',
            app(UploadLimits::class)->guide(),
        );
    }
}
