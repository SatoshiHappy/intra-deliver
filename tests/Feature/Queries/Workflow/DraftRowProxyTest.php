<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Workflow\CreateDraft;
use App\Actions\Workflow\DraftInput;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseRows;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 一覧の一時保存の行の can.editDraft・can.deleteDraft の判定する人(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.8 節): 持ち主が自分の
 * 申請の代理元なら持ち主で見る。行のリンクが一時保存の入口(画面の GET・上書き・削除)の結果と食い違わない。
 * 代理元 A = u0009(持ち主)、申請の代理先 B = u0002。
 */
final class DraftRowProxyTest extends TransactionalTestCase
{
    use RegistersProxies;
    use RunsWorkflowOperations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow($this->operationDay()->toDateString().' 03:00:00 UTC');
        $this->createFlow(approvals: [[$this->userCondition('u0003')]]);
    }

    /**
     * 自分の一時保存 / 代理元の一時保存を代理先が見る: どちらも真で、入口が通る。
     */
    public function test_the_own_draft_and_the_draft_of_a_principal_can_be_edited_and_deleted(): void
    {
        [$a, $b] = [$this->seededUser('u0009'), $this->seededUser('u0002')];
        $this->addProxy($a, $b, WorkflowProxyKind::Apply, $this->flowIdOf(self::FLOW_CODE));
        [$own, $principals] = [$this->draftOf($b), $this->draftOf($a)];

        $this->assertSame([true, true, 200], $this->rowAndEntry($b, $own));
        $this->assertSame([true, true, 200], $this->rowAndEntry($b, $principals));
    }

    /**
     * 代理元の一時保存で代理元が申請できない: editDraft は偽で、入口(画面の GET)は 403。deleteDraft は申請できる人を求めない
     * (申請できなくなった人も自分の一時保存を消せる)ので真のままで、代理先は削除できる。
     */
    public function test_a_draft_of_a_principal_who_cannot_apply_cannot_be_edited(): void
    {
        [$a, $b] = [$this->seededUser('u0009'), $this->seededUser('u0002')];
        $this->addProxy($a, $b, WorkflowProxyKind::Apply, $this->flowIdOf(self::FLOW_CODE));
        $draft = $this->draftOf($a);
        $this->allowOnly($this->projectFormModuleCode(), RecordModuleAction::Create, $b);

        $this->assertSame([false, true, 403], $this->rowAndEntry($b, $draft));

        $revision = WorkflowCase::query()->findOrFail($draft)->revision;
        $this->delete("/workflow/drafts/{$draft}", ['revision' => $revision])->assertRedirect();
        $this->assertDatabaseMissing('workflow_cases', ['id' => $draft]);
    }

    /**
     * 代理の期間の外の代理先には、行が出ない(閲覧できない)ので入口は 404。持ち主の一時保存を自分の代理先でない人が見ても真にならない。
     */
    public function test_a_user_who_is_not_a_proxy_of_the_owner_gets_false(): void
    {
        [$a, $b] = [$this->seededUser('u0009'), $this->seededUser('u0002')];
        $this->addProxy($a, $b, WorkflowProxyKind::Apply, $this->flowIdOf(self::FLOW_CODE), end: '2026-10-13');
        $draft = $this->draftOf($a);

        $this->assertSame([false, false, 404], $this->rowAndEntry($b, $draft));
    }

    /**
     * @return array{bool, bool, int} [行の can.editDraft, 行の can.deleteDraft, 一時保存の画面の GET の状態]
     */
    private function rowAndEntry(User $user, int $draftId): array
    {
        $requester = Requester::fromAccessContext($this->contextOf($user));
        $cases = WorkflowCase::query()->whereKey($draftId)->get();
        $row = app(CaseRows::class)->rows($requester, $this->operationDay(), AccessContext::LOCALE_CODE, $cases)[0];
        $this->actingAs($user);

        $status = $this->get("/workflow/drafts/{$draftId}/edit")->status();

        return [$row['can']['editDraft'], $row['can']['deleteDraft'], $status];
    }

    private function draftOf(User $owner): int
    {
        $input = new DraftInput('下書き', null, [], []);

        return app(CreateDraft::class)->execute($this->contextOf($owner), self::FLOW_CODE, $input);
    }
}
