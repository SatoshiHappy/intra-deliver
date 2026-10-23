<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\CaseOperationErrors;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseNodeTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 許されない役割・権限の組の書き込み(docs の 設計/ワークフロー/認可と一覧.md 7 節の「書き込み → 移り先」の後半、案件の操作/操作.md 3.1・3.2): 封筒の正しい
 * 入力(今の改訂番号を含む)を送っても、閲覧できない人は 404、申請できない人・再申請で update を許されない人は 403、閲覧できるが状態 × 役割の表で
 * 許されない人は 422 case になり、案件・ノード・処理対象者・履歴・レコードが何も変わらない。初期データ(案件の見本)を入れ、ルートの認可を外さない。
 */
final class WorkflowWriteRejectionTest extends TransactionalTestCase
{
    use UsesSeededCases;
    use UsesSeededRecords;
    use WritesWorkflowInputs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * 行 = [利用者(null = ゲスト), メソッド, URL(%d = 案件の id), 案件(番号・'draft' = 初期データの一時保存・null = フローを指す), 入力の種類,
     * 消す許可 [業務モジュール, アクション] か null, 状態コード]。
     *
     * @return array<string, array{string|null, string, string, int|'draft'|null, 'plan'|'issue'|'action'|'send_back'|'reapply'|'revision', array{string, RecordModuleAction}|null, int}>
     */
    public static function rejectedWrites(): array
    {
        return [
            'apply by a guest' => [null, 'post', '/workflow/flows/project_plan/apply', null, 'plan', null, 403],
            'apply without create on the module' => ['u0001', 'post', '/workflow/flows/project_plan/apply', null, 'plan', ['project', RecordModuleAction::Create], 403],
            'save a draft by a guest' => [null, 'post', '/workflow/flows/project_plan/drafts', null, 'plan', null, 403],
            'overwrite the draft of another user' => ['u0002', 'put', '/workflow/drafts/%d', 'draft', 'issue', null, 404],
            'overwrite the own draft without create' => ['u0001', 'put', '/workflow/drafts/%d', 'draft', 'issue', ['project_issue', RecordModuleAction::Create], 403],
            'apply the draft of another user' => ['u0002', 'post', '/workflow/drafts/%d/apply', 'draft', 'issue', null, 404],
            'apply the own draft without create' => ['u0001', 'post', '/workflow/drafts/%d/apply', 'draft', 'issue', ['project_issue', RecordModuleAction::Create], 403],
            'delete the draft of another user' => ['u0002', 'delete', '/workflow/drafts/%d', 'draft', 'revision', null, 404],
            'delete a draft by a guest' => [null, 'delete', '/workflow/drafts/%d', 'draft', 'revision', null, 404],
            'approve by the applicant' => ['u0001', 'post', '/workflow/cases/%d/approve', 2, 'action', null, 422],
            'approve by a referrer' => ['u0003', 'post', '/workflow/cases/%d/approve', 2, 'action', null, 422],
            // u0009 は初期データの代理で u0003 の代理先(設計/ワークフロー/案件の運用/初期データ.md 4.1)なので、案件にも代理にも関わらない u0005
            'approve by an unrelated user' => ['u0005', 'post', '/workflow/cases/%d/approve', 3, 'action', null, 404],
            'approve a completed case by its last processor' => ['u0003', 'post', '/workflow/cases/%d/approve', 1, 'action', null, 422],
            'approve early by the previous processor' => ['u0002', 'post', '/workflow/cases/%d/approve-early', 3, 'action', null, 422],
            'reject by the applicant' => ['u0001', 'post', '/workflow/cases/%d/reject', 5, 'action', null, 422],
            'send back by the previous processor' => ['u0002', 'post', '/workflow/cases/%d/send-back', 3, 'send_back', null, 422],
            'pull back by the current target' => ['u0003', 'post', '/workflow/cases/%d/pull-back', 3, 'action', null, 422],
            'pull back by the applicant after an approval' => ['u0001', 'post', '/workflow/cases/%d/pull-back', 3, 'action', null, 422],
            'withdraw by the approver who sent back' => ['u0002', 'post', '/workflow/cases/%d/withdraw', 4, 'action', null, 422],
            'withdraw while waiting for an approval' => ['u0001', 'post', '/workflow/cases/%d/withdraw', 2, 'action', null, 422],
            'reapply by the approver who sent back' => ['u0002', 'post', '/workflow/cases/%d/reapply', 4, 'reapply', null, 422],
            'reapply without update on the module' => ['u0001', 'post', '/workflow/cases/%d/reapply', 4, 'reapply', ['project', RecordModuleAction::Update], 403],
            'reapply by a guest' => [null, 'post', '/workflow/cases/%d/reapply', 4, 'reapply', null, 404],
        ];
    }

