<?php

namespace Tests\Unit\Queries\Workflow;

use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowRelativeBase;
use App\Enums\Workflow\WorkflowRelativeScope;
use App\Queries\Workflow\StepDescriptions;
use App\Queries\Workflow\StepTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 設定から作る文のうち、処理期限と確認する人の文(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.6 節)と、
 * 図の短い文の強調の区切り(設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md の 9.3 節)。期待値は節の文から書いた。DB を使わない。
 * ほかの文は {@see StepDescriptionsTest}。
 */
final class StepDescriptionsMarksTest extends TestCase
{
    /**
     * @return array<string, array{WorkflowNodeKind, int|null, list<string>, string}>
     */
    public static function deadlineAndConfirmerRows(): array
    {
        $approve = '課長が選択した 1 人から 2 人が承認します。';

        return [
            '承認・期限 3 日・確認 1 人' => [
                WorkflowNodeKind::Approve, 3, ['山田 恵'], $approve.'到達した日から 3 日以内に処理します。山田 恵が確認します。',
            ],
            '承認・期限 0 日・確認 2 人' => [
                WorkflowNodeKind::Approve, 0, ['山田 恵', '中村 美咲'],
                $approve.'到達した日から 0 日以内に処理します。山田 恵、中村 美咲が確認します。',
            ],
            '承認・期限なし・確認なし' => [WorkflowNodeKind::Approve, null, [], $approve.'期限はありません。確認する人はいません。'],
            '申請・確認 1 人(期限を持たない)' => [WorkflowNodeKind::Apply, null, ['山田 恵'], '一般ユーザーが申請できます。山田 恵が確認します。'],
            '申請・確認なし' => [WorkflowNodeKind::Apply, null, [], '一般ユーザーが申請できます。確認する人はいません。'],
            '横配置・期限 99 日(確認する人を持たない)' => [
                WorkflowNodeKind::Serial, 99, [], '課長が選択した 1 人から 2 人が、順に承認します。到達した日から 99 日以内に処理します。',
            ],
            '縦配置・期限なし' => [
                WorkflowNodeKind::Parallel, null, [], '課長が選択した 1 人から 2 人のうち、全員が承認すると次へ進みます。期限はありません。',
            ],
            '分岐開始(どちらも持たない)' => [WorkflowNodeKind::BranchStart, null, [], '分岐「部長」の条件に該当する先へ、すべて進みます。'],
        ];
    }

    /**
     * 処理期限と確認する人の要約の文(案件の運用/画面/管理と通知の画面.md 7.6 節): 持てる種類だけ、指定の文の後に期限・確認する人の順で続ける。
     *
     * @param  list<string>  $confirmerNames
     */
    #[DataProvider('deadlineAndConfirmerRows')]
    public function test_the_deadline_and_the_confirmers_follow_by_node_kind(
        WorkflowNodeKind $kind,
        ?int $deadlineDays,
        array $confirmerNames,
        string $expected,
    ): void {
        $target = match (true) {
            $kind === WorkflowNodeKind::Apply => StepTarget::fixed([
                ['type' => 'role', 'id' => 1, 'name' => '一般ユーザー', 'affiliation' => null],
            ]),
            $kind === WorkflowNodeKind::BranchStart => null,
            default => StepTarget::designated('課長', 1, 2),
        };
        $parts = StepDescriptions::summaryParts(
            $kind, '部長', $target, WorkflowDecisionRule::All, null, false, $deadlineDays, $confirmerNames,
        );

        $this->assertSame([$expected], $parts);
    }

    /**
     * 図の短い文の区切り(9.3 節): 基準にする人は起点と役職名(役職が無ければ「所属の人」)だけが強調の区切り。期限の日数があれば
     * 後ろに「　期限 {n} 日」(短い文が無ければ「期限 {n} 日」だけ)。処理の時に指定は承認の箱も枠も同じ文で、強調しない。
     */
    public function test_the_subtitle_parts_emphasize_the_base_and_the_post_and_carry_the_deadline(): void
    {
        $em = fn (string $text) => ['text' => $text, 'emphasis' => true];
        $relative = fn (?string $post) => StepTarget::relative(
            WorkflowRelativeBase::Applicant, WorkflowRelativeScope::Own, $post === null ? null : 4, $post,
        );

        $this->assertSame([$em('申請者'), 'の所属組織の', $em('課長')], StepDescriptions::subtitle($relative('課長')));
        $this->assertSame(
            [$em('申請者'), 'の所属組織の', $em('所属の人'), '　期限 3 日'],
            StepDescriptions::subtitle($relative(null), 3),
        );
        $this->assertSame(['課長が承認者を選択　期限 0 日'], StepDescriptions::subtitle(StepTarget::designated('課長', 1, 2), 0));
        $this->assertSame(['山田 恵　期限 5 日'], StepDescriptions::subtitle(
            StepTarget::fixed([['type' => 'user', 'id' => 10, 'name' => '山田 恵', 'affiliation' => '経理部']]), 5,
        ));
        $this->assertSame(['期限 3 日'], StepDescriptions::subtitle(null, 3));
        $this->assertNull(StepDescriptions::subtitle(null));
        $this->assertSame(['課長が承認者を選択　期限 3 日'], StepDescriptions::caseSubtitle('課長', 3));
        $this->assertSame(['課長が承認者を選択'], StepDescriptions::caseSubtitle('課長', null));
        $this->assertSame(['期限 2 日'], StepDescriptions::caseSubtitle(null, 2));
    }

    /**
     * 確認の札(7.6 節): 0 人 = 札なし、1 人 =「確認：{名前}」、2 人以上 =「確認：{1 人目} ほか {n − 1} 人」。
     */
    public function test_the_confirm_badge_shows_the_first_confirmer_and_the_rest_count(): void
    {
        $this->assertNull(StepDescriptions::confirmBadge([]));
        $this->assertSame('確認：山田 恵', StepDescriptions::confirmBadge(['山田 恵']));
        $this->assertSame('確認：山田 恵 ほか 1 人', StepDescriptions::confirmBadge(['山田 恵', '中村 美咲']));
        $this->assertSame('確認：山田 恵 ほか 2 人', StepDescriptions::confirmBadge(['山田 恵', '中村 美咲', '#7']));
    }
}
