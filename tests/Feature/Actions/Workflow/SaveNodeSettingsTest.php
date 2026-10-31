<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Authz\Api\CreateSelectionSubjectGroup;
use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\SaveNodeSettings;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowRelativeBase;
use App\Enums\Workflow\WorkflowRelativeScope;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\AuthzSubjectGroupTranslation;
use App\Models\Master\Locale;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteEdge;
use App\Models\Workflow\WorkflowRouteSendBack;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\SavesNodeSettings;
use Tests\Support\TransactionalTestCase;

/**
 * ステップの設定の保存(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 5.1・8 節のステップの設定のテスト)。期待の文言は 6 節の表から書いた。
 * テストのフローの版: 版 1 = 一本道(申請・承認1(u0002)・承認2(u0003)。2025-04-01〜2026-01-01)、版 2 = R1(2026-01-01〜)。
 */
final class SaveNodeSettingsTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use SavesNodeSettings;

    private int $linear;

    private int $branched;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branched = $this->addVersion($this->r1());
        $this->linear = WorkflowFlowVersion::query()->whereRelation('workflowFlow', 'code', self::FLOW_CODE)
            ->where('number', 1)->sole()->id;
    }

    /**
     * 5.1 節の指定の種類の変更(前の種類の列が NULL)。
     */
    public function test_changing_the_target_kind_clears_the_columns_of_the_previous_kind(): void
    {
        $manager = $this->postId('manager');
        $this->saveSettings($this->linear, 'approval2', [
            'targetKind' => WorkflowTargetKind::Relative, 'targets' => null,
            'relativeBase' => WorkflowRelativeBase::Applicant, 'relativeScope' => WorkflowRelativeScope::Parent,
            'companyPostId' => $manager,
        ]);
        $expected = ['relative', null, 'applicant', 'parent', $manager, null, null, null];
        $this->assertSame($expected, $this->targetColumns('approval2'));

        $this->saveSettings($this->linear, 'approval2', [
            'targetKind' => WorkflowTargetKind::Designated,
            'relativeBase' => null, 'relativeScope' => null, 'companyPostId' => null,
            'designatorNodeCode' => 'approval1', 'minAssignees' => 0, 'maxAssignees' => 3,
        ]);
        $designator = $this->routeNode($this->linear, 'approval1')->id;
        $this->assertSame(['designated', null, null, null, null, $designator, 0, 3], $this->targetColumns('approval2'));

        $this->saveSettings($this->linear, 'approval2', [
            'targetKind' => WorkflowTargetKind::Fixed, 'targets' => [$this->userCondition('u0004')],
            'designatorNodeCode' => null, 'minAssignees' => null, 'maxAssignees' => null,
        ]);
        $columns = $this->targetColumns('approval2');
        $this->assertSame(['fixed', null, null, null, null, null, null], [$columns[0], ...array_slice($columns, 2)]);
        $this->assertEquals([$this->userCondition('u0004')], $this->targetsOf((int) $columns[1]));
    }

    /**
     * 対象者の条件が同じなら作らず、違えば作って指し直す。表示名(定義の管理.md の 5.1 節)。
     */
    public function test_fixed_targets_keep_the_same_set_and_repoint_a_different_set(): void
    {
        $before = $this->groupOf('approval1');
        $groups = AuthzSubjectGroup::query()->count();
        $same = [$this->userCondition('u0002'), $this->userCondition('u0002')];
        $this->saveSettings($this->linear, 'approval1', ['targets' => $same]);
        $this->assertSame([$before, $groups], [$this->groupOf('approval1'), AuthzSubjectGroup::query()->count()]);

        $targets = [$this->userCondition('u0004'), $this->userCondition('u0002')];
        $this->saveSettings($this->linear, 'approval1', ['targets' => $targets]);

        $after = $this->groupOf('approval1');
        $this->assertNotSame($before, $after);
        $this->assertTrue(AuthzSubjectGroup::query()->whereKey($before)->exists());
        $this->assertEquals($targets, $this->targetsOf($after));
        $names = AuthzSubjectGroupTranslation::query()->where('authz_subject_group_id', $after)
            ->pluck('display_name')->all();
        $this->assertSame(array_fill(0, Locale::query()->count(), 'プロジェクト計画の承認 / 承認1'), $names);
    }

    /**
     * 対象者の行が無い・相手が存在しない(ほかの欄の誤りと一緒に返る)ときは 422 で何も変わらない(定義の管理.md の 6 節)。
     */
    public function test_missing_targets_are_rejected_and_nothing_changes(): void
    {
        $before = $this->settingsSnapshot();
        $missing = new SelectionCondition(SelectionConditionKind::User, 999_999_999);
        $rows = [$this->userCondition('u0002'), $this->userCondition('u0002'), $missing];

        $this->assertSame(['targets' => '対象者を 1 件以上指定してください'], $this->saveErrors('approval1', ['targets' => []]));
        $this->assertSame(['targets.2' => '対象者を選択してください'], $this->saveErrors('approval1', ['targets' => $rows]));
        $this->assertSame(
            ['decision_rule' => 'このステップには、この設定はできません', 'targets.0' => '対象者を選択してください'],
            $this->saveErrors('approval1', ['targets' => [$missing], 'decisionRule' => WorkflowDecisionRule::All]),
        );
        // 作った対象者が残らないことの確かめ(定義の管理.md の 5.1 節)
        $this->assertSame(
            ['decision_rule' => 'このステップには、この設定はできません'],
            $this->saveErrors('approval1', [
                'targets' => [$this->userCondition('u0003')], 'decisionRule' => WorkflowDecisionRule::All,
            ]),
        );
        $this->assertSame($before, $this->settingsSnapshot());
    }

    /**
     * 申請の「誰が申請できるか」と、申請者本人の承認を防ぐ・差戻しできる先(重複は 1 つ)・合議の人数と可決の条件の保存。
     */
    public function test_saves_the_applicant_targets_the_prevention_the_send_backs_and_the_council(): void
    {
        $this->saveSettings($this->branched, 'apply', ['targets' => [$this->userCondition('u0001')]]);
        $applyGroup = (int) $this->routeNode($this->branched, 'apply')->authz_subject_group_id;
        $this->assertEquals([$this->userCondition('u0001')], $this->targetsOf($applyGroup));

        $this->saveSettings($this->branched, 'council', [
            'preventsApplicantApproval' => null, 'sendBackNodeCodes' => ['section_chief', 'apply', 'section_chief'],
            'minAssignees' => 3, 'maxAssignees' => 4,
            'decisionRule' => WorkflowDecisionRule::Ratio, 'decisionValue' => 50,
        ]);

        $council = $this->routeNode($this->branched, 'council');
        $this->assertSame([false, 3, 4, WorkflowDecisionRule::Ratio, 50], [
            $council->prevents_applicant_approval, $council->min_assignees, $council->max_assignees,
            $council->decision_rule, $council->decision_value,
        ]);
        $targets = WorkflowRouteSendBack::query()->where('workflow_route_node_id', $council->id)->orderBy('id')
            ->pluck('target_workflow_route_node_id')->all();
        $this->assertSame([
            $this->routeNode($this->branched, 'section_chief')->id, $this->routeNode($this->branched, 'apply')->id,
        ], $targets);
    }

    /**
     * 分岐・同期のノードで、どの欄も空なら何も変えない。
     */
    public function test_a_routing_node_without_settings_changes_nothing(): void
    {
        $before = $this->settingsSnapshot();

        $this->saveSettings($this->branched, 'budget');
        $empty = ['sendBackNodeCodes' => [], 'preventsApplicantApproval' => false];
        $this->saveSettings($this->branched, 'budget_end', $empty);

        $this->assertSame($before, $this->settingsSnapshot());
    }

    /**
     * 入力に無い列(期限・確認する人・添付の追加の禁止)は変えない。
     */
    public function test_the_columns_not_in_the_input_are_kept(): void
    {
        $confirmer = app(CreateSelectionSubjectGroup::class)
            ->execute([$this->userCondition('u0010')], ['ja' => '確認する人']);
        $this->routeNode($this->linear, 'approval1')->update([
            'deadline_days' => 5, 'confirm_authz_subject_group_id' => $confirmer, 'prevents_attachment_add' => true,
        ]);

        $this->saveSettings($this->linear, 'approval1', [
            'targetKind' => WorkflowTargetKind::Relative, 'targets' => null,
            'relativeBase' => WorkflowRelativeBase::Applicant, 'relativeScope' => WorkflowRelativeScope::Own,
            'preventsApplicantApproval' => true,
        ]);

        $node = $this->routeNode($this->linear, 'approval1');
        $this->assertSame([5, $confirmer, true], [
            $node->deadline_days, $node->confirm_authz_subject_group_id, $node->prevents_attachment_add,
        ]);
    }

    /**
     * 保存の後の申請だけが新しい設定を写し、進行中の案件の写しは変わらない。
     */
    public function test_only_applications_after_the_save_copy_the_new_settings(): void
    {
        $previousGroup = $this->groupOf('approval1');
        $earlier = $this->apply();

        $this->saveSettings($this->linear, 'approval1', [
            'targets' => [$this->userCondition('u0004')], 'preventsApplicantApproval' => true,
        ]);
        $later = $this->apply();

        $expected = [[$previousGroup, false], [$this->groupOf('approval1'), true]];
        $this->assertSame($expected, [$this->copiedApproval($earlier), $this->copiedApproval($later)]);
    }

    /**
     * 分岐の条件の項目を業務モジュールから消した後(条件が無い項目のコードを指す)も保存は通る。
     */
    public function test_saves_after_the_branch_field_was_removed_from_the_module(): void
    {
        $removed = ['all' => [['field' => 'removed_field', 'op' => 'ge', 'value' => 300]]];
        WorkflowRouteEdge::query()->where('workflow_flow_version_id', $this->branched)->where('sort_key', 1)
            ->update(['condition' => json_encode($removed)]);

        $this->saveSettings($this->branched, 'section_chief', ['preventsApplicantApproval' => false]);

        $this->assertFalse($this->routeNode($this->branched, 'section_chief')->prevents_applicant_approval);
    }

    /**
     * フローが無い・フローに無い版の番号・その版に無いノード(別の版にだけあるコード)は見つからない。
     */
    public function test_a_missing_flow_version_or_node_is_not_found(): void
    {
        $input = $this->settingsOf($this->linear, 'approval1');
        $targets = [
            ['no_such_flow', 1, 'approval1'], [self::FLOW_CODE, 9, 'approval1'], [self::FLOW_CODE, 1, 'section_chief'],
        ];

        foreach ($targets as [$flow, $number, $node]) {
            $save = fn () => app(SaveNodeSettings::class)->execute($flow, $number, $node, $input);
            $this->assertThrows($save, ModelNotFoundException::class);
        }
    }

    /**
     * 版 1 のノードを保存したときの誤り。
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, string>
     */
    private function saveErrors(string $nodeCode, array $overrides): array
    {
        return $this->validationErrors(fn () => $this->saveSettings($this->linear, $nodeCode, $overrides));
    }

    private function groupOf(string $code): int
    {
        return (int) $this->routeNode($this->linear, $code)->authz_subject_group_id;
    }

    /**
     * 版 1 のノードの指定の列: [指定の種類, 対象者, 起点, 組織の範囲, 役職, 指定するノード, 最小, 最大]。
     *
     * @return list<mixed>
     */
    private function targetColumns(string $code): array
    {
        $node = $this->routeNode($this->linear, $code);

        return [
            $node->target_kind?->value, $node->authz_subject_group_id,
            $node->relative_base?->value, $node->relative_scope?->value, $node->company_post_id,
            $node->designator_workflow_route_node_id, $node->min_assignees, $node->max_assignees,
        ];
    }

    /**
     * u0001 が申請基準日 2025-12-01(版 1 の期間)で申請した案件の id。
     */
    private function apply(): int
    {
        $context = $this->contextOf($this->seededUser('u0001'));

        return app(ApplyCase::class)->execute($context, self::FLOW_CODE, $this->projectInput(), '2025-12-01')->caseId;
    }

    /**
     * 案件が写した承認1: [対象者, 申請者本人の承認を防ぐか]。
     *
     * @return array{int, bool}
     */
    private function copiedApproval(int $caseId): array
    {
        $node = WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->where('code', 'approval1')->sole();

        return [(int) $node->authz_subject_group_id, (bool) $node->prevents_applicant_approval];
    }
}
