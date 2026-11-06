<?php

namespace Tests\Feature\Queries\Workflow;

use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CaseDetailQuery;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Tests\Support\TransactionalTestCase;

/**
 * 案件の図の短い文と確認の札(docs の 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md 8 節・9.3 節、
 * 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md 7.6 節): 短い文 = 処理の時に指定するノードの文「{名前}が承認者を選択」と
 * 写した期限の日数(展開の行は枠が出すので持たない)、確認の札 = 写した確認する人。期待値は初期データの見本 WF-000009(版 3 = 課長・部長は
 * 確認する人 山田 恵、期限は課長 3・部長 5・合議 3・責任者 3 日 = 案件の運用/初期データ.md 3 節)から書く。
 */
final class CaseDiagramMarksTest extends TransactionalTestCase
{
    public function test_the_subtitles_carry_the_deadline_and_the_badges_the_confirmers(): void
    {
        $nodes = $this->detailOf(9, 'u0010')['nodes'];
        $text = fn (string|array $part) => is_string($part) ? $part : $part['text'];
        $subtitles = array_map(
            fn (array $node) => $node['subtitle'] === null ? null : implode('', array_map($text, $node['subtitle'])),
            $nodes,
        );

        $this->assertSame([
            '申請' => null, '課長' => '期限 3 日', '予算' => null, '部長' => '期限 5 日', '分岐終了' => null,
            '関係部署の合議' => '課長が承認者を選択　期限 3 日', '関係部署の合議 承認 1' => null,
            '関係部署の合議 承認 2' => null, '関係部署の合議 承認 3' => null, '責任者' => '課長が承認者を選択　期限 3 日',
        ], array_combine(array_column($nodes, 'name'), $subtitles));
        $this->assertSame(
            [null, '確認：山田 恵', null, '確認：山田 恵', null, null, null, null, null, null],
            array_column($nodes, 'confirmBadge'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function detailOf(int $number, string $viewer): array
    {
        $user = User::query()->where('code', $viewer)->sole();
        $today = CarbonImmutable::today(AccessContext::TIMEZONE);
        $context = new AccessContext($user->id, null, null, $today, AccessContext::LOCALE_CODE);

        $caseId = WorkflowCase::query()->where('case_number', $number)->sole()->id;

        return app(CaseDetailQuery::class)->show($context, $caseId);
    }
}
