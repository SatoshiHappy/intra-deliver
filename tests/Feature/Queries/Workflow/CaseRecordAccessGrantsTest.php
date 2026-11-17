<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseConfirmTarget;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseDetailQuery;
use App\Queries\Workflow\CaseRecordAccessGrants;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Http\Controllers\Web\Workflow\UsesSeededCases;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\TransactionalTestCase;

/**
 * 申請の経路のレコードの閲覧(docs の 設計/業務データの入れ物/レコードの共通機能/認可と初期データ.md の 1.2 節の表の各行。検査 =
 * 設計/ワークフロー/申請の周辺/申請の内容の閲覧.md の 3 節の 1 つ目)。述語ごとに申請の詳細の入口({@see CaseDetailQuery::visibleCase()})と
 * 同じ結果になることを比べる。表の「起きない」行を止める制約のテストは tests/Feature/Models/Workflow/WorkflowCaseTest。
 * A = u0001(申請者)、B = u0002(処理済)、C = u0003(未処理)、F = u0006(確認する人)、R = u0005(参照者)、
 * M = u0004(システム管理者 = 管理。見本の WF-000005(未完了)と WF-000001(完了)で確かめる)。
 */
final class CaseRecordAccessGrantsTest extends TransactionalTestCase
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

    #[DataProvider('states')]
    public function test_each_predicate_grants_the_url_of_the_case_while_it_can_view_the_case(
        ?WorkflowCompletionReason $reason,
    ): void {
        [$case, $users] = $this->participantsCase($reason);
        $recordId = (int) $case->record_id;
        $url = [$recordId => route('workflow.cases.show', ['case' => $case->id])];
        $active = $reason === null;

        $this->assertSame($active ? $url : [], $this->granted($users['未処理'], [$recordId]), '未処理');

        foreach (['処理済', '参照', '確認'] as $predicate) {
            $this->assertSame($url, $this->granted($users[$predicate], [$recordId]), $predicate);
        }

        $seeded = $this->seededCase($reason);
        $this->assertSame(
            [(int) $seeded->record_id => route('workflow.cases.show', ['case' => $seeded->id])],
            $this->granted($users['管理'], [(int) $seeded->record_id]),
            '管理',
        );
        $this->assertSame([], $this->granted($users['関わらない人'], [$recordId]), '関わらない人');
        $this->assertSame([], $this->granted(null, [$recordId]), 'ゲスト');
    }

    /**
     * 同値の検査(申請の内容の閲覧.md の 3 節の 1 つ目): 述語ごとに、一時保存でない申請で、URL が返る ⇔ 申請の詳細の入口が申請を見せる
     * (入口の判定と比べる。CaseVisibility とだけ比べると、入口に足された条件のずれを拾わない)。
     */
    #[DataProvider('states')]
    public function test_the_url_is_granted_exactly_when_the_entry_of_the_case_detail_shows_the_case(
        ?WorkflowCompletionReason $reason,
    ): void {
        [$case, $users] = $this->participantsCase($reason);
        $seeded = $this->seededCase($reason);

        foreach ($users as $predicate => $user) {
            $target = $predicate === '管理' ? $seeded : $case;
            $granted = $this->granted($user, [(int) $target->record_id]) !== [];

            $this->assertSame($this->shownByEntry($user, $target), $granted, $predicate);
        }

        $guestGranted = $this->granted(null, [(int) $case->record_id]) !== [];
        $this->assertSame($this->shownByEntry(null, $case), $guestGranted, 'ゲスト');
    }

    /**
     * 申請の無いレコード(業務レコード・申請していない申請書)には返らない。一時保存はレコードを持たないので、申請者の一時保存は経路にならない。
     */
    public function test_a_record_without_a_case_and_a_draft_grant_nothing(): void
    {
        [$a] = $this->seededUsers('u0001');
        $draft = WorkflowCase::factory()->draft()
            ->create(['workflow_flow_id' => $this->flow()->id, 'user_id' => $a->id]);
        $record = Record::factory()->create();

        $this->assertNull($draft->record_id);
        $this->assertSame([], $this->granted($a, [$record->id]));
    }

    public function test_mixed_ids_return_only_the_records_of_the_visible_cases_by_one_set_of_queries(): void
    {
        [$a, $b, $d] = $this->seededUsers('u0001', 'u0002', 'u0007');
        $active = $this->processedCase(WorkflowCase::factory()->active(), $a, $b);
        $completed = $this->processedCase(WorkflowCase::factory()->completed(), $a, $b);
        $others = $this->processedCase(WorkflowCase::factory()->active(), $d, $d);
        $ids = [(int) $active->record_id, (int) $completed->record_id, (int) $others->record_id, 999_999_999];

        $this->granted($b, $ids);
        $one = $this->countQueries(fn () => $this->granted($b, [(int) $active->record_id]));
        $four = $this->countQueries(fn () => $this->granted($b, $ids));

        $visible = [(int) $active->record_id, (int) $completed->record_id];
        $this->assertSame($visible, array_keys($this->granted($b, $ids)));
        $this->assertSame($one, $four);
        $this->assertSame(0, $this->countQueries(fn () => $this->granted($b, [])));
    }

    /**
     * @param  list<int>  $recordIds
     * @return array<int, string>
     */
    private function granted(?User $user, array $recordIds): array
    {
        $requester = new Requester($user?->id, null);

        $date = CarbonImmutable::parse(self::DATE);

        return app(CaseRecordAccessGrants::class)->grantedViews($requester, $recordIds, $date);
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

    private function countQueries(callable $action): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $action();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * 述語ごとの人が関わる申請(reason が null なら未完了、ほかはその理由で完了): 申請者 A が申請し、B が承認 1 を承認し、承認 2 が C の承認待ちのまま
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
     * 申請者が申請し、処理者が承認した申請(処理者は処理済の述語で見られる)。
     *
     * @param  Factory<WorkflowCase>  $factory
     */
    private function processedCase(Factory $factory, User $applicant, User $processor): WorkflowCase
    {
        $case = $factory->createOne(['workflow_flow_id' => $this->flow()->id, 'user_id' => $applicant->id]);
        [$apply, $approve] = $this->caseNodes($case, 2);
        $this->process($apply, $applicant, WorkflowAction::Apply);
        $this->process($approve, $processor, WorkflowAction::Approve);

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
