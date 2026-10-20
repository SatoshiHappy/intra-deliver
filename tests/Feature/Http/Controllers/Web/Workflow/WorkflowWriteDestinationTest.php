<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use Carbon\CarbonImmutable;
use Database\Seeders\SeedingDates;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 書き込み → 移り先(docs の 設計/ワークフロー/認可と一覧.md 7 節の「書き込み → 移り先」= 2 段目の完了条件、画面.md 1.1 の応答): 申請・一時保存・
 * 一時保存の上書き・一時保存からの申請・一時保存の削除・処理 6 つ・再申請を、許される役割の利用者(申請できる人・申請者・処理対象者・直前の処理者)で
 * HTTP から送り、移り先(詳細・一時保存の画面・案件一覧の一時保存のタブ)・成功のトースト・状態コード(POST は 302、PUT・DELETE は Inertia の
 * 要求で 303 = 案件の操作/同時実行と検査.md 4 節)を確かめ、移り先を GET して 200 で開けること(書き込みの後に 403・404 を見ない)と書いた結果を見る。
 * 初期データ(案件の見本 = 初期データ.md 4 節)を入れ、ルートの認可を外さない。要求は画面と同じ Inertia の要求(X-Inertia)で送る。権限の組み合わせの行は
 * {@see WorkflowWriteCombinationTest}。
 */
