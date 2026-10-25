<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Models\Workflow\WorkflowCase;
use Database\Seeders\WorkflowApplicationModules;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * ワークフローの書き込みの入口の判定の順(docs の 設計/ワークフロー/案件の操作/操作.md 3.1 の 1、認可と一覧.md 7 節): 壊れた入力でも、案件 404 → フロー 404 →
 * 申請できる人・update 403 が封筒の 422 より先。一時保存のルートの書き込みが一時保存でない案件を指すときは 404 でなく業務処理の 422 case。
 * 一時保存の削除は申請できる人を求めない。拒んだ要求は何も変えない。初期データを入れ、ルートの認可を外さない。
 */
final class WorkflowEntryOrderTest extends TransactionalTestCase
{
    use UsesSeededCases;
    use UsesSeededRecords;

    /** 封筒の形が壊れた入力(どのキーも 422 になる形) */
    private const BROKEN = ['name' => ['x'], 'comment' => ['x'], 'values' => 'x', 'details' => 'x', 'revision' => 'x', 'send_back_node_id' => 'x'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_flow_write_is_404_for_an_unknown_flow_then_403_for_a_guest_then_422_for_the_envelope(): void
    {
        $this->post('/workflow/flows/no_such_flow/apply', self::BROKEN)->assertNotFound();
        $this->post('/workflow/flows/no_such_flow/drafts', self::BROKEN)->assertNotFound();
        $this->post('/workflow/flows/project_plan/apply', self::BROKEN)->assertForbidden();
        $this->post('/workflow/flows/project_plan/drafts', self::BROKEN)->assertForbidden();

        $this->actingAsUser('u0001');
        $count = WorkflowCase::query()->count();
        $this->post('/workflow/flows/project_plan/apply', self::BROKEN)->assertSessionHasErrors(['name', 'comment', 'values', 'details']);
        $this->post('/workflow/flows/project_plan/drafts', self::BROKEN)->assertSessionHasErrors(['name', 'comment', 'values', 'details']);
        $this->post('/workflow/flows/project_plan/drafts', ['details' => ['milestones' => [['id' => 'x', 'values' => []]]]])
            ->assertSessionHasErrors(['details.milestones.0.id' => '明細の行の指定が正しくありません']);
        $this->assertSame($count, WorkflowCase::query()->count());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function caseWrites(): array
    {
        return [
            'draft update' => ['put', '/workflow/drafts/%d'],
            'draft apply' => ['post', '/workflow/drafts/%d/apply'],
            'draft delete' => ['delete', '/workflow/drafts/%d'],
            'approve' => ['post', '/workflow/cases/%d/approve'],
            'approve early' => ['post', '/workflow/cases/%d/approve-early'],
            'reject' => ['post', '/workflow/cases/%d/reject'],
            'send back' => ['post', '/workflow/cases/%d/send-back'],
            'pull back' => ['post', '/workflow/cases/%d/pull-back'],
            'withdraw' => ['post', '/workflow/cases/%d/withdraw'],
            'reapply' => ['post', '/workflow/cases/%d/reapply'],
        ];
    }

    #[DataProvider('caseWrites')]
    public function test_a_case_write_is_404_for_a_case_out_of_sight_before_the_envelope(string $method, string $path): void
    {
        $this->json($method, sprintf($path, 999999999), self::BROKEN)->assertNotFound();
        $this->json($method, sprintf($path, $this->seededDraft()), self::BROKEN)->assertNotFound();

        $this->actingAsUser('u0002');
        $this->json($method, sprintf($path, $this->seededDraft()), self::BROKEN)->assertNotFound();
        $this->actingAsUser('u0001');
        $this->json($method, sprintf($path, $this->caseNumbered(6)), self::BROKEN)->assertNotFound();
    }

    #[DataProvider('caseWrites')]
    public function test_a_visible_case_write_rejects_the_broken_envelope_with_422(string $method, string $path): void
    {
        $this->actingAsUser('u0001');

        $this->json($method, sprintf($path, $this->caseNumbered(4)), self::BROKEN)->assertUnprocessable()->assertJsonValidationErrors(['revision']);
    }

    public function test_a_draft_write_to_a_non_draft_case_is_422_case_not_404(): void
    {
        $this->actingAsUser('u0001');
        $case = WorkflowCase::query()->findOrFail($this->caseNumbered(4));

        $this->putJson("/workflow/drafts/{$case->id}", ['revision' => $case->revision])->assertUnprocessable()->assertJsonValidationErrors(['case']);
        $this->postJson("/workflow/drafts/{$case->id}/apply", ['revision' => $case->revision])->assertUnprocessable()->assertJsonValidationErrors(['case']);
        $this->deleteJson("/workflow/drafts/{$case->id}", ['revision' => $case->revision])->assertUnprocessable()->assertJsonValidationErrors(['case']);
        $this->assertSame($case->revision, $case->fresh()?->revision);
    }

    public function test_the_reapplication_is_403_without_update_before_the_envelope(): void
    {
        $this->actingAsUser('u0001');
        $this->deny(WorkflowApplicationModules::PLAN, RecordModuleAction::Update);

        $this->postJson('/workflow/cases/'.$this->caseNumbered(4).'/reapply', self::BROKEN)->assertForbidden();
    }

    public function test_the_draft_writes_are_403_without_applicability_but_the_delete_is_not(): void
    {
        $this->actingAsUser('u0001');
        $this->deny(WorkflowApplicationModules::ISSUE, RecordModuleAction::Create);
        $draft = $this->seededDraft();

        $this->putJson("/workflow/drafts/{$draft}", self::BROKEN)->assertForbidden();
        $this->postJson("/workflow/drafts/{$draft}/apply", self::BROKEN)->assertForbidden();
        $this->postJson('/workflow/flows/issue_resolution/drafts', self::BROKEN)->assertForbidden();
        $this->deleteJson("/workflow/drafts/{$draft}", self::BROKEN)->assertUnprocessable()->assertJsonValidationErrors(['revision']);
    }
}