    /**
     * @param  int|'draft'|null  $case
     * @param  'plan'|'issue'|'action'|'send_back'|'reapply'|'revision'  $input
     * @param  array{string, RecordModuleAction}|null  $denied
     */
    #[DataProvider('rejectedWrites')]
    public function test_a_write_by_a_role_that_is_not_allowed_is_rejected_and_changes_nothing(?string $user, string $method, string $path, int|string|null $case, string $input, ?array $denied, int $status): void
    {
        $caseId = match (true) {
            $case === 'draft' => $this->seededDraft(),
            is_int($case) => $this->caseNumbered($case),
            default => null,
        };
        $payload = $this->inputOf($input, $caseId);
        if ($denied !== null) {
            $this->deny(...$denied);
        }
        if ($user !== null) {
            $this->actingAsUser($user);
        }
        $before = $this->snapshot();

        $response = $this->json($method, sprintf($path, $caseId ?? 0), $payload);

        $response->assertStatus($status);
        if ($status === 422) {
            $response->assertJsonValidationErrors(['case' => CaseOperationErrors::NOT_PERMITTED]);
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_stale_revision_is_rejected_with_the_message_to_reload_and_changes_nothing(): void
    {
        $this->actingAsUser('u0002');
        $case = $this->caseNumbered(2);
        $before = $this->snapshot();

        $this->postJson("/workflow/cases/{$case}/approve", ['revision' => $this->revisionOf($case) - 1, 'comment' => null, 'node_id' => $this->currentNodeIdOf($case)])
            ->assertUnprocessable()->assertJsonValidationErrors(['case' => CaseOperationErrors::STALE_REVISION]);

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * 封筒の正しい入力(既にある案件は今の改訂番号を送る)。
     *
     * @param  'plan'|'issue'|'action'|'send_back'|'reapply'|'revision'  $input
     * @return array<string, mixed>
     */
    private function inputOf(string $input, ?int $caseId): array
    {
        $revision = $caseId === null ? [] : ['revision' => $this->revisionOf($caseId)];

        return [...$revision, ...match ($input) {
            'plan' => ['name' => '', 'comment' => null, 'values' => $this->planValues('拒まれる計画'), 'base_date' => $this->baseDate()],
            'issue' => ['name' => '', 'comment' => null, 'values' => $this->issueValues('拒まれる課題'), 'base_date' => $this->baseDate()],
            'action' => ['comment' => 'コメントです。', 'node_id' => $this->currentNodeIdOf((int) $caseId)],
            'send_back' => ['comment' => 'コメントです。', 'node_id' => $this->currentNodeIdOf((int) $caseId), 'send_back_node_id' => $this->applyNodeIdOf((int) $caseId)],
            'reapply' => ['name' => '', 'comment' => null, 'values' => $this->planValuesOfCase4(['budget' => 120])],
            'revision' => [],
        }];
    }

    private function applyNodeIdOf(int $caseId): int
    {
        return WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->where('position', 1)->sole()->id;
    }

    /**
     * 書き込みで変わりうる行の全部(案件・ノード・処理対象者・履歴の件数・レコードの値と更新日時)。
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'cases' => WorkflowCase::query()->orderBy('id')->get(['id', 'state', 'completion_reason', 'case_number', 'name', 'draft_input', 'revision', 'record_id'])->toArray(),
            'nodes' => WorkflowCaseNode::query()->orderBy('id')->get(['id', 'state', 'workflow_case_history_id'])->toArray(),
            'targets' => WorkflowCaseNodeTarget::query()->orderBy('id')->get(['workflow_case_node_id', 'user_id'])->toArray(),
            'histories' => WorkflowCaseHistory::query()->count(),
            'records' => Record::query()->orderBy('id')->get(['id', 'field_values', 'updated_at'])->toArray(),
        ];
    }
}
