<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowRelativeBase;
use App\Enums\Workflow\WorkflowRelativeScope;
use App\Enums\Workflow\WorkflowTargetKind;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\SavesNodeSettings;
use Tests\Support\TransactionalTestCase;

/**
 * ステップの設定の欄の要否(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 5.1・6 節。ノードの種類ごとに要る欄・受ける欄・受けない欄)。
 * 版 = R1(申請 apply・承認 section_chief(起点で指定)と owner(処理の時に指定)・縦配置 council・分岐 budget / budget_end)と
 * 横配置の版(family)。期待の文言は 6 節の表から書いた。どの誤りも何も変えない。
 */
final class NodeSettingsFieldsTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use SavesNodeSettings;

    private const NOT_ALLOWED = 'このステップには、この設定はできません';

    private int $r1;

    private int $serial;

    protected function setUp(): void
    {
        parent::setUp();
        $this->r1 = $this->addVersion($this->r1());
        $this->serial = $this->addVersion($this->placementRoute(WorkflowNodeKind::Serial), '2026-06-01');
    }

    /**
     * 指定の種類の要否と許す値と、その誤りのとき指定の種類で決まる欄を確かめないこと(定義の管理.md の 5.1 節)。
     */
    public function test_the_target_kind_by_node_kind(): void
    {
        $required = ['target_kind' => '指定の種類を選択してください'];
        $notAllowed = ['target_kind' => self::NOT_ALLOWED];
        $fixed = ['targetKind' => WorkflowTargetKind::Fixed, 'targets' => [$this->userCondition('u0002')]];
        $relative = ['targetKind' => WorkflowTargetKind::Relative];

        $this->assertErrors($required, $this->r1, 'apply', ['targetKind' => null, 'targets' => null]);
        $this->assertErrors($notAllowed, $this->r1, 'apply', $relative);
        $this->assertErrors($required, $this->r1, 'section_chief', ['targetKind' => null]);
        $this->assertErrors($required, $this->serial, 'family', ['targetKind' => null, 'minAssignees' => null]);
        $this->assertErrors($notAllowed, $this->serial, 'family', $relative);
        $this->assertErrors($notAllowed, $this->r1, 'council', [...$fixed, 'maxAssignees' => 99]);
        $missing = [new SelectionCondition(SelectionConditionKind::User, 999_999_999)];
        $this->assertErrors($notAllowed, $this->serial, 'family', [...$fixed, 'targets' => $missing]);
        $this->assertErrors(
            [...$notAllowed, 'prevents_applicant_approval' => self::NOT_ALLOWED],
            $this->r1, 'budget', [...$fixed, 'preventsApplicantApproval' => true],
        );
    }

    /**
     * 指定の種類で決まる欄(対象者の行から最小・最大まで)の要否(定義の管理.md の 5.1 節)。
     */
    public function test_the_fields_decided_by_the_target_kind(): void
    {
        $relative = ['relativeBase' => WorkflowRelativeBase::Applicant, 'relativeScope' => WorkflowRelativeScope::Own];
        $designation = ['designatorNodeCode' => 'apply', 'minAssignees' => 1, 'maxAssignees' => 1];
        $targets = [$this->userCondition('u0002')];
        $post = $this->postId('manager');
        $na = fn (string ...$keys) => array_fill_keys($keys, self::NOT_ALLOWED);
        $noTargets = ['targets' => '対象者を 1 件以上指定してください'];

        $this->assertErrors($noTargets, $this->r1, 'apply', ['targets' => []]);
        $this->assertErrors($noTargets, $this->r1, 'section_chief', [
            'targetKind' => WorkflowTargetKind::Fixed, 'targets' => null,
            'relativeBase' => null, 'relativeScope' => null, 'companyPostId' => null,
        ]);
        $this->assertErrors(
            $na('targets', 'designator_node_code', 'min_assignees', 'max_assignees'),
            $this->r1, 'section_chief', ['targets' => $targets, ...$designation],
        );
        $this->assertErrors(
            ['relative_base' => '基準にする人を選択してください', 'relative_scope' => '組織を選択してください'],
            $this->r1, 'section_chief', ['relativeBase' => null, 'relativeScope' => null],
        );
        $this->assertErrors(
            $na('relative_base', 'relative_scope', 'company_post_id'),
            $this->r1, 'owner', [...$relative, 'companyPostId' => $post],
        );
        $this->assertErrors(
            $na('targets', 'relative_base'),
            $this->serial, 'family', ['targets' => $targets, 'relativeBase' => WorkflowRelativeBase::Applicant],
        );
        $this->assertErrors(
            $na('company_post_id', 'designator_node_code'),
            $this->r1, 'apply', ['companyPostId' => $post, 'designatorNodeCode' => 'apply'],
        );
        $this->assertErrors($na('targets', 'relative_scope', 'company_post_id', 'min_assignees'), $this->r1, 'budget', [
            'targets' => $targets, 'relativeScope' => WorkflowRelativeScope::Own,
            'companyPostId' => $post, 'minAssignees' => 1,
        ]);

        $this->saveSettings($this->r1, 'section_chief', ['companyPostId' => null]);
        $this->assertNull($this->routeNode($this->r1, 'section_chief')->company_post_id);
    }

    /**
     * 申請者本人の承認を防ぐ・差戻しできる先を受けるノード(定義の管理.md の 5.1 節)。ほかの欄の誤りと一緒に返る。
     */
    public function test_the_prevention_and_the_send_backs_by_node_kind(): void
    {
        $both = ['prevents_applicant_approval' => self::NOT_ALLOWED, 'send_back_node_codes' => self::NOT_ALLOWED];
        $filled = ['preventsApplicantApproval' => true, 'sendBackNodeCodes' => ['apply']];

        $this->assertErrors(
            [...$both, 'decision_rule' => self::NOT_ALLOWED],
            $this->r1, 'apply', [...$filled, 'decisionRule' => WorkflowDecisionRule::All],
        );
        $this->assertErrors($both, $this->r1, 'budget_end', $filled);

        $this->saveSettings($this->r1, 'apply', ['preventsApplicantApproval' => false, 'sendBackNodeCodes' => []]);
        $this->saveSettings($this->r1, 'section_chief', $this->filled(null, null));
        $this->saveSettings($this->serial, 'family', $this->filled(true, ['chief']));
        $this->assertSame([false, true], [
            $this->routeNode($this->r1, 'section_chief')->prevents_applicant_approval,
            $this->routeNode($this->serial, 'family')->prevents_applicant_approval,
        ]);
    }

    /**
     * 可決の条件と値の要否と、可決の条件の誤りのとき値を確かめないこと(定義の管理.md の 5.1 節)。
     */
    public function test_the_decision_rule_and_value_by_node_kind(): void
    {
        $value = ['decision_value' => self::NOT_ALLOWED];
        $rule = ['decision_rule' => self::NOT_ALLOWED];
        $all = WorkflowDecisionRule::All;
        $countRule = WorkflowDecisionRule::Count;
        $ratioRule = WorkflowDecisionRule::Ratio;
        $count = ['decision_value' => '必要な人数は 1 から人数の最小までの範囲で入力してください'];

        $this->assertErrors(
            ['decision_rule' => '可決の条件を選択してください'],
            $this->r1, 'council', ['decisionRule' => null, 'decisionValue' => 999],
        );
        $this->assertErrors($value, $this->r1, 'council', ['decisionRule' => $all, 'decisionValue' => 2]);
        $this->assertErrors($count, $this->r1, 'council', ['decisionValue' => null]);
        $this->assertErrors($rule, $this->r1, 'owner', ['decisionRule' => $countRule, 'decisionValue' => 1]);
        $this->assertErrors($value, $this->r1, 'owner', ['decisionValue' => 1]);
        $this->assertErrors($rule, $this->serial, 'family', ['decisionRule' => $all, 'decisionValue' => 1]);
        $this->assertErrors($value, $this->serial, 'family', ['decisionValue' => 1]);
        $this->assertErrors($rule, $this->r1, 'budget', ['decisionRule' => $ratioRule, 'decisionValue' => 50]);

        $this->saveSettings($this->r1, 'council', ['decisionRule' => $all, 'decisionValue' => null]);
        $council = $this->routeNode($this->r1, 'council');
        $this->assertSame([$all, null], [$council->decision_rule, $council->decision_value]);
    }

    /**
     * 申請者本人の承認を防ぐ・差戻しできる先の入力。
     *
     * @param  list<string>|null  $sendBackNodeCodes
     * @return array<string, mixed>
     */
    private function filled(?bool $prevents, ?array $sendBackNodeCodes): array
    {
        return ['preventsApplicantApproval' => $prevents, 'sendBackNodeCodes' => $sendBackNodeCodes];
    }

    /**
     * @param  array<string, string>  $expected
     * @param  array<string, mixed>  $overrides
     */
    private function assertErrors(array $expected, int $versionId, string $nodeCode, array $overrides): void
    {
        $before = $this->settingsSnapshot();
        $errors = $this->validationErrors(fn () => $this->saveSettings($versionId, $nodeCode, $overrides));

        ksort($expected);
        ksort($errors);
        $this->assertSame($expected, $errors, $nodeCode);
        $this->assertSame($before, $this->settingsSnapshot());
    }
}
