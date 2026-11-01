<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseConfirmTarget;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseRecordAccessGrants;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Http\Controllers\Web\Workflow\UsesSeededCases;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\TransactionalTestCase;

/**
 * 申請の経路のレコードの閲覧(docs の 設計/ワークフロー/申請の周辺/申請の内容の閲覧.md の 1 節、検査 = 3 節の 1 つ目): 未完了の申請の 5 つの述語
 * (未処理・処理済・参照・確認・管理)のそれぞれで申請の詳細の URL が返る。一時保存はレコードを持たず対象にならない。完了・取止めの申請は返らない。
 * ほかの申請のレコード・無い id を混ぜても見られるものだけが返る。集合を 1 回で判定する(問い合わせの数がレコードの数で増えない)。
 * A = u0001(申請者)、B = u0002(処理済)、C = u0003(未処理)、F = u0006(確認する人)、R = u0005(参照者)、
 * M = u0004(システム管理者 = 管理。見本の WF-000005 で確かめる)。
 */
final class CaseRecordAccessGrantsTest extends TransactionalTestCase
{
    use BuildsWorkflowCases;
    use UsesSeededCases;

    private const DATE = '2026-10-13';

    public function test_each_of_the_five_predicates_of_an_active_case_grants_the_url_of_the_case(): void
    {
        [$a, $b, $c, $r, $m, $f] = $this->seededUsers('u0001', 'u0002', 'u0003', 'u0005', 'u0004', 'u0006');
        $case = $this->activeCase($this->flow($this->selection(SelectionConditionKind::User, $r->id)), $a);
        [$apply, $approve1, $approve2] = $this->caseNodes($case, 3);
        $this->process($apply, $a, WorkflowAction::Apply);
        $this->process($approve1, $b, WorkflowAction::Approve);
        $this->reach($approve2, $c);
        WorkflowCaseConfirmTarget::factory()->create([
            'workflow_case_id' => $case->id, 'user_id' => $f->id,
        ]);
        $expected = [(int) $case->record_id => route('workflow.cases.show', ['case' => $case->id])];

        foreach (['未処理' => $c, '処理済' => $b, '参照' => $r, '確認' => $f] as $predicate => $user) {
            $this->assertSame($expected, $this->granted($user, [(int) $case->record_id]), $predicate);
        }

        // 管理の述語はフローの workflow-flow の read(テストで作ったフローにはリソースが無いので、見本のフローの未完了の申請で確かめる)
        $seeded = WorkflowCase::query()->findOrFail($this->caseNumbered(5));
        $this->assertSame(
            [(int) $seeded->record_id => route('workflow.cases.show', ['case' => $seeded->id])],
            $this->granted($m, [(int) $seeded->record_id]),
            '管理',
        );

        $this->assertSame([], $this->granted($this->seededUsers('u0007')[0], [(int) $case->record_id]), '関わらない人');
        $this->assertSame([], $this->granted(null, [(int) $case->record_id]), 'ゲスト');
    }

    /**
     * 完了(承認)・取止めの申請は、関係者にも返らない。一時保存はレコードを持たないので、申請者の一時保存は経路にならない。
     */
    public function test_a_completed_a_withdrawn_and_a_draft_case_grant_nothing(): void
    {
        [$a, $b] = $this->seededUsers('u0001', 'u0002');
        $completed = $this->processedCase(WorkflowCase::factory()->completed(), $a, $b);
        $withdrawn = $this->processedCase(
            WorkflowCase::factory()->completed()->state(['completion_reason' => WorkflowCompletionReason::Withdrawn]),
            $a,
            $b,
        );
        $draft = WorkflowCase::factory()->draft()
            ->create(['workflow_flow_id' => $this->flow()->id, 'user_id' => $a->id]);

        $this->assertNull($draft->record_id);
        $this->assertSame([], $this->granted($b, [(int) $completed->record_id, (int) $withdrawn->record_id]));
        $this->assertSame([], $this->granted($a, [(int) $completed->record_id, (int) $withdrawn->record_id]));
    }

    public function test_mixed_ids_return_only_the_records_of_the_visible_active_cases_by_one_set_of_queries(): void
    {
        [$a, $b, $d] = $this->seededUsers('u0001', 'u0002', 'u0007');
        $mine = $this->processedCase(WorkflowCase::factory()->active(), $a, $b);
        $others = $this->processedCase(WorkflowCase::factory()->active(), $d, $d);
        $ids = [(int) $mine->record_id, (int) $others->record_id, 999_999_999];

        $this->granted($b, $ids);
        $one = $this->countQueries(fn () => $this->granted($b, [(int) $mine->record_id]));
        $three = $this->countQueries(fn () => $this->granted($b, $ids));

        $this->assertSame([(int) $mine->record_id], array_keys($this->granted($b, $ids)));
        $this->assertSame($one, $three);
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

    private function activeCase(WorkflowFlow $flow, User $applicant): WorkflowCase
    {
        return WorkflowCase::factory()->active()
            ->create(['workflow_flow_id' => $flow->id, 'user_id' => $applicant->id]);
    }

    /**
     * フロー(参照者の指定 = 渡した対象者。null なら参照者なし)。
     */
    private function flow(?int $referrerSubjectGroupId = null): WorkflowFlow
    {
        return WorkflowFlow::factory()->create(['authz_subject_group_id' => $referrerSubjectGroupId]);
    }
}
