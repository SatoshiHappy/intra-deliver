<?php

namespace Tests\Unit\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Enums\Workflow\WorkflowNodeState;
use App\Queries\Workflow\CaseStateLabels;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 案件の状態の札の語と色のサーバーの 1 か所(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 2.4 節、語と色 = 設計/ワークフロー/画面/ホームと意匠とE2E.md の 8 節。
 * 画面の CaseStateBadge と同じ語と色で、印は付けない)。
 *
 * @phpstan-type Badge array{string, string}
 * @phpstan-type BadgeRow array{WorkflowCaseState, WorkflowCompletionReason|null, WorkflowNodeState|null, Badge}
 */
final class CaseStateLabelsTest extends TestCase
{
    /**
     * @return array<string, BadgeRow>
     */
    public static function badges(): array
    {
        $active = WorkflowCaseState::Active;
        $completed = WorkflowCaseState::Completed;

        return [
            '一時保存' => [WorkflowCaseState::Draft, null, null, ['一時保存', 'settled']],
            '承認待ち' => [$active, null, WorkflowNodeState::Waiting, ['承認待ち', 'pending']],
            '再申請待ち' => [$active, null, WorkflowNodeState::Reapply, ['再申請待ち', 'returned']],
            '保留中' => [$active, null, WorkflowNodeState::Hold, ['保留中', 'returned']],
            '未完了で現在のノードが無い' => [$active, null, null, ['承認待ち', 'pending']],
            '完了(承認)' => [$completed, WorkflowCompletionReason::Approved, null, ['完了(承認)', 'settled']],
            '完了(承認終了)' => [$completed, WorkflowCompletionReason::ApprovedEarly, null, ['完了(承認終了)', 'settled']],
            '完了(否認)' => [$completed, WorkflowCompletionReason::Rejected, null, ['完了(否認)', 'rejected']],
            '完了(取止め)' => [$completed, WorkflowCompletionReason::Withdrawn, null, ['完了(取止め)', 'settled']],
        ];
    }

    /**
     * @param  Badge  $expected
     */
    #[DataProvider('badges')]
    public function test_the_word_and_the_tone_of_the_badge(
        WorkflowCaseState $state,
        ?WorkflowCompletionReason $reason,
        ?WorkflowNodeState $labelState,
        array $expected,
    ): void {
        $this->assertSame($expected, CaseStateLabels::of(1, $state, $reason, $labelState));
    }

    public function test_a_label_state_without_a_word_is_an_inconsistency(): void
    {
        $this->expectException(LogicException::class);

        CaseStateLabels::of(7, WorkflowCaseState::Active, null, WorkflowNodeState::Done);
    }

    public function test_a_completed_case_without_a_reason_is_an_inconsistency(): void
    {
        $this->expectException(LogicException::class);

        CaseStateLabels::of(7, WorkflowCaseState::Completed, null, null);
    }
}
