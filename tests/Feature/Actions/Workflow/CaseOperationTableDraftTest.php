<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\CreateDraft;
use App\Actions\Workflow\DraftInput;
use App\Models\Workflow\WorkflowCase;
use Tests\Support\ChecksCaseOperationTable;
use Tests\Support\TransactionalTestCase;

/**
 * 状態 × 操作の表(docs の 設計/ワークフロー/案件の操作/操作.md 3.2、同時実行と検査.md 7 節)のうち、状況「案件なし」「一時保存」の全組。
 * 期待と記号は {@see ChecksCaseOperationTable}。
 */
final class CaseOperationTableDraftTest extends TransactionalTestCase
{
    use ChecksCaseOperationTable;

    /** 申請した直後(承認 1 が承認待ち) */
    private const APPLIED = ['nodes' => ['done/apply', 'waiting:B', 'unreached'], 'state' => 'active', 'histories' => 1, 'tabs' => ['draft' => '', 'pending' => 'B', 'processed' => '', 'referenced' => 'R']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTableFlow();
    }

    /**
     * 案件なし: 一時保存(新規)と申請(新規)は申請できる人だけが通り、ほかは 403(ゲストを含む)。申請者・処理者・参照者の役割と、既にある案件の操作は該当なし。
     */
    public function test_without_a_case(): void
    {
        $checked = $this->assertOperationTable(null, [
            'roles' => ['applicant' => null, 'referrer' => null, 'currentTarget' => null, 'lastActor' => null, 'previousActor' => null],
            'visible' => '',
            'success' => [
                'save_draft' => ['canApplyPerson', ['nodes' => [], 'state' => 'draft', 'histories' => 0, 'tabs' => ['draft' => 'P', 'pending' => '', 'processed' => '', 'referenced' => '']]],
                'apply' => ['canApplyPerson', $this->applied('P')],
            ],
        ]);

        $this->assertSame(4 * 2, $checked);
    }

    /**
     * 一時保存(A の): 上書き・削除・申請は申請者だけが通る。閲覧できるのは本人だけ(ほかは 404)。
     */
    public function test_a_draft(): void
    {
        $case = WorkflowCase::query()->findOrFail(app(CreateDraft::class)->execute($this->contextOf($this->person('A')), self::FLOW_CODE, new DraftInput('下書き', null, [], [])));

        $checked = $this->assertOperationTable($case, [
            'roles' => ['currentTarget' => null, 'lastActor' => null, 'previousActor' => null],
            'visible' => 'A',
            'success' => [
                'overwrite_draft' => ['applicant', ['nodes' => [], 'state' => 'draft', 'histories' => 0, 'tabs' => ['draft' => 'A', 'pending' => '', 'processed' => '', 'referenced' => '']]],
                'delete_draft' => ['applicant', ['nodes' => [], 'state' => 'deleted', 'histories' => 0, 'tabs' => ['draft' => '', 'pending' => '', 'processed' => '', 'referenced' => '']]],
                'apply' => ['applicant', $this->applied('A')],
            ],
        ]);

        $this->assertSame(6 * 21, $checked);
    }

    /**
     * @return array{nodes: list<string>, state: string, histories: int, tabs: array{draft: string, pending: string, processed: string, referenced: string}}
     */
    private function applied(string $applicant): array
    {
        return [...self::APPLIED, 'tabs' => [...self::APPLIED['tabs'], 'processed' => $applicant]];
    }
}
