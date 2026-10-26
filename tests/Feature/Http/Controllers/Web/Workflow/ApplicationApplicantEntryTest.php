<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\CreateDraft;
use App\Actions\Workflow\DraftInput;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use Carbon\CarbonImmutable;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 申請の系統の入口の 403 と、一時保存・再申請の画面の申請者(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 7 節・10.8 節、
 * 案件の運用/画面/案件の画面.md の 6.1 節)。新規の入口は自分か代理元のどれかが申請できれば通す。一時保存・再申請の入口と画面の GET の判定する人は
 * 業務処理と同じ式(持ち主・申請者が自分の申請の代理元ならその人、ほかは自分)。一時保存の画面の申請者 = 持ち主、再申請の画面 = 案件の申請者。
 * 代理元 A = u0009(総務部)、申請の代理先 B = u0002(東京営業課)。承認 = u0003 の 1 段のフローで差戻して再申請待ちを作る。
 */
final class ApplicationApplicantEntryTest extends TransactionalTestCase
{
    use RegistersProxies;
    use RunsWorkflowOperations;

    private int $flowId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow($this->operationDay()->toDateString().' 03:00:00 UTC');
        $this->flowId = $this->createFlow(approvals: [[$this->userCondition('u0003')]]);
    }

    /**
     * 新規の申請の GET・POST と新規の一時保存: 自分も代理元も申請できない → 403。代理元だけが申請できる → 通る(代理元として書く)。
     */
    public function test_the_new_entrances_pass_when_only_a_principal_can_apply(): void
    {
        [$a, $b] = $this->people();
        $this->addProxy($a, $b, WorkflowProxyKind::Apply, $this->flowId);
        $this->allowCreate();
        $this->actingAs($b);
        $rejected = [
            $this->get($this->newFormUrl())->status(),
            $this->post($this->newFormUrl(), $this->newPayload($a))->status(),
            $this->post($this->draftsUrl(), $this->newPayload($a))->status(),
        ];
        $this->allowCreate($a);

        $this->get($this->newFormUrl())->assertOk();
        $applied = $this->post($this->newFormUrl(), $this->newPayload($a))->assertStatus(302);
        $drafted = $this->post($this->draftsUrl(), $this->newPayload($a))->assertStatus(302);

        $this->assertSame([403, 403, 403], $rejected);
        $this->assertSame($a->id, $this->caseIn((string) $applied->headers->get('Location'))->user_id);
        $this->assertSame($a->id, $this->caseIn((string) $drafted->headers->get('Location'))->user_id);
    }

    /**
     * B は申請できず A は申請できる → A の一時保存の画面の GET・上書き・一時保存からの申請が通る(判定する人 = 持ち主)。
     */
    public function test_the_draft_entrances_are_judged_by_the_principal_owner(): void
    {
        [$a, $b] = $this->people();
        $this->addProxy($a, $b, WorkflowProxyKind::Apply, $this->flowId);
        $draft = $this->draftOf($a);
        $this->allowCreate($a);
        $this->actingAs($b);

        $this->get("/workflow/drafts/{$draft}/edit")->assertOk();
        $this->put("/workflow/drafts/{$draft}", $this->draftPayload($draft))
            ->assertRedirect("/workflow/drafts/{$draft}/edit");
        $this->post("/workflow/drafts/{$draft}/apply", $this->draftPayload($draft))
            ->assertRedirect("/workflow/cases/{$draft}");

        $this->assertSame('active', WorkflowCase::query()->findOrFail($draft)->state->value);
    }

    /**
     * B に update があり A に無い → A の案件の再申請の画面の GET と再申請の入口が 403(判定する人 = 申請者)。
     */
    public function test_the_reapply_entrances_are_judged_by_the_principal_applicant(): void
    {
        [$a, $b] = $this->people();
        $this->addProxy($a, $b, WorkflowProxyKind::Apply, $this->flowId);
        $case = $this->sentBackOf($a);
        $this->allowOnly($this->projectFormModuleCode(), RecordModuleAction::Update, $b);
        $this->actingAs($b);

        $this->get("/workflow/cases/{$case->id}/reapply")->assertForbidden();
        $this->post("/workflow/cases/{$case->id}/reapply", $this->reapplyPayload($case, $a))->assertForbidden();
    }

    /**
     * 自分の申請の代理元でない人の一時保存は今のまま 404、閲覧できる他人の案件の再申請は自分の update で 403(述語と判定の順は変えない)。
     */
    public function test_drafts_and_cases_of_others_keep_the_404_and_the_403(): void
    {
        [$a, $b, $approver] = [...$this->people(), $this->seededUser('u0003')];
        $draft = $this->draftOf($a);
        $case = $this->sentBackOf($a);
        $this->actingAs($b);
        $draftStatuses = [
            $this->get("/workflow/drafts/{$draft}/edit")->status(),
            $this->put("/workflow/drafts/{$draft}", $this->draftPayload($draft))->status(),
            $this->post("/workflow/drafts/{$draft}/apply", $this->draftPayload($draft))->status(),
        ];
        $this->allowOnly($this->projectFormModuleCode(), RecordModuleAction::Update, $a);
        $this->actingAs($approver);

        $this->assertSame([404, 404, 404], $draftStatuses);
        $this->get("/workflow/cases/{$case->id}/reapply")->assertForbidden();
    }

    /**
     * 一時保存の画面: 自分の一時保存は applicants = 自分・applicant = null。代理元の一時保存は持ち主で、次の処理担当者も持ち主で引く。
     * クエリ applicant を付けても変わらない。
     */
    public function test_the_draft_form_has_the_owner_as_the_applicant(): void
    {
        [$a, $b] = $this->people();
        $this->addProxy($a, $b, WorkflowProxyKind::Apply, $this->flowId);
        [$own, $principals] = [$this->draftOf($b), $this->draftOf($a)];
        $this->actingAs($b);

        $ownProps = $this->propsAt("/workflow/drafts/{$own}/edit?applicant={$a->id}");
        $principalProps = $this->propsAt("/workflow/drafts/{$principals}/edit");
        $queried = $this->propsAt("/workflow/drafts/{$principals}/edit?applicant={$b->id}");

        $this->assertSame([[$b->id], null], [array_column($ownProps['applicants'], 'id'), $ownProps['applicant']]);
        $this->assertSame([[$a->id], $a->id, '総務部'], [
            array_column($principalProps['applicants'], 'id'), $principalProps['applicant'],
            $principalProps['next']['departmentName'],
        ]);
        $this->assertSame([$principalProps['applicants'], $a->id], [$queried['applicants'], $queried['applicant']]);
    }

    /**
     * 再申請の画面: 自分の案件は applicant = null。代理元の案件を代理先が開くと applicants = 申請者・applicant = 申請者。
     */
    public function test_the_reapply_form_has_the_case_applicant_as_the_applicant(): void
    {
        [$a, $b] = $this->people();
        $this->addProxy($a, $b, WorkflowProxyKind::Apply, $this->flowId);
        [$own, $principals] = [$this->sentBackOf($b), $this->sentBackOf($a)];
        $this->actingAs($b);

        $ownProps = $this->propsAt("/workflow/cases/{$own->id}/reapply");
        $principalProps = $this->propsAt("/workflow/cases/{$principals->id}/reapply");

        $this->assertSame([[$b->id], null], [array_column($ownProps['applicants'], 'id'), $ownProps['applicant']]);
        $principalChoice = [array_column($principalProps['applicants'], 'id'), $principalProps['applicant']];
        $this->assertSame([[$a->id], $a->id], $principalChoice);
    }

    /**
     * @return array{User, User} [代理元 A, 代理先 B]
     */
    private function people(): array
    {
        return [$this->seededUser('u0009'), $this->seededUser('u0002')];
    }

    private function allowCreate(User ...$users): void
    {
        $this->allowOnly($this->projectFormModuleCode(), RecordModuleAction::Create, ...$users);
    }

    private function draftOf(User $owner): int
    {
        $input = new DraftInput('下書き', null, [], []);

        return app(CreateDraft::class)->execute($this->contextOf($owner), self::FLOW_CODE, $input);
    }

    private function sentBackOf(User $applicant): WorkflowCase
    {
        $case = $this->applyAs($applicant);
        $this->sendBack($this->seededUser('u0003'), $case, 'apply');

        return $case->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function propsAt(string $url): array
    {
        return $this->get($url)->assertOk()->viewData('page')['props'];
    }

    private function newFormUrl(): string
    {
        return '/workflow/flows/'.self::FLOW_CODE.'/apply';
    }

    private function draftsUrl(): string
    {
        return '/workflow/flows/'.self::FLOW_CODE.'/drafts';
    }

    /**
     * 新規の申請・一時保存の画面が送る形(申請者 = 代理元)。
     *
     * @return array<string, mixed>
     */
    private function newPayload(User $applicant): array
    {
        $baseDate = $this->operationDay()->toDateString();

        return [...$this->recordPayload(), 'base_date' => $baseDate, 'applicant_user_id' => $applicant->id];
    }

    /**
     * @return array<string, mixed>
     */
    private function draftPayload(int $caseId): array
    {
        $revision = WorkflowCase::query()->findOrFail($caseId)->revision;

        $baseDate = $this->operationDay()->toDateString();

        return [...$this->recordPayload(), 'revision' => $revision, 'base_date' => $baseDate];
    }

    /**
     * @return array<string, mixed>
     */
    private function reapplyPayload(WorkflowCase $case, User $principal): array
    {
        $revision = $this->revisionOf($case);

        return [...$this->recordPayload(), 'revision' => $revision, 'principal_user_id' => $principal->id];
    }

    /**
     * @return array{values: array<string, mixed>, details: array<string, list<array{values: array<string, mixed>}>>}
     */
    private function recordPayload(): array
    {
        $record = $this->projectInput()->record;

        $rows = [['values' => ['name' => '要件の整理', 'due_date' => '2026-11-30']]];

        return ['values' => $record->values, 'details' => ['milestones' => $rows]];
    }

    private function caseIn(string $location): WorkflowCase
    {
        $matched = preg_match('#/workflow/(?:cases|drafts)/(\d+)(?:/edit)?$#D', $location, $matches) === 1;

        return $matched ? WorkflowCase::query()->findOrFail((int) $matches[1]) : $this->fail("移り先の形が違う: {$location}");
    }
}
