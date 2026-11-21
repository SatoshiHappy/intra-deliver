<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseConfirmTarget;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordHoldLink;
use App\Queries\Records\Api\RecordHoldNotices;
use App\Queries\Records\Api\RecordHoldState;
use App\Queries\Workflow\CaseDetailQuery;
use App\Queries\Workflow\CaseNumbers;
use App\Queries\Workflow\WorkflowRecordHoldNotices;
use App\Queries\Workflow\WorkflowRecordHolds;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Http\Controllers\Web\Workflow\UsesSeededCases;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\TransactionalTestCase;

/**
 * 持ち主の案内のワークフローの実装(docs の 設計/ワークフロー/業務データとの接続/申請と書き込み.md の 4 節の「持ち主の案内の実装」、
 * 検査 = 設計/ワークフロー/申請の周辺/申請の内容の閲覧.md の 3 節の「持ち主の案内の検査」)。
 * stateOf は述語ごとに申請の詳細の入口({@see CaseDetailQuery::visibleCase()})と同じ人にだけ返り、札の語と色は申請の詳細の見出しと同じ規則、
 * suffix は現在のステップの文字。判定しない 2 つ(revisionLinks・attachmentDisableNotice)は {@see WorkflowRecordHoldNoticeTextsTest}。
 * A = u0001(申請者)、B = u0002(処理済)、C = u0003(未処理)、F = u0006(確認する人)、R = u0005(参照者)、
 * M = u0004(システム管理者 = 管理。見本の WF-000005(未完了)と WF-000001(完了)で確かめる)。
 */
final class WorkflowRecordHoldNoticesTest extends TransactionalTestCase
{
    use BuildsWorkflowCases;
    use UsesSeededCases;

    private const DATE = '2026-10-13';

    /**
     * 申請の状態(null = 未完了、ほか = 完了の理由)。
     *
     * @return array<string, array{WorkflowCompletionReason|null}>
     */
    public static function states(): array
    {
        return [
            '未完了' => [null],
            '完了(承認)' => [WorkflowCompletionReason::Approved],
            '完了(承認終了)' => [WorkflowCompletionReason::ApprovedEarly],
            '完了(否認)' => [WorkflowCompletionReason::Rejected],
            '完了(取止め)' => [WorkflowCompletionReason::Withdrawn],
        ];
    }

    /**
     * @return array<string, array{WorkflowCompletionReason}>
     */
    public static function completedReasons(): array
    {
        return array_filter(self::states(), fn (array $state) => $state[0] !== null);
    }

    public function test_the_implementation_is_registered_with_the_tag(): void
    {
        $tagged = [...app()->tagged(RecordHoldNotices::TAG)];
        $registered = array_map(fn (RecordHoldNotices $notices) => $notices::class, $tagged);

        $this->assertContains(WorkflowRecordHoldNotices::class, $registered);
    }

    /**
     * 同値の検査(申請の内容の閲覧.md の 3 節の「持ち主の案内の検査」): 述語ごとに、一時保存でない申請で、案内が返る ⇔ 申請の詳細の入口が申請を見せる
     * (入口の判定と比べる。CaseVisibility とだけ比べると、入口に足された条件のずれを拾わない)。
     */
    #[DataProvider('states')]
    public function test_the_state_is_returned_exactly_when_the_entry_of_the_case_detail_shows_the_case(
        ?WorkflowCompletionReason $reason,
    ): void {
        [$case, $users] = $this->participantsCase($reason);
        $seeded = $this->seededCase($reason);

        foreach ($users as $predicate => $user) {
            $target = $predicate === '管理' ? $seeded : $case;
            $returned = $this->stateOf($user, WorkflowRecordHolds::holderOf($target->id)) !== null;

            $this->assertSame($this->shownByEntry($user, $target), $returned, $predicate);
        }

        $guestReturned = $this->stateOf(null, WorkflowRecordHolds::holderOf($case->id)) !== null;
        $this->assertSame($this->shownByEntry(null, $case), $guestReturned, 'ゲスト');
    }

