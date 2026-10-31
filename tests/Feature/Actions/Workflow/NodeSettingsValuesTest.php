<?php

namespace Tests\Feature\Actions\Workflow;

use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowTargetKind;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\SavesNodeSettings;
use Tests\Support\TransactionalTestCase;

/**
 * ステップの設定の欄の値(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 6 節): 役職・指定するステップ・人数の範囲・可決の条件の値・差戻しできる先。
 * 版 = R1(承認 section_chief = 起点で指定、owner = 処理の時に指定 1〜1 人、縦配置 council = 2〜5 人・人数 2)。数は境界とその両側を通す。
 * 期待の文言は 6 節の表から書いた。どの誤りも何も変えない。
 */
final class NodeSettingsValuesTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use SavesNodeSettings;

    private const RANGE = '人数の最小は 0 から 20、最大は 1 から 20 の範囲で、最小は最大以下にしてください';

    private const COUNT = '必要な人数は 1 から人数の最小までの範囲で入力してください';

    private const RATIO = '必要な割合は 1 から 100 の範囲で入力してください';

    private int $r1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->r1 = $this->addVersion($this->r1());
    }

    /**
     * 役職・指定するステップ・差戻しできる先が無い、指定するステップと人数が空(定義の管理.md の 6 節)。
     */
    public function test_a_company_post_a_designator_and_send_backs_must_exist(): void
    {
        $designator = '処理対象者を指定するステップには、このステップより前の、必ず通り省略されない承認のステップを選択してください';

        $this->assertErrors(['company_post_id' => '役職を選択してください'], 'section_chief', ['companyPostId' => 999_999_999]);
        $this->assertErrors(['designator_node_code' => $designator], 'owner', ['designatorNodeCode' => 'nobody']);
        $this->assertErrors(
            ['designator_node_code' => $designator, 'min_assignees' => self::RANGE, 'max_assignees' => self::RANGE],
            'owner', ['designatorNodeCode' => null, 'minAssignees' => null, 'maxAssignees' => null],
        );
        $sendBack = '差戻しできる先には、このステップに至る経路上の、申請または承認のステップ(横配置・縦配置を含む)を選択してください';
        $this->assertErrors(
            ['send_back_node_codes' => $sendBack], 'owner', ['sendBackNodeCodes' => ['apply', 'nobody']],
        );
    }

    /**
     * 人数の範囲の境界(最小 -1・0・20・21、最大 0・1・20・21)と、最小 > 最大は最小にだけ出ること(定義の管理.md の 6 節)。
     */
    public function test_the_assignee_range_and_its_boundaries(): void
    {
        $cases = [
            [['minAssignees' => -1], ['min_assignees' => self::RANGE]],
            [['minAssignees' => 21, 'maxAssignees' => 20], ['min_assignees' => self::RANGE]],
            [['maxAssignees' => 0], ['max_assignees' => self::RANGE]],
            [['maxAssignees' => 21], ['max_assignees' => self::RANGE]],
            [['minAssignees' => 3, 'maxAssignees' => 2], ['min_assignees' => self::RANGE]],
            [['minAssignees' => -1, 'maxAssignees' => 0], [
                'min_assignees' => self::RANGE, 'max_assignees' => self::RANGE,
            ]],
        ];

        foreach ($cases as [$overrides, $expected]) {
            $this->assertErrors($expected, 'owner', $overrides);
        }

        foreach ([[0, 1], [20, 20], [2, 2], [0, 20]] as [$min, $max]) {
            $this->saveSettings($this->r1, 'owner', ['minAssignees' => $min, 'maxAssignees' => $max]);
            $node = $this->routeNode($this->r1, 'owner');
            $this->assertSame([$min, $max], [$node->min_assignees, $node->max_assignees]);
        }
    }

    /**
     * 必要な人数の境界(0・1・最小・最小 + 1)と最小 0、最小や指定の種類の誤りのときは確かめないこと(定義の管理.md の 6 節)。
     */
    public function test_the_count_value_and_the_minimum_of_a_count_rule(): void
    {
        $this->assertErrors(['decision_value' => self::COUNT], 'council', ['decisionValue' => 0]);
        $this->assertErrors(['decision_value' => self::COUNT], 'council', ['decisionValue' => 3]);
        $this->assertErrors(['min_assignees' => '可決の条件を人数にする場合は、人数の最小を 1 以上にしてください'], 'council', [
            'minAssignees' => 0, 'decisionValue' => 5,
        ]);
        $this->assertErrors(['min_assignees' => self::RANGE], 'council', ['minAssignees' => 25, 'decisionValue' => 99]);
        $this->assertErrors(['target_kind' => 'このステップには、この設定はできません'], 'council', [
            'targetKind' => WorkflowTargetKind::Fixed, 'targets' => [$this->userCondition('u0002')],
            'minAssignees' => 3, 'decisionValue' => 5,
        ]);

        foreach ([1, 2] as $value) {
            $this->saveSettings($this->r1, 'council', ['decisionValue' => $value]);
            $this->assertSame($value, $this->routeNode($this->r1, 'council')->decision_value);
        }
    }

    /**
     * 必要な割合の境界(空・0・1・100・101。定義の管理.md の 6 節)。
     */
    public function test_the_ratio_value_and_its_boundaries(): void
    {
        $ratio = ['decisionRule' => WorkflowDecisionRule::Ratio];

        foreach ([null, 0, 101] as $value) {
            $this->assertErrors(['decision_value' => self::RATIO], 'council', [...$ratio, 'decisionValue' => $value]);
        }

        foreach ([1, 100] as $value) {
            $this->saveSettings($this->r1, 'council', [...$ratio, 'decisionValue' => $value]);
            $this->assertSame($value, $this->routeNode($this->r1, 'council')->decision_value);
        }
    }

    /**
     * @param  array<string, string>  $expected
     * @param  array<string, mixed>  $overrides
     */
    private function assertErrors(array $expected, string $nodeCode, array $overrides): void
    {
        $before = $this->settingsSnapshot();
        $errors = $this->validationErrors(fn () => $this->saveSettings($this->r1, $nodeCode, $overrides));

        ksort($expected);
        ksort($errors);
        $this->assertSame($expected, $errors, json_encode($overrides) ?: $nodeCode);
        $this->assertSame($before, $this->settingsSnapshot());
    }
}