final class WorkflowWriteDestinationTest extends TransactionalTestCase
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

    public function test_an_applicable_user_applies_and_lands_on_the_detail_of_the_new_case(): void
    {
        $this->actingAsUser('u0001');
        $count = WorkflowCase::query()->count();

        $response = $this->post('/workflow/flows/project_plan/apply', ['name' => '', 'comment' => '計画を申請します。', 'values' => $this->planValues('新しい計画'), 'base_date' => $this->baseDate()], self::INERTIA);

        $response->assertStatus(302)->assertSessionHasNoErrors()->assertInertiaFlash('toast', ['type' => 'success', 'message' => '申請しました']);
        $caseId = $this->caseIdIn($response, '#/workflow/cases/(\d+)$#D');
        $this->assertSame($count + 1, WorkflowCase::query()->count());
        $this->get("/workflow/cases/{$caseId}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Workflow/CaseShow')
            ->where('case.name', 'プロジェクト計画の承認 新しい計画')
            ->where('case.state', 'active')
            ->where('case.currentSummary.name', '課長の承認')
            ->where('case.currentSummary.state', 'waiting')
            ->where('nodes.0.processed.action', 'apply')
            ->where('content.record.values.name', '新しい計画')
        );
    }

    public function test_an_applicable_user_saves_a_draft_and_lands_on_the_draft_form(): void
    {
        $this->actingAsUser('u0001');

        $response = $this->post('/workflow/flows/project_plan/drafts', ['name' => '下書きの計画', 'comment' => null, 'values' => ['name' => '下書きの計画']], self::INERTIA);

        $response->assertStatus(302)->assertSessionHasNoErrors()->assertInertiaFlash('toast', ['type' => 'success', 'message' => '一時保存しました']);
        $caseId = $this->caseIdIn($response, '#/workflow/drafts/(\d+)/edit$#D');
        $this->get("/workflow/drafts/{$caseId}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Workflow/Apply')
            ->where('mode', 'draft')
            ->where('case.id', $caseId)
            ->where('case.revision', 0)
            ->where('initial.name', '下書きの計画')
            ->where('initial.values.name', '下書きの計画')
        );
        $this->get('/workflow/cases?tab=draft')->assertOk()->assertInertia(fn (Assert $page) => $page->where('counts.draft', 2));
    }

    public function test_the_applicant_overwrites_the_draft_and_lands_on_the_draft_form(): void
    {
        $this->actingAsUser('u0001');
        $draft = $this->seededDraft();
        $revision = $this->revisionOf($draft);

        $response = $this->put("/workflow/drafts/{$draft}", ['revision' => $revision, 'name' => '課題の対応の承認 様式の変更', 'comment' => null, 'values' => ['title' => '様式の変更']], self::INERTIA);

        $response->assertStatus(303)->assertSessionHasNoErrors()->assertRedirect("/workflow/drafts/{$draft}/edit")
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '一時保存しました']);
        $this->get("/workflow/drafts/{$draft}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Workflow/Apply')
            ->where('case.revision', $revision + 1)
            ->where('initial.name', '課題の対応の承認 様式の変更')
            ->where('initial.values.title', '様式の変更')
        );
    }

    public function test_the_applicant_applies_the_draft_and_lands_on_the_detail(): void
    {
        $this->actingAsUser('u0001');
        $draft = $this->seededDraft();

        $response = $this->post("/workflow/drafts/{$draft}/apply", ['revision' => $this->revisionOf($draft), 'name' => '', 'comment' => null, 'values' => $this->issueValues('請求書の様式の変更'), 'base_date' => $this->baseDate()], self::INERTIA);

        $response->assertStatus(302)->assertSessionHasNoErrors()->assertRedirect("/workflow/cases/{$draft}")
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '申請しました']);
        $this->get("/workflow/cases/{$draft}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Workflow/CaseShow')
            ->where('case.number', fn (string $number) => preg_match('/^WF-\d{6}$/D', $number) === 1)
            ->where('case.name', '課題の対応の承認 請求書の様式の変更')
            ->where('case.state', 'active')
            ->where('case.currentSummary.name', '課長の承認')
        );
        $this->get('/workflow/cases?tab=draft')->assertOk()->assertInertia(fn (Assert $page) => $page->where('counts.draft', 0));
    }

    public function test_the_applicant_deletes_the_draft_and_lands_on_the_draft_tab(): void
    {
        $this->actingAsUser('u0001');
        $draft = $this->seededDraft();

        $response = $this->delete("/workflow/drafts/{$draft}", ['revision' => $this->revisionOf($draft)], self::INERTIA);

        $response->assertStatus(303)->assertSessionHasNoErrors()->assertRedirect('/workflow/cases?tab=draft')
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '一時保存を削除しました']);
        $this->assertNull(WorkflowCase::query()->find($draft));
        $this->get('/workflow/cases?tab=draft')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Workflow/CaseIndex')
            ->where('query.tab', 'draft')
            ->where('counts.draft', 0)
            ->where('cases.total', 0)
        );
    }

    /**
     * 処理 6 つと再申請(どれも POST → 案件の詳細)。行 = [操作の URL の末尾, 利用者(役割), 案件番号, 差戻し先の位置, トースト, 書いた後の案件の状態,
     * 完了の理由, 現在のノードの名前, ノードの状態(位置の順), 最後の履歴の種別]。
     *
     * @return array<string, array{string, string, int, int|null, string, string, string|null, string|null, list<string>, string}>
     */
    public static function caseActions(): array
    {
        return [
            'approve to the next node by the current target' => ['approve', 'u0002', 2, null, '承認しました', 'active', null, '部長の承認', ['done', 'done', 'waiting'], 'approve'],
            'approve the last node by the current target' => ['approve', 'u0003', 3, null, '承認しました', 'completed', 'approved', null, ['done', 'done', 'done'], 'approve'],
            'approve early by the current target' => ['approve-early', 'u0002', 5, null, '承認終了しました', 'completed', 'approved_early', null, ['done', 'done', 'unreached'], 'approve_early'],
            'reject by the current target' => ['reject', 'u0002', 2, null, '否認しました', 'completed', 'rejected', null, ['done', 'done', 'unreached'], 'reject'],
            'send back to an approval node by the current target' => ['send-back', 'u0003', 3, 2, '差戻しました', 'active', null, '課長の承認', ['done', 'waiting', 'unreached'], 'send_back'],
            'send back to the application by the current target' => ['send-back', 'u0002', 2, 1, '差戻しました', 'active', null, '申請', ['reapply', 'unreached', 'unreached'], 'send_back'],
            'pull back an approval by the previous processor' => ['pull-back', 'u0002', 3, null, '引戻しました', 'active', null, '課長の承認', ['done', 'waiting', 'unreached'], 'pull_back'],
            'pull back the application by the applicant' => ['pull-back', 'u0001', 2, null, '引戻しました', 'active', null, '申請', ['reapply', 'unreached', 'unreached'], 'pull_back'],
            'withdraw by the applicant waiting for reapplication' => ['withdraw', 'u0001', 4, null, '取止めました', 'completed', 'withdrawn', null, ['done', 'unreached', 'unreached'], 'withdraw'],
            'reapply by the applicant waiting for reapplication' => ['reapply', 'u0001', 4, null, '再申請しました', 'active', null, '課長の承認', ['done', 'waiting', 'unreached'], 'reapply'],
        ];
    }

    /**
     * @param  list<string>  $nodeStates
     */
    #[DataProvider('caseActions')]
    public function test_a_case_action_by_an_allowed_role_lands_on_the_detail_with_the_toast(string $action, string $user, int $number, ?int $sendBackPosition, string $message, string $state, ?string $completionReason, ?string $currentNode, array $nodeStates, string $lastAction): void
    {
        $this->actingAsUser($user);
        $case = $this->caseNumbered($number);
        $revision = $this->revisionOf($case);
        $payload = $action === 'reapply'
            ? ['revision' => $revision, 'name' => '', 'comment' => '予算を変更しました。', 'values' => $this->planValuesOfCase4(['budget' => 120])]
            : ['revision' => $revision, 'comment' => 'コメントです。', 'node_id' => $action === 'pull-back' ? $this->pullBackNodeIdOf($case, $user) : $this->currentNodeIdOf($case), ...($sendBackPosition === null ? [] : ['send_back_node_id' => $this->nodeIdOf($case, $sendBackPosition)])];

        $response = $this->post("/workflow/cases/{$case}/{$action}", $payload, self::INERTIA);

        $response->assertStatus(302)->assertSessionHasNoErrors()->assertRedirect("/workflow/cases/{$case}")
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => $message]);
        $this->assertSame($revision + 1, $this->revisionOf($case));
        $this->get("/workflow/cases/{$case}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Workflow/CaseShow')
            ->where('case.state', $state)
            ->where('case.completionReason', $completionReason)
            ->where('case.currentSummary', fn (?Collection $summary) => $summary?->get('name') === $currentNode)
            ->where('nodes', fn (Collection $nodes) => $nodes->pluck('state')->all() === $nodeStates)
            ->where('histories', fn (Collection $histories) => $histories->last()['action'] === $lastAction)
        );
    }

    public function test_the_send_back_source_goes_back_to_unreached(): void
    {
        $this->actingAsUser('u0003');
        $case = $this->caseNumbered(3);

        $this->post("/workflow/cases/{$case}/send-back", ['revision' => $this->revisionOf($case), 'comment' => null, 'node_id' => $this->currentNodeIdOf($case), 'send_back_node_id' => $this->nodeIdOf($case, 1)], self::INERTIA)
            ->assertStatus(302)->assertSessionHasNoErrors();

        $this->get("/workflow/cases/{$case}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('nodes.0.state', 'reapply')
            ->where('nodes.1.state', 'unreached')
            ->where('nodes.2.state', 'unreached')
            ->where('nodes.2.processed', null)
            ->where('histories', fn (Collection $histories) => $histories->last()['action'] === 'send_back')
        );
    }

    public function test_the_reapplication_records_the_changed_values_in_the_history_diff(): void
    {
        $this->actingAsUser('u0001');
        $case = $this->caseNumbered(4);

        $this->post("/workflow/cases/{$case}/reapply", ['revision' => $this->revisionOf($case), 'name' => '', 'comment' => null, 'values' => $this->planValuesOfCase4(['budget' => 120])], self::INERTIA)
            ->assertStatus(302)->assertSessionHasNoErrors();

        $this->get("/workflow/cases/{$case}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('content.record.values.budget', 120)
            ->where('histories', fn (Collection $histories) => $histories->last()['diff']['fields'] !== [])
        );
    }

    private function nodeIdOf(int $caseId, int $position): int
    {
        return WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->where('position', $position)->sole()->id;
    }
}
