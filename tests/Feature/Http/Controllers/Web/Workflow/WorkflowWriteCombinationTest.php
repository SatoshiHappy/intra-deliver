<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\CaseOperationErrors;
use App\Enums\Records\RecordModuleAction;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Database\Seeders\SeedingDates;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 書き込み → 移り先の権限の組み合わせの行(docs の 設計/ワークフロー/認可と一覧.md 7 節の「書き込み → 移り先」= ロードマップ「共通の進め方」の 6、画面.md 1.1):
 * update を禁止した申請者の取止め、承認 1 の処理対象者を兼ねる申請者の承認と引戻し、セッションで組織を選んだ利用者の申請・一時保存、申請できなくなった申請者の
 * 一時保存の削除、申請済みになった案件へ一時保存の画面から送った書き込み(422 case の後の戻り先の GET が詳細へ移る)。どれも移り先の GET が 200 で開くことまで通す。
 * 初期データ(案件の見本 = 初期データ.md 4 節)を入れ、ルートの認可を外さない。書き込みは画面と同じ Inertia の要求(X-Inertia)で送る。
 */
final class WorkflowWriteCombinationTest extends TransactionalTestCase
{
    use UsesSeededCases;
    use UsesSeededRecords;
    use WritesWorkflowInputs;

    /** 画面(Inertia の router)が送る要求の印 */
    private const INERTIA = ['X-Inertia' => 'true'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // 申請基準日に初期データの日付(版 2 の日)を送るので、今日を初期データの流す日に固定する(今日の前後 1 年の規則で日がたっても拒まれないため)
        CarbonImmutable::setTestNow(SeedingDates::BASE.' 03:00:00 UTC');
    }

