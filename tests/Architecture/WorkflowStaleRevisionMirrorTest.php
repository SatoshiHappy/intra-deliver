<?php

namespace Tests\Architecture;

use App\Actions\Workflow\CaseOperationErrors;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 改訂番号の不一致の文言の写しの整合(docs の 設計/ワークフロー/画面.md 6 節・案件の操作/同時実行と検査.md 6 節)。処理のダイアログは、キー case の文言が
 * resources/js/lib/Workflow/workflowLabels.ts の STALE_REVISION_MESSAGE と一致するときに「再表示」を添える。この定数は業務処理の
 * App\Actions\Workflow\CaseOperationErrors::STALE_REVISION の写し(二重の定義を受け入れ、このテストで結ぶ。片方だけ変わると失敗)。
 *
 * 自己証明: 文言を変えた TS で、指摘になること。
 */
final class WorkflowStaleRevisionMirrorTest extends TestCase
{
    public function test_the_screen_mirrors_the_stale_revision_message_of_the_business_process(): void
    {
        $this->assertSame([], self::problems(self::tsSource()));
    }

    public function test_detects_a_message_that_differs(): void
    {
        $changed = str_replace('再表示してください', '開き直してください', self::tsSource());

        $this->assertSame(['文言の宣言が TS に無い: STALE_REVISION'], self::problems($changed));
    }

    /**
     * @return list<string>
     */
    private static function problems(string $ts): array
    {
        $message = preg_quote(CaseOperationErrors::STALE_REVISION, '/');

        return preg_match("/export const STALE_REVISION_MESSAGE =\s*'{$message}';/", $ts) === 1 ? [] : ['文言の宣言が TS に無い: STALE_REVISION'];
    }

    private static function tsSource(): string
    {
        $path = dirname(__DIR__, 2).'/resources/js/lib/Workflow/workflowLabels.ts';
        $source = file_get_contents($path);

        if ($source === false) {
            throw new RuntimeException("{$path} を読めない");
        }

        return $source;
    }
}
