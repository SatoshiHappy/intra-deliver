<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\PullBackCase;
use Tests\Support\ChecksCaseOperationTable;
use Tests\Support\TransactionalTestCase;

/**
 * 状態 × 操作の表(docs の 設計/ワークフロー/案件の操作/操作.md 3.2、同時実行と検査.md 7 節)のうち、未完了の 5 つの状況の全組。期待と記号は
 * {@see ChecksCaseOperationTable}。役割が重なる組(例: 申請の直後の直前の処理者 = 申請者)と、いない役割(引戻しの後の直前の処理者)は該当なし(null)。
 */
final class CaseOperationTableActiveTest extends TransactionalTestCase
{
    use ChecksCaseOperationTable;

    /** 未完了の案件の操作の数(一時保存(新規)を除く 14) */
    private const OPERATIONS = 14;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTableFlow();
    }

    /**
     * 申請の直後(承認 1 が B の承認待ち。直前の処理者 = 申請者 A)。
     */
    public function test_waiting_at_the_first_approval(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));

        $checked = $this->assertOperationTable($case, [
            'roles' => ['currentTarget' => 'B', 'lastActor' => null, 'previousActor' => null],
            'visible' => 'ABR',
            'success' => [
                ...$this->approverOutcomes('AB', [
                    'approve' => ['done/apply', 'done/approve', 'waiting:C'], 'approve_early' => ['done/apply', 'done/approve_early', 'unreached'],
                    'reject' => ['done/apply', 'done/reject', 'unreached'], 'send_back' => ['reapply:A', 'unreached', 'unreached'],
                    'hold' => ['done/apply', 'hold:B', 'unreached'],
                    'transfer' => ['done/apply', 'waiting:U', 'unreached'],
                ], 'C'),
                'pull_back' => ['applicant', $this->active(['reapply:A', 'unreached', 'unreached'], 'A', 'A')],
            ],
        ]);

        $this->assertSame(7 * self::OPERATIONS, $checked);
    }

    /**
     * 承認 1 を B が承認した後(承認 2 が C の承認待ち。直前の処理者 = B)。
     */
    public function test_waiting_at_the_second_approval(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $this->operate(ApproveCase::class, $this->person('B'), $case);

        $checked = $this->assertOperationTable($case, [
            'roles' => ['currentTarget' => 'C', 'lastActor' => 'B', 'previousActor' => null],
            'visible' => 'ABCR',
            'success' => [
                ...$this->approverOutcomes('ABC', [
                    'approve' => ['done/apply', 'done/approve', 'done/approve'], 'approve_early' => ['done/apply', 'done/approve', 'done/approve_early'],
                    'reject' => ['done/apply', 'done/approve', 'done/reject'], 'send_back' => ['reapply:A', 'unreached', 'unreached'],
                    'hold' => ['done/apply', 'done/approve', 'hold:C'],
                    'transfer' => ['done/apply', 'done/approve', 'waiting:U'],
                ], null),
                'pull_back' => ['lastActor', $this->active(['done/apply', 'waiting:B', 'unreached'], 'B', 'AB')],
            ],
        ]);

        $this->assertSame(8 * self::OPERATIONS, $checked);
    }

    /**
     * 承認 2 の C が申請へ差し戻した後(申請のノードが A の再申請待ち。直前の処理者 = C、以前の処理者 = B)。
     */
    public function test_waiting_for_reapplication_after_a_send_back(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $this->operate(ApproveCase::class, $this->person('B'), $case);
        $this->sendBack($this->person('C'), $case, 'apply');

        $checked = $this->assertOperationTable($case, [
            'roles' => ['currentTarget' => null, 'lastActor' => 'C', 'previousActor' => 'B'],
            'visible' => 'ABCR',
            'success' => [
                'reapply' => ['applicant', $this->active(['done/reapply', 'waiting:B', 'unreached'], 'B', 'ABC')],
                'withdraw' => ['applicant', $this->completed(['done/withdraw', 'unreached', 'unreached'], 'withdrawn', 'ABC')],
                'pull_back' => ['lastActor', $this->active(['done/apply', 'done/approve', 'waiting:C'], 'C', 'ABC')],
            ],
        ]);

        $this->assertSame(8 * self::OPERATIONS, $checked);
    }

    /**
     * B が承認 1 を承認して引き戻した後(承認 1 が B の承認待ち。最新の行は引戻しなので直前の処理者はいない)。
     */
    public function test_after_a_pull_back(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $this->operate(ApproveCase::class, $this->person('B'), $case);
        $this->operate(PullBackCase::class, $this->person('B'), $case);

        $checked = $this->assertOperationTable($case, [
            'roles' => ['currentTarget' => 'B', 'lastActor' => null, 'previousActor' => null],
            'visible' => 'ABR',
            'success' => $this->approverOutcomes('AB', [
                'approve' => ['done/apply', 'done/approve', 'waiting:C'], 'approve_early' => ['done/apply', 'done/approve_early', 'unreached'],
                'reject' => ['done/apply', 'done/reject', 'unreached'], 'send_back' => ['reapply:A', 'unreached', 'unreached'],
                'hold' => ['done/apply', 'hold:B', 'unreached'],
                'transfer' => ['done/apply', 'waiting:U', 'unreached'],
            ], 'C'),
        ]);

        $this->assertSame(7 * self::OPERATIONS, $checked);
    }

    /**
     * 承認 2 の C が承認 1 へ差し戻した直後(承認 1 が B の承認待ち。直前の処理者 = C)。
     */
    public function test_right_after_a_send_back_to_the_first_approval(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $this->operate(ApproveCase::class, $this->person('B'), $case);
        $this->sendBack($this->person('C'), $case, 'approval1');

        $checked = $this->assertOperationTable($case, [
            'roles' => ['currentTarget' => 'B', 'lastActor' => 'C', 'previousActor' => null],
            'visible' => 'ABCR',
            'success' => [
                ...$this->approverOutcomes('ABC', [
                    'approve' => ['done/apply', 'done/approve', 'waiting:C'], 'approve_early' => ['done/apply', 'done/approve_early', 'unreached'],
                    'reject' => ['done/apply', 'done/reject', 'unreached'], 'send_back' => ['reapply:A', 'unreached', 'unreached'],
                    'hold' => ['done/apply', 'hold:B', 'unreached'],
                    'transfer' => ['done/apply', 'waiting:U', 'unreached'],
                ], 'C'),
                'pull_back' => ['lastActor', $this->active(['done/apply', 'done/approve', 'waiting:C'], 'C', 'ABC')],
            ],
        ]);

        $this->assertSame(8 * self::OPERATIONS, $checked);
    }

    /**
     * 承認待ちの現在のノードの処理対象者の 6 つの操作の結果(承認は次へ到達するか完了、承認終了・否認は完了、差戻しは申請のノードへ、保留・振替はノードに留まる)。
     *
     * @param  string  $processed  操作の後に処理済に出る人
     * @param  array{approve: list<string>, approve_early: list<string>, reject: list<string>, send_back: list<string>, hold: list<string>, transfer: list<string>}  $nodes  操作ごとの後のノード
     * @param  string|null  $pendingAfterApproval  承認の後に未処理に出る人(null = 最後のノードなので完了)
     * @return array<string, array{string, array{nodes: list<string>, state: string, histories: int, tabs: array{draft: string, pending: string, processed: string, referenced: string}}}>
     */
    private function approverOutcomes(string $processed, array $nodes, ?string $pendingAfterApproval): array
    {
        return [
            'approve' => ['currentTarget', $pendingAfterApproval === null ? $this->completed($nodes['approve'], 'approved', $processed) : $this->active($nodes['approve'], $pendingAfterApproval, $processed)],
            'approve_early' => ['currentTarget', $this->completed($nodes['approve_early'], 'approved_early', $processed)],
            'reject' => ['currentTarget', $this->completed($nodes['reject'], 'rejected', $processed)],
            'send_back' => ['currentTarget', $this->active($nodes['send_back'], 'A', $processed)],
            // 保留は処理対象者の行を保ち全員の未処理に残る、振替は振替先 U の未処理に移る(どちらも処理者の処理済に出る)
            'hold' => ['currentTarget', $this->active($nodes['hold'], $this->heldTargets($nodes['hold']), $processed)],
            'transfer' => ['currentTarget', $this->active($nodes['transfer'], 'U', $processed)],
        ];
    }

    /**
     * 保留中のノードの処理対象者の記号(期待のノードの形の「hold:<記号>」から)。
     *
     * @param  list<string>  $nodes
     */
    private function heldTargets(array $nodes): string
    {
        $held = array_values(array_filter($nodes, fn (string $node) => str_starts_with($node, 'hold:')));

        return substr($held[0] ?? 'hold:', strlen('hold:'));
    }

    /**
     * @param  list<string>  $nodes
     * @return array{nodes: list<string>, state: string, histories: int, tabs: array{draft: string, pending: string, processed: string, referenced: string}}
     */
    private function active(array $nodes, string $pending, string $processed): array
    {
        return ['nodes' => $nodes, 'state' => 'active', 'histories' => 1, 'tabs' => ['draft' => '', 'pending' => $pending, 'processed' => $processed, 'referenced' => 'R']];
    }

    /**
     * @param  list<string>  $nodes
     * @return array{nodes: list<string>, state: string, histories: int, tabs: array{draft: string, pending: string, processed: string, referenced: string}}
     */
    private function completed(array $nodes, string $reason, string $processed): array
    {
        return ['nodes' => $nodes, 'state' => "completed:{$reason}", 'histories' => 1, 'tabs' => ['draft' => '', 'pending' => '', 'processed' => $processed, 'referenced' => 'R']];
    }
}