    /**
     * 権限の組み合わせ (a): 業務モジュールの update を禁止した申請者も、取止め(update を求めない)はでき、詳細が 200 で開く。
     */
    public function test_the_applicant_without_update_on_the_module_withdraws_and_lands_on_the_detail(): void
    {
        $this->actingAsUser('u0001');
        $this->deny('project', RecordModuleAction::Update);
        $case = $this->caseNumbered(4);

        $this->post("/workflow/cases/{$case}/withdraw", ['revision' => $this->revisionOf($case), 'comment' => null], self::INERTIA)
            ->assertStatus(302)->assertSessionHasNoErrors()->assertRedirect("/workflow/cases/{$case}")
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '取止めました']);

        $this->get("/workflow/cases/{$case}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('case.state', 'completed')
            ->where('case.completionReason', 'withdrawn')
            ->where('can.reapply', false)
        );
    }

    /**
     * 権限の組み合わせ (b): 承認 1 の処理対象者(u0002)が自分で申請した案件を自分で承認し、その後に引き戻す。どの書き込みも詳細へ移り、詳細が 200 で開く。
     */
    public function test_the_applicant_who_is_also_the_first_approver_approves_and_pulls_back_landing_on_the_detail(): void
    {
        $this->actingAsUser('u0002');
        $applied = $this->post('/workflow/flows/project_plan/apply', ['name' => '', 'comment' => null, 'values' => $this->planValues('自分で承認する計画'), 'base_date' => $this->baseDate()], self::INERTIA);
        $applied->assertStatus(302)->assertSessionHasNoErrors();
        $case = $this->caseIdIn($applied, '#/workflow/cases/(\d+)$#D');
        $this->get("/workflow/cases/{$case}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('case.currentSummary.name', '課長の承認')
            ->where('nodes.1.people.0.isMe', true)
            ->where('nodeActions.0.approve', true)
        );

        $this->post("/workflow/cases/{$case}/approve", ['revision' => $this->revisionOf($case), 'comment' => null, 'node_id' => $this->currentNodeIdOf($case)], self::INERTIA)
            ->assertStatus(302)->assertSessionHasNoErrors()->assertRedirect("/workflow/cases/{$case}")
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '承認しました']);
        $this->get("/workflow/cases/{$case}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('case.currentSummary.name', '部長の承認')
            ->where('can.pullBack', true)
            ->where('pullBackNodes.0.name', '課長の承認')
        );

        $this->post("/workflow/cases/{$case}/pull-back", ['revision' => $this->revisionOf($case), 'comment' => null, 'node_id' => $this->pullBackNodeIdOf($case, 'u0002')], self::INERTIA)
            ->assertStatus(302)->assertSessionHasNoErrors()->assertRedirect("/workflow/cases/{$case}")
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '引戻しました']);
        $this->get("/workflow/cases/{$case}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('case.currentSummary.name', '課長の承認')
            ->where('case.currentSummary.state', 'waiting')
            ->where('histories', fn (Collection $histories) => $histories->pluck('action')->all() === ['apply', 'approve', 'pull_back'])
        );
    }

    /**
     * 権限の組み合わせ (c): セッションで組織を選んだ利用者(兼務の u0009 が人事部を選ぶ)の申請・一時保存・一時保存からの申請。移り先が 200 で開き、
     * 申請した案件の担当組織はセッションの組織(主所属の総務部でない)。
     */
    public function test_a_user_with_a_selected_department_applies_and_saves_a_draft_landing_on_the_destinations(): void
    {
        $this->actingAsUser('u0009');
        $humanResources = $this->departmentIdOf('human-resources');
        $this->withSession([AccessContext::CURRENT_DEPARTMENT_SESSION_KEY => $humanResources]);

        $applied = $this->post('/workflow/flows/project_plan/apply', ['name' => '', 'comment' => null, 'values' => $this->planValues('人事部の計画'), 'base_date' => $this->baseDate()], self::INERTIA);
        $applied->assertStatus(302)->assertSessionHasNoErrors();
        $case = $this->caseIdIn($applied, '#/workflow/cases/(\d+)$#D');
        $this->get("/workflow/cases/{$case}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('case.department.code', 'human-resources'));

        $saved = $this->post('/workflow/flows/project_plan/drafts', ['name' => '人事部の下書き', 'comment' => null, 'values' => ['name' => '人事部の下書き']], self::INERTIA);
        $saved->assertStatus(302)->assertSessionHasNoErrors();
        $draft = $this->caseIdIn($saved, '#/workflow/drafts/(\d+)/edit$#D');
        $this->get("/workflow/drafts/{$draft}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page->where('mode', 'draft')->where('case.id', $draft));

        $this->post("/workflow/drafts/{$draft}/apply", ['revision' => $this->revisionOf($draft), 'name' => '', 'comment' => null, 'values' => $this->planValues('人事部の下書き'), 'base_date' => $this->baseDate()], self::INERTIA)
            ->assertStatus(302)->assertSessionHasNoErrors()->assertRedirect("/workflow/cases/{$draft}");
        $this->get("/workflow/cases/{$draft}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('case.department.code', 'human-resources'));
    }

    /**
     * 権限の組み合わせ (d): 申請できなくなった申請者(業務モジュールの create を禁止)も一時保存を削除でき(削除は申請できる人を求めない)、一時保存のタブが 200 で開く。
     */
    public function test_the_applicant_who_cannot_apply_any_more_deletes_the_draft_and_lands_on_the_draft_tab(): void
    {
        $this->actingAsUser('u0001');
        $this->deny('project_issue', RecordModuleAction::Create);
        $draft = $this->seededDraft();

        $this->delete("/workflow/drafts/{$draft}", ['revision' => $this->revisionOf($draft)], self::INERTIA)
            ->assertStatus(303)->assertSessionHasNoErrors()->assertRedirect('/workflow/cases?tab=draft')
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '一時保存を削除しました']);

        $this->get('/workflow/cases?tab=draft')->assertOk()->assertInertia(fn (Assert $page) => $page->where('counts.draft', 0)->where('cases.total', 0));
    }

    /**
     * 一時保存の画面からの書き込み(行 = [メソッド, URL の末尾, 状態コード])。
     *
     * @return array<string, array{string, string, int}>
     */
    public static function draftWrites(): array
    {
        return [
            'overwrite' => ['put', '', 303],
            'apply' => ['post', '/apply', 302],
            'delete' => ['delete', '', 303],
        ];
    }

    /**
     * 権限の組み合わせ (e)(画面.md 1.1): 別のタブで申請した後に、開いたままの一時保存の画面から上書き・申請・削除を送ると、422 case(改訂番号の不一致)で
     * 一時保存の画面へ戻り、その GET が詳細へ移して「この案件は今この操作をできません」をトーストで出し、詳細が 200 で開く(利用者に出るのはトーストだけ)。
     */
    #[DataProvider('draftWrites')]
    public function test_a_draft_write_after_the_draft_was_applied_returns_to_the_draft_form_that_moves_to_the_detail(string $method, string $suffix, int $status): void
    {
        $this->actingAsUser('u0001');
        $draft = $this->seededDraft();
        $staleRevision = $this->revisionOf($draft);
        $form = "/workflow/drafts/{$draft}/edit";
        $this->post("/workflow/drafts/{$draft}/apply", ['revision' => $staleRevision, 'name' => '', 'comment' => null, 'values' => $this->issueValues('別のタブで申請'), 'base_date' => $this->baseDate()], self::INERTIA)
            ->assertStatus(302)->assertSessionHasNoErrors();
        $before = $this->revisionOf($draft);
        $payload = ['revision' => $staleRevision, ...($method === 'delete' ? [] : ['name' => '', 'comment' => null, 'values' => $this->issueValues('古い画面の値')])];

        $this->from($form)->{$method}("/workflow/drafts/{$draft}{$suffix}", $payload, self::INERTIA)
            ->assertStatus($status)->assertRedirect($form)
            ->assertSessionHasErrors(['case' => CaseOperationErrors::STALE_REVISION]);
        $this->assertSame($before, $this->revisionOf($draft));

        $this->get($form)->assertRedirect("/workflow/cases/{$draft}")
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => CaseOperationErrors::NOT_PERMITTED]);
        $this->get("/workflow/cases/{$draft}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Workflow/CaseShow')
            ->where('case.state', 'active')
            ->hasFlash('toast', ['type' => 'error', 'message' => CaseOperationErrors::NOT_PERMITTED])
            ->where('errors', [])
        );
    }
}
