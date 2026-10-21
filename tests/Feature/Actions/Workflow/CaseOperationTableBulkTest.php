<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Authz\Api\CreateSelectionSubjectGroup;
use App\Actions\Workflow\ApproveCaseEarly;
use App\Actions\Workflow\BulkOperationResult;
use App\Actions\Workflow\BulkResultRows;
use App\Models\Workflow\WorkflowCase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ChecksRouteOperationTable;
use Tests\Support\OperatesHolds;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsBulkOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 状態 × 操作の表(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 8 節、同時実行と検査.md の 14.1 節)のうち、一括承認・一括確認の行
 * (案件ごとに承認(承認待ち)・保留中の承認(保留)・確認の行と同じ)。見本 = 確認の表のテスト({@see CaseOperationTableConfirmTest})と同じ
 * R1(課長に確認する人 R)で、課長 B が合議 X・Y・Z と責任者 O を指定して承認した案件。E = u0016(X と R の代理先)。
 * 状況 = 確認が開いた後・X が合議の子 1 を保留した後・完了(X が承認終了)。役割 = 表の人の全員(ゲストを含む)。
 * 各人は画面と同じく行の組を送る(組が無ければ合議の子 1 を自分として)。期待 = 成功(承認済み・確認済み。成功の組は不変の条件)/
 * 閲覧できない(未処理・—・申請名なし)/ 閲覧できるが当たらない(処理済み・対応は不要です。)。失敗の組は何も変わらない。
 */
final class CaseOperationTableBulkTest extends TransactionalTestCase
{
    use ChecksRouteOperationTable;
    use OperatesHolds;
    use RegistersProxies;
    use RunsBulkOperations;

    /**
     * @return array<string, string|null>
     */
    protected function tablePeople(): array
    {
        return [...self::PEOPLE, ...self::ROUTE_PEOPLE, 'E' => 'u0016'];
    }

    /**
     * 確認が開いた後: X・Y・Z は自分の合議の子を、E は X として合議の子 1 を承認できる。確認は R だけ。
     */
    public function test_after_the_confirmation_opened(): void
    {
        $case = $this->confirmingCase();

        $this->assertBulkTable($case, ['approved' => 'EXYZ', 'confirmed' => 'R', 'visible' => 'ABERXYZ']);
    }

    /**
     * X が合議の子 1 を保留した後: 保留者側(X と、X として E)が保留中の子を承認できる。Y・Z は自分の子を承認できる。
     */
    public function test_after_a_child_was_held(): void
    {
        $case = $this->confirmingCase();
        $this->holdOn($case, 'council.1', $this->codeOf('X'));

        $this->assertBulkTable($case, ['approved' => 'EXYZ', 'confirmed' => 'R', 'visible' => 'ABERXYZ']);
    }

    /**
     * 完了(確認が開いていた。X が合議の子 1 で承認終了): 誰も承認・確認できない。
     */
    public function test_completed_while_the_confirmation_was_open(): void
    {
        $case = $this->confirmingCase();
        $this->operateOn(ApproveCaseEarly::class, $case, 'council.1', $this->codeOf('X'));

        $this->assertBulkTable($case, ['approved' => '', 'confirmed' => '', 'visible' => 'ABERX']);
    }

    /**
     * 表の人の全員で一括承認と一括確認を 1 件ずつ savepoint の中で行い、結果の行と書き込みを確かめる。
     *
     * @param  array{approved: string, confirmed: string, visible: string}  $spec  成功する人・閲覧できる人の記号
     */
    private function assertBulkTable(WorkflowCase $case, array $spec): void
    {
        $focus = $this->labelIds($case)['council.1'];
        $checked = 0;

        foreach (array_keys($this->tablePeople()) as $letter) {
            $user = $this->person($letter);
            $approval = $user === null ? null : $this->bulkApprovalOf($case, $user);
            $sent = $this->rowFor($case, $approval->nodeId ?? $focus, $approval?->principalUserId);
            $operations = [
                'approve' => [fn () => $this->bulkApprove($user, [$sent]), 'approved'],
                'confirm' => [fn () => $this->bulkConfirm($user, [$case->id]), 'confirmed'],
            ];

            foreach ($operations as $name => [$operate, $success]) {
                $expected = $this->expectedLine($case, $spec, $letter, $success);
                $this->attemptBulk($case, $operate, $expected, "{$name} by {$letter}");
                $checked++;
            }
        }

        $this->assertSame(2 * count($this->tablePeople()), $checked);
    }

    /**
     * @param  array{approved: string, confirmed: string, visible: string}  $spec
     * @param  'approved'|'confirmed'  $success  成功したときの結果
     * @return array{int, string, string|null, bool}
     */
    private function expectedLine(WorkflowCase $case, array $spec, string $letter, string $success): array
    {
        return match (true) {
            str_contains($spec[$success], $letter) => [$case->id, $success, null, true],
            ! str_contains($spec['visible'], $letter) => [$case->id, 'pending', null, false],
            default => [$case->id, 'processed', BulkResultRows::NO_ACTION_NEEDED, true],
        };
    }

    /**
     * @param  callable(): BulkOperationResult  $operate
     * @param  array{int, string, string|null, bool}  $expected
     */
    private function attemptBulk(WorkflowCase $case, callable $operate, array $expected, string $label): void
    {
        DB::beginTransaction();

        try {
            $before = [$this->workflowCounts(), WorkflowCase::query()->findOrFail($case->id)->getAttributes()];
            $this->assertSame([$expected], $this->resultLines($operate()), $label);

            if (in_array($expected[1], ['approved', 'confirmed'], true)) {
                $this->assertRouteInvariants($case);
            } else {
                $after = [$this->workflowCounts(), WorkflowCase::query()->findOrFail($case->id)->getAttributes()];
                $this->assertSame($before, $after, "{$label} で何か変わった");
            }
        } finally {
            DB::rollBack();
        }
    }

    /**
     * 課長に確認する人 R を付けた R1 を申請し、課長が合議 X・Y・Z と責任者 O を指定して承認した案件(R の確認が開いた)。
     * E は X の承認の代理先で、R の代理先でもある。
     */
    private function confirmingCase(): WorkflowCase
    {
        $confirmer = [$this->userCondition($this->codeOf('R'))];
        $group = app(CreateSelectionSubjectGroup::class)->execute($confirmer, ['ja' => '確認する人']);
        $this->addTableVersion($this->r1($group));
        $proxy = $this->seededUser($this->codeOf('E'));
        $this->addProxy($this->seededUser($this->codeOf('X')), $proxy);
        $this->addProxy($this->seededUser($this->codeOf('R')), $proxy);
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $this->approveSectionChief($case, 'XYZ');

        return $case;
    }
}
