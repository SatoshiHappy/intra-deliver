<?php

namespace Tests\Unit\Enums\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowCouncilOutcome;
use App\Enums\Workflow\WorkflowDecisionRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 合議の判定の表(docs の 設計/ワークフロー/ルートの深さ/案件の操作/README.md 2.5、同時実行と検査.md 14 節): 可決の条件 × 人数 n × 票の並び。
 * 必要な数 k = 全員 n・人数 K・割合 ⌈n × p / 100⌉(境界 = 割合 50 % で n = 3 は 2 人、n = 4 は 2 人)。a ≥ k → 可決、n − r < k → 可決できなくなった。
 * 数えるのは番号が n 以下の子だけ(区切り 4-2 の修正 #11 で業務処理の CouncilDecision から移した。データの組と期待は同じ)。
 */
final class WorkflowDecisionRuleTest extends TestCase
{
    /**
     * @return array<string, array{WorkflowDecisionRule, int|null, int, int}>
     */
    public static function requiredCounts(): array
    {
        return [
            '全員 n = 2' => [WorkflowDecisionRule::All, null, 2, 2],
            '全員 n = 5' => [WorkflowDecisionRule::All, null, 5, 5],
            '人数 2' => [WorkflowDecisionRule::Count, 2, 5, 2],
            '割合 50 % n = 2' => [WorkflowDecisionRule::Ratio, 50, 2, 1],
            '割合 50 % n = 3' => [WorkflowDecisionRule::Ratio, 50, 3, 2],
            '割合 50 % n = 4' => [WorkflowDecisionRule::Ratio, 50, 4, 2],
            '割合 50 % n = 5' => [WorkflowDecisionRule::Ratio, 50, 5, 3],
            '割合 100 % n = 3' => [WorkflowDecisionRule::Ratio, 100, 3, 3],
            '割合 1 % n = 5' => [WorkflowDecisionRule::Ratio, 1, 5, 1],
        ];
    }

    #[DataProvider('requiredCounts')]
    public function test_the_required_count(WorkflowDecisionRule $rule, ?int $value, int $assignees, int $required): void
    {
        $this->assertSame($required, $rule->required($value, $assignees));
        $this->assertSame($required, $rule->decide($value, $assignees, [])['required']);
    }

    /**
     * 票の並び(A = 承認、R = 否認)の各時点の判定。
     *
     * @return array<string, array{WorkflowDecisionRule, int|null, int, string, list<string>}>
     */
    public static function votes(): array
    {
        return [
            '全員 n = 3: 承認だけ' => [WorkflowDecisionRule::All, null, 3, 'AAA', ['pending', 'pending', 'passed']],
            '全員 n = 3: 1 人の否認で決まる' => [WorkflowDecisionRule::All, null, 3, 'AR', ['pending', 'failed']],
            '人数 2 / n = 3: 承認 2 で可決' => [WorkflowDecisionRule::Count, 2, 3, 'AA', ['pending', 'passed']],
            '人数 2 / n = 3: 否認 1 ではまだ可決できる' => [WorkflowDecisionRule::Count, 2, 3, 'RAA', ['pending', 'pending', 'passed']],
            '人数 2 / n = 3: 否認 2 で可決できない' => [WorkflowDecisionRule::Count, 2, 3, 'ARR', ['pending', 'pending', 'failed']],
            '人数 2 / n = 5: 否認 4 で可決できない' => [WorkflowDecisionRule::Count, 2, 5, 'RRRAR', ['pending', 'pending', 'pending', 'pending', 'failed']],
            '割合 50 % / n = 3: 承認 2' => [WorkflowDecisionRule::Ratio, 50, 3, 'RAA', ['pending', 'pending', 'passed']],
            '割合 50 % / n = 3: 否認 2' => [WorkflowDecisionRule::Ratio, 50, 3, 'RR', ['pending', 'failed']],
            '割合 50 % / n = 4: 承認 2' => [WorkflowDecisionRule::Ratio, 50, 4, 'RAA', ['pending', 'pending', 'passed']],
            '割合 50 % / n = 4: 否認 3' => [WorkflowDecisionRule::Ratio, 50, 4, 'RRR', ['pending', 'pending', 'failed']],
            '全員 n = 2' => [WorkflowDecisionRule::All, null, 2, 'AA', ['pending', 'passed']],
        ];
    }

    /**
     * 子 1〜n の票を番号の順に入れ、各時点の判定を見る(まだ処理していない子は null)。
     *
     * @param  list<string>  $expected
     */
    #[DataProvider('votes')]
    public function test_each_vote_is_judged_by_the_formula(WorkflowDecisionRule $rule, ?int $value, int $assignees, string $votes, array $expected): void
    {
        $actions = array_fill(1, $assignees, null);
        $outcomes = [];

        foreach (str_split($votes) as $index => $vote) {
            $actions[$index + 1] = $vote === 'A' ? WorkflowAction::Approve : WorkflowAction::Reject;
            $outcomes[] = $rule->decide($value, $assignees, $actions)['outcome']->value;
        }

        $this->assertSame($expected, $outcomes);
        $this->assertSame(WorkflowCouncilOutcome::Pending, $rule->decide($value, $assignees, array_fill(1, $assignees, null))['outcome']);
    }

    /**
     * 数えるのは番号が n 以下の子と承認として数える種別だけ(n より大きい番号の子の票・承認終了・差戻しは数えない)。
     */
    public function test_only_children_up_to_n_and_counted_actions_are_counted(): void
    {
        $decision = WorkflowDecisionRule::Count->decide(2, 3, [
            1 => WorkflowAction::Approve, 2 => WorkflowAction::ApproveEarly, 3 => WorkflowAction::Reject, 4 => WorkflowAction::Approve, 5 => WorkflowAction::Reject,
        ]);

        $this->assertSame(['approvals' => 1, 'rejections' => 1, 'required' => 2, 'outcome' => WorkflowCouncilOutcome::Pending], $decision);
    }
}
