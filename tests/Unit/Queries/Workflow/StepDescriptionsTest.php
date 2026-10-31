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
 * 設定から作る文(docs の 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md の 9.3 節の表と、表に無い行 = 基準にする人の役職なし・
 * 分岐終了と同期・横配置と縦配置の「{n} 人」・縦配置の定義の図の人数・配置の仮の札)。期待値は表の文から書いた。DB を使わない。
 * 要約と短い文は同じ {@see StepTarget} の名前を使う。
 */
final class StepDescriptionsTest extends TestCase
{
    /**
     * @return array<string, array{
     *     WorkflowNodeKind, StepTarget|null, WorkflowDecisionRule|null, int|null, string, string|null,
     * }>
     */
    public static function rows(): array
    {
        $fixed = StepTarget::fixed([
            ['type' => 'role', 'id' => 1, 'name' => '一般ユーザー'],
            ['type' => 'user', 'id' => 7, 'name' => '#7'],
        ]);
        $chief = fn (int $min, int $max) => StepTarget::designated('課長', $min, $max);

        return [
            '申請・対象者' => [WorkflowNodeKind::Apply, $fixed, null, null, '一般ユーザー、#7が申請できます。', '一般ユーザー、#7'],
            '承認・対象者' => [WorkflowNodeKind::Approve, $fixed, null, null, '一般ユーザー、#7が承認します。', '一般ユーザー、#7'],
            '承認・基準にする人' => [
                WorkflowNodeKind::Approve,
                StepTarget::relative(WorkflowRelativeBase::Applicant, WorkflowRelativeScope::Parent, 3, '部長'),
                null, null, '申請者の上位組織の部長が承認します。', '申請者の上位組織 + 部長',
            ],
            '承認・基準にする人・所属組織' => [
                WorkflowNodeKind::Approve,
                StepTarget::relative(WorkflowRelativeBase::Applicant, WorkflowRelativeScope::Own, 4, '課長'),
                null, null, '申請者の所属組織の課長が承認します。', '申請者の所属組織 + 課長',
            ],
            '承認・基準にする人・役職なし' => [
                WorkflowNodeKind::Approve,
                StepTarget::relative(WorkflowRelativeBase::Previous, WorkflowRelativeScope::Ancestors, null, null),
                null, null, '前の承認者の上位組織全ての所属の人が承認します。', '前の承認者の上位組織全て',
            ],
            '動的承認' => [WorkflowNodeKind::Approve, $chief(0, 2), null, null, '課長が選択した 0 人から 2 人が承認します。', '課長が選択'],
            '動的承認・最小 = 最大' => [WorkflowNodeKind::Approve, $chief(1, 1), null, null, '課長が選択した 1 人が承認します。', '課長が選択'],
            '横配置' => [WorkflowNodeKind::Serial, $chief(1, 3), null, null, '課長が選択した 1 人から 3 人が、順に承認します。', '承認者は課長が選択'],
            '横配置・最小 = 最大' => [WorkflowNodeKind::Serial, $chief(2, 2), null, null, '課長が選択した 2 人が、順に承認します。', '承認者は課長が選択'],
            '縦配置・人数' => [
                WorkflowNodeKind::Parallel, $chief(2, 5), WorkflowDecisionRule::Count, 2,
                '課長が選択した 2 人から 5 人のうち、2 人が承認すると次へ進みます。', '承認者は課長が選択',
            ],
            '縦配置・割合' => [
                WorkflowNodeKind::Parallel, $chief(2, 5), WorkflowDecisionRule::Ratio, 50,
                '課長が選択した 2 人から 5 人のうち、50% 以上が承認すると次へ進みます。', '承認者は課長が選択',
            ],
            '縦配置・全員・最小 = 最大' => [
                WorkflowNodeKind::Parallel, $chief(3, 3), WorkflowDecisionRule::All, null,
                '課長が選択した 3 人のうち、全員が承認すると次へ進みます。', '承認者は課長が選択',
            ],
            '分岐開始' => [WorkflowNodeKind::BranchStart, null, null, null, '分岐「予算」の条件に該当する先へ、すべて進みます。', null],
            '分岐終了' => [WorkflowNodeKind::BranchEnd, null, null, null, '分岐の枝がここで合流します。', null],
            '同期開始' => [WorkflowNodeKind::SyncStart, null, null, null, '後ろのステップへ並行して進みます。', null],
            '同期終了' => [WorkflowNodeKind::SyncEnd, null, null, null, '並行したステップがすべて終わると次へ進みます。', null],
        ];
    }