    /**
     * 述語ごとの結果(認可と初期データの 1.2 の表と同じ形): 未処理の述語は未完了の間だけ、ほかの 4 つは完了の後も返る。関わらない人・ゲストには返らない。
     */
    #[DataProvider('states')]
    public function test_each_predicate_sees_the_state_while_it_can_view_the_case(
        ?WorkflowCompletionReason $reason,
    ): void {
        [$case, $users] = $this->participantsCase($reason);
        $holder = WorkflowRecordHolds::holderOf($case->id);

        $this->assertSame($reason === null, $this->stateOf($users['未処理'], $holder) !== null, '未処理');

        foreach (['処理済', '参照', '確認'] as $predicate) {
            $this->assertNotNull($this->stateOf($users[$predicate], $holder), $predicate);
        }

        $seeded = WorkflowRecordHolds::holderOf($this->seededCase($reason)->id);
        $this->assertNotNull($this->stateOf($users['管理'], $seeded), '管理');
        $this->assertNull($this->stateOf($users['関わらない人'], $holder), '関わらない人');
        $this->assertNull($this->stateOf(null, $holder), 'ゲスト');
    }

    /**
     * 未完了: リンク = 申請番号と申請の詳細の URL、札 = 承認待ち、suffix = 現在のステップ。
     */
    public function test_an_active_case_shows_its_number_the_waiting_badge_and_the_current_nodes(): void
    {
        [$case, $users] = $this->participantsCase(null);

        $this->assertEquals(
            new RecordHoldState($this->linkOf($case), '承認待ち', 'pending', '部長'),
            $this->stateOf($users['処理済'], WorkflowRecordHolds::holderOf($case->id)),
        );
    }

    /**
     * 札の状態の規則(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 2.4 節): 保留中のノードだけなら保留中(suffix の名前の後に「(保留中)」)、並列の片方だけが保留中なら承認待ちで、
     * 保留中のノードの名前にだけ添える。再申請待ちがあれば再申請待ち。
     */
    public function test_the_badge_and_the_current_nodes_of_held_and_returned_cases(): void
    {
        [$case, $users] = $this->participantsCase(null);
        $holder = WorkflowRecordHolds::holderOf($case->id);
        [$apply, , $approve2] = $this->nodesOf($case);
        $approve2->update(['state' => WorkflowNodeState::Hold]);

        $held = $this->stateOf($users['処理済'], $holder);
        $this->assertSame(['保留中', 'returned', '部長(保留中)'], [$held?->status, $held?->tone, $held?->suffix]);

        $parallel = WorkflowCaseNode::factory()
            ->create(['workflow_case_id' => $case->id, 'position' => 99, 'name' => '課長']);
        $this->reach($parallel, $users['未処理']);
        $partly = $this->stateOf($users['処理済'], $holder);
        $this->assertSame(['承認待ち', 'pending', '部長(保留中)、課長'], [$partly?->status, $partly?->tone, $partly?->suffix]);

        $approve2->update(['state' => WorkflowNodeState::Unreached]);
        $this->unreach($parallel);
        $apply->update(['name' => '申請']);
        $this->reach($apply);
        $returned = $this->stateOf($users['処理済'], $holder);
        $this->assertSame(['再申請待ち', 'returned', '申請'], [$returned?->status, $returned?->tone, $returned?->suffix]);
    }

    /**
     * 完了: 札 = 完了(<完了の理由>)(申請の詳細の見出しの札と同じ語と色。印は付けない)、suffix = null。
     */
    #[DataProvider('completedReasons')]
    public function test_a_completed_case_shows_the_completed_badge_without_current_nodes(
        WorkflowCompletionReason $reason,
    ): void {
        [$case, $users] = $this->participantsCase($reason);
        $badges = [
            WorkflowCompletionReason::Approved->value => ['完了(承認)', 'settled'],
            WorkflowCompletionReason::ApprovedEarly->value => ['完了(承認終了)', 'settled'],
            WorkflowCompletionReason::Rejected->value => ['完了(否認)', 'rejected'],
            WorkflowCompletionReason::Withdrawn->value => ['完了(取止め)', 'settled'],
        ];

        $this->assertEquals(
            new RecordHoldState($this->linkOf($case), ...[...$badges[$reason->value], null]),
            $this->stateOf($users['処理済'], WorkflowRecordHolds::holderOf($case->id)),
        );
    }

