<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNoticeType;
use App\Enums\Workflow\WorkflowRelativeBase;
use App\Enums\Workflow\WorkflowRelativeScope;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use Tests\Support\OperatesAsCaseManager;
use Tests\Support\ReadsCaseNotices;
use Tests\Support\TransactionalTestCase;

/**
 * ステップの移動の進む(docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.5 節・15.1 節の 1〜3、同時実行と検査.md の 14.2 節・14.3 節の
 * (1)): 承認のノードは承認と同じ遷移(合議の票・横配置の次の子)、申請のノードは再申請と同じ遷移(レコードを変えず今のレコードで分岐を評価し直す)。
 * 指定するノードは 422 case。進めたノードの処理対象者が 1 人ならその人、ほかは申請者を前処理者にし(管理者にしない)、前処理者の列を書く。進むで処理済みに
 * したノード・子へ戻すと、以前に処理していないのと同じに展開し直す。管理者 = u0004。
 * 前処理者を起点にする rel(上位組織の全員): 開発部の上位の開発本部 = u0008、東京営業課の上位の営業部 = u0003・u0013、頂点(デリバー商事)の上位は無い。
 */
final class MoveCaseNodeForwardTest extends TransactionalTestCase
{
    use OperatesAsCaseManager;
    use ReadsCaseNotices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
    }

    /**
     * 申請 → a1(u0002。確認する人 u0010)→ a2(u0003): a1 を進めると処理済み(進む)で a2 が u0003 の承認待ち。a2 の前処理者 = a1 のただ 1 人の
     * 処理対象者 u0002(起点の組織 = 主所属の東京営業課)。u0003 に処理依頼、u0010 に確認依頼が届く(管理者には届かない)。u0002 は未処理から消え、
     * 管理者の処理済に出る。
     */
    public function test_moving_an_approval_forward_is_the_same_as_approving_it(): void
    {
        $case = $this->confirmedLineCase();
        $lastId = $this->lastNoticeId();

        $this->moveOn($case, 'a1', 'forward');

        $this->assertSame(['a1' => 'done', 'a2' => 'waiting[u0003]'], $this->pick($case, 'a1', 'a2'));
        $this->assertSame(['move_forward', self::MANAGER, null], array_slice($this->latestRow($case), 0, 3));
        $this->assertSame(['u0002', $this->departmentIdOf('tokyo-sales')], $this->previousOf($case, 'a2'));
        $request = ['step_name' => 'a2', 'step_kind' => 'approve'];
        $this->assertSame([
            $this->caseNotice(WorkflowNoticeType::ConfirmRequest, 'u0010', self::MANAGER, $case, ['step_name' => 'a1']),
            $this->caseNotice(WorkflowNoticeType::ProcessRequest, 'u0003', self::MANAGER, $case, $request),
        ], $this->noticesSince($lastId));
        $this->assertSame(
            ['u0002' => [], 'u0003' => ['pending'], self::MANAGER => ['processed']],
            $this->appearances($case, 'u0002', 'u0003', self::MANAGER),
        );
    }

    /**
     * 最後のノードを進めると承認で完了し、申請者に処理結果(承認)が届く。管理者の処理済(完了)に出る。保留中のノード(a1 を u0002 が保留)も進められ、
     * 保留は消える。
     */
    public function test_moving_the_last_or_a_held_node_forward(): void
    {
        $case = $this->confirmedLineCase();
        $this->holdOn($case, 'a1', 'u0002');
        $this->moveOn($case, 'a1', 'forward');
        $held = $this->pick($case, 'a1', 'a2');
        $lastId = $this->lastNoticeId();

        $this->moveOn($case, 'a2', 'forward');

        $this->assertSame(['a1' => 'done', 'a2' => 'waiting[u0003]'], $held);
        $case->refresh();
        $this->assertSame(['completed', 'approved'], [$case->state->value, $case->completion_reason?->value]);
        $params = ['completion_reason' => 'approved'];
        $result = $this->caseNotice(WorkflowNoticeType::Result, 'u0001', self::MANAGER, $case, $params);
        $this->assertSame([$result], $this->noticesSince($lastId));
        $this->assertSame([self::MANAGER => ['processed']], $this->appearances($case, self::MANAGER));
    }

    /**
     * 進めて到達したノードの処理対象者に管理者本人(u0004)がいても、管理者には処理依頼が届かない(宛先から処理者を除く = 通知.md の 3 節)。
     */
    public function test_the_manager_gets_no_request_for_a_node_he_reached(): void
    {
        $nodes = [
            $this->applyNode(),
            $this->approveNode('a1', 'u0002', ['apply']),
            $this->approveNode('a2', self::MANAGER, ['apply']),
        ];
        $this->addVersion([$nodes, $this->chain(['apply', 'a1'], ['a1', 'a2'])]);
        [$case] = $this->applyOnRoute([]);
        $lastId = $this->lastNoticeId();

        $this->moveOn($case, 'a1', 'forward');

        $this->assertSame([[], 'waiting[u0004]'], [$this->noticesSince($lastId), $this->shape($case)['a2']]);
    }

    /**
     * 合議(縦配置。人数 2)の子を進めると票になる: 2 人とも進めて可決し、次の final が到達する(全員の可決も進むの票で決まる)。
     */
    public function test_moving_council_children_forward_votes(): void
    {
        $this->addVersion($this->placementRoute(WorkflowNodeKind::Parallel));
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', 'u0002', ['family' => ['u0005', 'u0009']]);
        $this->moveOn($case, 'family.1', 'forward');
        $pending = $this->familyShape($case, 'family');

        $this->moveOn($case, 'family.2', 'forward');

        $this->assertSame(['running', 'done', 'waiting[u0009]'], $pending);
        $shape = [...$this->familyShape($case, 'family'), $this->shape($case)['final']];
        $this->assertSame(['passed', 'done', 'done', 'waiting[u0010]'], $shape);
    }

    /**
     * 横配置の子を進めると、次の子が今の指定の人で到達する。
     */
    public function test_moving_a_serial_child_forward_reaches_the_next_child(): void
    {
        $this->addVersion($this->placementRoute(WorkflowNodeKind::Serial));
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', 'u0002', ['family' => ['u0005', 'u0009']]);

        $this->moveOn($case, 'family.1', 'forward');

        $this->assertSame(['running', 'done', 'waiting[u0009]'], $this->familyShape($case, 'family'));
    }

    /**
     * 申請へ差戻した再申請待ちの申請のノードを進めると、再申請と同じ遷移で課長が到達する(レコードの値・版は変わらず、行は版を指さない)。今のレコードの値で
     * 分岐を評価し直すので、予算を空にしたレコードでは 422 case(再申請と同じ文言)で何も変わらない(14.3 節の (1))。
     */
    public function test_moving_the_application_forward_reevaluates_the_current_record(): void
    {
        $this->addVersion($this->r1());
        [$reapplied] = $this->applyOnRoute(['budget' => 500]);
        [$emptied] = $this->applyOnRoute(['budget' => 500]);

        foreach ([$reapplied, $emptied] as $case) {
            $this->approve($case, 'section_chief', 'u0002', ['council' => ['u0005', 'u0009'], 'owner' => ['u0010']]);
            $this->sendBackOn($case, 'manager', 'u0003', 'apply');
        }

        $record = Record::query()->findOrFail($reapplied->record_id);
        $before = [$record->field_values, RecordRevision::query()->where('record_id', $record->id)->count()];
        $this->moveOn($reapplied, 'apply', 'forward');
        $this->clearBudgetOf($emptied);
        $unchanged = [$this->shape($emptied), $this->workflowCounts(), $this->revisionOf($emptied)];

        $error = $this->attemptOf(fn () => $this->moveOn($emptied, 'apply', 'forward'));

        $reached = $this->pick($reapplied, 'apply', 'section_chief');
        $this->assertSame(['apply' => 'done', 'section_chief' => 'waiting[u0002]'], $reached);
        $after = [$record->refresh()->field_values, RecordRevision::query()->where('record_id', $record->id)->count()];
        $row = WorkflowCaseHistory::query()->where('workflow_case_id', $reapplied->id)->latest('id')->firstOrFail();
        $this->assertSame([$before, 'move_forward', null], [$after, $row->action->value, $row->record_revision_id]);
        $this->assertSame(['case' => '分岐「予算」の条件に該当するルートがないため、申請できません'], $error);
        $this->assertSame($unchanged, [$this->shape($emptied), $this->workflowCounts(), $this->revisionOf($emptied)]);
    }

    /**
     * 指定するノード(R1 の課長)は進められない → 422 case(13 節の文言)で何も変わらない。
     */
    public function test_a_designating_node_cannot_be_moved_forward(): void
    {
        $this->addVersion($this->r1());
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $before = [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)];

        $error = $this->attemptOf(fn () => $this->moveOn($case, 'section_chief', 'forward'));

        $message = '次のステップの指定が必要なため、このステップは進められません。承認の画面から処理してください。';
        $after = [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)];
        $this->assertSame([['case' => $message], $before], [$error, $after]);
    }

    /**
     * 進めた a1 の処理対象者がちょうど 1 人(u0011)なら、rel はその人の主所属(開発部)を起点に展開し、2 人(u0011・u0010)なら申請者と担当組織(東京営業課)を
     * 起点に展開する(管理者 u0004 の経営企画室からは展開しない)。どちらも rel に前処理者と起点の組織を書く。進めた人が頂点の組織の u0005 だけなら、
     * 展開が 0 人で 422 case(前のノードからの到達と同じ文言)。
     */
    public function test_moving_forward_expands_from_the_single_target_or_the_applicant(): void
    {
        $this->addPreviousRoute();
        [$single] = $this->applyOnRoute([]);
        [$several] = $this->applyOnRoute([]);
        [$top] = $this->applyOnRoute([]);
        $this->retargetOn($several, 'a1', ['u0011', 'u0010']);
        $this->retargetOn($top, 'a1', ['u0005']);

        $this->moveOn($single, 'a1', 'forward');
        $this->moveOn($several, 'a1', 'forward');
        $error = $this->attemptOf(fn () => $this->moveOn($top, 'a1', 'forward'));

        $rels = [$this->shape($single)['rel'], $this->shape($several)['rel']];
        $this->assertSame(['waiting[u0008]', 'waiting[u0003,u0013]'], $rels);
        $this->assertSame(
            [['u0011', $this->departmentIdOf('development')], ['u0001', $this->departmentIdOf('tokyo-sales')]],
            [$this->previousOf($single, 'rel'), $this->previousOf($several, 'rel')],
        );
        $this->assertSame(['case' => 'relの処理対象者がいないため、この操作はできません'], $error);
    }

    /**
     * 進むで処理済みにしたノードへ差戻すと、管理者に戻らず写した指定から展開し直す: 対象者で指定の a1 は u0011、前処理者を起点にする rel は記録した前処理者
     * u0011 と開発部から u0008(処理対象者の変更で入れた u0006 にも戻らない)。
     */
    public function test_sending_back_to_a_node_moved_forward_expands_it_again(): void
    {
        $this->addPreviousRoute();
        [$toFixed] = $this->applyOnRoute([]);
        [$toPrevious] = $this->applyOnRoute([]);
        $this->moveOn($toFixed, 'a1', 'forward');
        $this->approve($toFixed, 'rel', 'u0008');
        $this->approve($toPrevious, 'a1', 'u0011');
        $this->retargetOn($toPrevious, 'rel', ['u0006']);
        $this->moveOn($toPrevious, 'rel', 'forward');

        $this->sendBackOn($toFixed, 'fin', 'u0010', 'a1');
        $this->sendBackOn($toPrevious, 'fin', 'u0010', 'rel');

        $returned = [$this->shape($toFixed)['a1'], $this->shape($toPrevious)['rel']];
        $this->assertSame(['waiting[u0011]', 'waiting[u0008]'], $returned);
    }

    /**
     * 配置の親へ差戻したとき、進むで処理済みにした子は今の指定の人(u0005。処理対象者の変更で入れた u0006 にも管理者にも戻らない)、承認した子は承認した人、
     * 省いた子は今の指定の人で到達し直す。
     */
    public function test_sending_back_to_a_parent_returns_a_child_moved_forward_to_its_designated_person(): void
    {
        $this->addVersion($this->placementRoute(WorkflowNodeKind::Parallel));
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', 'u0002', ['family' => ['u0005', 'u0009', 'u0011']]);
        $this->retargetOn($case, 'family.1', ['u0006']);
        $this->moveOn($case, 'family.1', 'forward');
        $this->approve($case, 'family.2', 'u0009');

        $this->sendBackOn($case, 'final', 'u0010', 'family');

        $children = ['running', 'waiting[u0005]', 'waiting[u0009]', 'waiting[u0011]'];
        $this->assertSame($children, $this->familyShape($case, 'family'));
    }

    /**
     * 申請 → a1(u0002。確認する人 u0010)→ a2(u0003)の案件。
     */
    private function confirmedLineCase(): WorkflowCase
    {
        $a1 = new RouteNodeInput(
            'a1',
            'a1',
            WorkflowNodeKind::Approve,
            WorkflowTargetKind::Fixed,
            [$this->userCondition('u0002')],
            ['ja' => 'a1'],
            sendBackCodes: ['apply'],
            confirmSubjectGroupId: $this->confirmGroup('u0010'),
        );
        $nodes = [$this->applyNode(), $a1, $this->approveNode('a2', 'u0003', ['apply', 'a1'])];
        $this->addVersion([$nodes, $this->chain(['apply', 'a1'], ['a1', 'a2'])]);

        return $this->applyOnRoute([])[0];
    }

    /**
     * 申請 → a1(u0011)→ rel(前処理者を起点に上位組織の全員)→ fin(u0010。a1・rel へ差戻せる)の版。
     */
    private function addPreviousRoute(): void
    {
        $rel = new RouteNodeInput(
            'rel',
            'rel',
            WorkflowNodeKind::Approve,
            WorkflowTargetKind::Relative,
            relativeBase: WorkflowRelativeBase::Previous,
            relativeScope: WorkflowRelativeScope::Parent,
            sendBackCodes: ['apply'],
        );
        $nodes = [
            $this->applyNode(),
            $this->approveNode('a1', 'u0011', ['apply']),
            $rel,
            $this->approveNode('fin', 'u0010', ['apply', 'a1', 'rel']),
        ];
        $this->addVersion([$nodes, $this->chain(['apply', 'a1'], ['a1', 'rel'], ['rel', 'fin'])]);
    }

    /**
     * ノードに記録した [前処理者のコード, 起点の組織の id]。
     *
     * @return array{string|null, int|null}
     */
    private function previousOf(WorkflowCase $case, string $label): array
    {
        $node = WorkflowCaseNode::query()->findOrFail($this->labelIds($case)[$label]);
        $user = $node->previous_user_id === null ? null : (string) $node->previousUser?->code;

        return [$user, $node->previous_department_id];
    }

    /**
     * 案件のレコードの予算を空にする(業務処理を通さずに、分岐のどの条件も偽になる今のレコードを作る)。
     */
    private function clearBudgetOf(WorkflowCase $case): void
    {
        $module = RecordModule::query()->with('recordFields')->where('code', 'project')->sole();
        $budgetId = $module->recordFields->firstOrFail('code', 'budget')->id;
        $record = Record::query()->findOrFail($case->record_id);
        $values = $record->field_values;
        unset($values[$budgetId]);
        $record->update(['field_values' => $values]);
    }
}