    #[DataProvider('rows')]
    public function test_the_summary_and_the_subtitle_come_from_the_same_setting(
        WorkflowNodeKind $kind,
        ?StepTarget $target,
        ?WorkflowDecisionRule $rule,
        ?int $value,
        string $summary,
        ?string $subtitle,
    ): void {
        $this->assertSame($summary, StepDescriptions::summary($kind, '予算', $target, $rule, $value));
        $this->assertSame($subtitle, StepDescriptions::subtitle($kind, $target));
    }

    /**
     * 可変部 = 要約の「が承認します。」「が申請できます。」の前(申請操作の面の再展開の {要約})。
     */
    public function test_the_target_phrase_is_the_variable_part_of_the_summary(): void
    {
        $relative = StepTarget::relative(WorkflowRelativeBase::Applicant, WorkflowRelativeScope::Parent, 3, '部長');
        $fixed = StepTarget::fixed([['type' => 'user', 'id' => 2, 'name' => '中村 美咲']]);

        $this->assertSame('申請者の上位組織の部長', StepDescriptions::targetPhrase($relative));
        $this->assertSame('中村 美咲', StepDescriptions::targetPhrase($fixed));
        $this->assertSame('課長が選択した 1 人から 2 人', StepDescriptions::targetPhrase(StepTarget::designated('課長', 1, 2)));
        $this->assertNull(StepDescriptions::designatorSubtitle(WorkflowNodeKind::Approve, null));
    }

    /**
     * 縦配置の完了の条件(3 種)と、定義の図の人数の範囲。
     */
    public function test_the_consensus_of_the_three_rules_and_the_range_of_the_definition(): void
    {
        $this->assertSame('完了の条件 承認 2 人', StepDescriptions::consensus(WorkflowDecisionRule::Count, 2));
        $this->assertSame('完了の条件 承認 60% 以上', StepDescriptions::consensus(WorkflowDecisionRule::Ratio, 60));
        $this->assertSame('完了の条件 全員が承認', StepDescriptions::consensus(WorkflowDecisionRule::All, null));
        $this->assertSame(
            '完了の条件 承認 2 人　人数 2〜5 人',
            StepDescriptions::definitionConsensus(WorkflowDecisionRule::Count, 2, 2, 5),
        );
    }

    /**
     * @return array<string, array{int, int, list<array{label: string, optional: bool}>}>
     */
    public static function placeholderRanges(): array
    {
        $required = fn (int $number) => ['label' => "承認 {$number}", 'optional' => false];

        return [
            '最小 = 最大' => [2, 2, [$required(1), $required(2)]],
            '最小 0' => [0, 3, [['label' => '承認 1〜3', 'optional' => true]]],
            '最小 0・最大 1' => [0, 1, [['label' => '承認 1', 'optional' => true]]],
            '最小 < 最大' => [1, 5, [$required(1), ['label' => '承認 2〜5', 'optional' => true]]],
            '任意が 1 つ' => [2, 3, [$required(1), $required(2), ['label' => '承認 3', 'optional' => true]]],
        ];
    }

    /**
     * @param  list<array{label: string, optional: bool}>  $expected
     */
    #[DataProvider('placeholderRanges')]
    public function test_the_placeholders_of_a_placement(int $min, int $max, array $expected): void
    {
        $this->assertSame($expected, StepDescriptions::placeholders($min, $max));
    }
}