    /**
     * ほかの形の鍵・見つからない案件・一時保存の案件は null。
     */
    public function test_other_keys_missing_cases_and_drafts_return_null(): void
    {
        [$a] = $this->seededUsers('u0001');
        $draft = WorkflowCase::factory()->draft()
            ->create(['workflow_flow_id' => $this->flow()->id, 'user_id' => $a->id]);
        $keys = [
            'other:1', 'workflow-case:', 'workflow-case:abc', 'workflow-case:0', 'workflow-case:01',
            'workflow-case:1 ', ' workflow-case:1', 'workflow-case:999999999',
            WorkflowRecordHolds::holderOf($draft->id),
        ];

        foreach ($keys as $key) {
            $this->assertNull($this->stateOf($a, $key), $key);
        }

        $this->assertSame(12, WorkflowRecordHolds::caseIdOf(WorkflowRecordHolds::holderOf(12)));
    }

    private function notices(): WorkflowRecordHoldNotices
    {
        return app(WorkflowRecordHoldNotices::class);
    }

    private function stateOf(?User $user, string $holder): ?RecordHoldState
    {
        return $this->notices()->stateOf(new Requester($user?->id, null), $holder, CarbonImmutable::parse(self::DATE));
    }

    private function linkOf(WorkflowCase $case): RecordHoldLink
    {
        return new RecordHoldLink(
            CaseNumbers::format((int) $case->case_number),
            route('workflow.cases.show', ['case' => $case->id]),
        );
    }

    /**
     * 申請の詳細の入口(workflow.cases.show の入口の判定 = {@see CaseDetailQuery::visibleCase()})がその申請を見せるか。
     */
    private function shownByEntry(?User $user, WorkflowCase $case): bool
    {
        $context = new AccessContext($user?->id, null, null, CarbonImmutable::parse(self::DATE), 'ja');

        try {
            app(CaseDetailQuery::class)->visibleCase(Requester::fromAccessContext($context), $context, $case->id);

            return true;
        } catch (ModelNotFoundException) {
            return false;
        }
    }

    /**
     * 述語ごとの人が関わる申請(reason が null なら未完了、ほかはその理由で完了): 申請者 A が申請し、B が承認 1 を承認し、承認 2(部長)が C の承認待ちのまま
     * (完了でも処理対象者の行は残る)。R はフローの参照者、F は確認する人。管理の述語の人 M(見本の申請で確かめる)と関わらない人も返す。
     *
     * @return array{WorkflowCase, array<string, User>}
     */
    private function participantsCase(?WorkflowCompletionReason $reason): array
    {
        $codes = ['u0001', 'u0002', 'u0003', 'u0004', 'u0005', 'u0006', 'u0007'];
        [$a, $b, $c, $m, $r, $f, $d] = $this->seededUsers(...$codes);
        $flow = $this->flow($this->selection(SelectionConditionKind::User, $r->id));
        $factory = $reason === null
            ? WorkflowCase::factory()->active()
            : WorkflowCase::factory()->completed()->state(['completion_reason' => $reason]);
        $case = $factory->createOne(['workflow_flow_id' => $flow->id, 'user_id' => $a->id]);
        [$apply, $approve1, $approve2] = $this->caseNodes($case, 3);
        $approve2->update(['name' => '部長']);
        $this->process($apply, $a, WorkflowAction::Apply);
        $this->process($approve1, $b, WorkflowAction::Approve);

        $this->reach($approve2, $c);

        WorkflowCaseConfirmTarget::factory()->create(['workflow_case_id' => $case->id, 'user_id' => $f->id]);
        $users = ['未処理' => $c, '処理済' => $b, '参照' => $r, '確認' => $f, '管理' => $m, '関わらない人' => $d];

        return [$case, $users];
    }

    /**
     * 管理の述語を確かめる見本の申請(フローの workflow-flow の read。テストで作ったフローにはリソースが無い): 未完了は WF-000005、完了は
     * WF-000001 の完了の理由を渡した理由に替える。
     */
    private function seededCase(?WorkflowCompletionReason $reason): WorkflowCase
    {
        if ($reason === null) {
            return WorkflowCase::query()->findOrFail($this->caseNumbered(5));
        }

        $case = WorkflowCase::query()->findOrFail($this->caseNumbered(1));
        $this->assertSame(WorkflowCaseState::Completed, $case->state);
        $case->update(['completion_reason' => $reason]);

        return $case;
    }

    /**
     * フロー(参照者の指定 = 渡した対象者。null なら参照者なし)。
     */
    private function flow(?int $referrerSubjectGroupId = null): WorkflowFlow
    {
        return WorkflowFlow::factory()->create(['authz_subject_group_id' => $referrerSubjectGroupId]);
    }
}
