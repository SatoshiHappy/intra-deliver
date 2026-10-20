<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Workflow\PullBackableNodes;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Testing\TestResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ワークフローの書き込みの HTTP のテストが送る正しい入力(業務データの入れ物の封筒の値 = 初期データの業務モジュール project・project_issue の項目)と、
 * 案件の今の改訂番号・応答の移り先の案件の id。UsesSeededRecords と一緒に使う(課題の参照先のプロジェクトを名前で引く)。
 */
trait WritesWorkflowInputs
{
    /**
     * プロジェクト計画の承認の申請で通る値(必須の項目と終了日・予算)。
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function planValues(string $name, array $changes = []): array
    {
        return [
            'name' => $name, 'department' => $this->departmentIdOf('tokyo-sales'), 'manager' => $this->userIdOf('u0002'), 'status' => 'planning',
            'start_date' => '2027-04-01', 'end_date' => '2027-06-30', 'budget' => 100, ...$changes,
        ];
    }

    /**
     * 案件の見本 WF-000004(販促資料の刷新)のレコードの今の値に changes を重ねたもの(再申請で送る全項目)。
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function planValuesOfCase4(array $changes = []): array
    {
        return [
            'name' => '販促資料の刷新', 'department' => $this->departmentIdOf('tokyo-sales'), 'manager' => $this->userIdOf('u0001'), 'status' => 'planning',
            'start_date' => '2026-12-01', 'end_date' => null, 'budget' => 80, 'summary' => null, ...$changes,
        ];
    }

    /**
     * 課題の対応の承認の申請で通る値(参照先 = 初期データのプロジェクト「経費精算の電子化」)。
     *
     * @return array<string, mixed>
     */
    private function issueValues(string $title): array
    {
        return ['title' => $title, 'project' => $this->recordNamed('project', 'name', '経費精算の電子化')->id, 'priority' => 'medium', 'status' => 'open'];
    }

    /**
     * 申請・一時保存からの申請の申請基準日(段 2 の初期データの見本と同じ版 2 の日 = 計画のフローは一本道 A、課題のフローは版 1。段 2 の書き込みのテストの意味を保つ。
     * 版 3 の分岐 B の申請はルートの深さのテストが書く)。
     */
    private function baseDate(): string
    {
        return WorkflowSeeder::DATES['stage2_plan_base_date'];
    }

    /**
     * 処理のダイアログが送る node_id(一本道の案件の現在のノード = 詳細の nodeActions の 1 つ。現在のノードが無ければ申請のノード = 表で拒まれる)。
     */
    private function currentNodeIdOf(int $caseId): int
    {
        $current = WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->whereIn('state', WorkflowNodeState::current())->orderBy('id')->first();

        return ($current ?? WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->where('position', 1)->sole())->id;
    }

    /**
     * 引戻しのダイアログが送る node_id(詳細・一覧の行の pullBackNodes の先頭 = その人が引戻せるノードの先頭)。
     */
    private function pullBackNodeIdOf(int $caseId, string $userCode): int
    {
        $nodes = app(PullBackableNodes::class)->of([$caseId], $this->userIdOf($userCode))[$caseId];

        return ($nodes[0] ?? throw new LogicException("{$userCode} が案件 {$caseId} で引戻せるノードが無い"))->id;
    }

    private function revisionOf(int $caseId): int
    {
        return WorkflowCase::query()->findOrFail($caseId)->revision;
    }

    /**
     * 応答の移り先(Location)から案件の id を取り出す(新しく作った案件の id は送る前に分からない)。
     *
     * @param  TestResponse<Response>  $response
     */
    private function caseIdIn(TestResponse $response, string $pattern): int
    {
        $location = (string) $response->headers->get('Location');

        if (preg_match($pattern, $location, $matches) !== 1) {
            $this->fail("リダイレクト先の形が違う: {$location}");
        }

        return (int) $matches[1];
    }

    private function departmentIdOf(string $code): int
    {
        return Department::query()->where('code', $code)->sole()->id;
    }

    private function userIdOf(string $code): int
    {
        return User::query()->where('code', $code)->sole()->id;
    }
}
