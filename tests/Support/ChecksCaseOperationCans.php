<?php

namespace Tests\Support;

use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseDetailQuery;
use App\Queries\Workflow\CaseRows;
use Illuminate\Support\Arr;

/**
 * 状態 × 操作の表のテスト({@see ChecksCaseOperationTable})の can の突き合わせ(docs の 設計/ワークフロー/認可と一覧.md 3.2 = 押すと 403・422 になるボタンを出さず、
 * 押せば通るボタンを消さない)。操作の前に閲覧できる人ごとの行・詳細の can を取り、各組の該当の旗を業務処理の成否と比べる。旗の無い組(案件が無い・閲覧できない)は外す。
 * 一時保存は行の can だけ、一時保存でない案件は行と詳細の両方(詳細の承認・承認終了・否認・差戻しはノードごとの nodeActions のどれかで、自分として出るボタン = ルートの深さ/画面/案件の側の画面.md 7 節。
 * 申請者承認防止の blockedReason があれば「決定」を押せないので、承認・承認終了・否認は出ないとして比べる。注目のノードを渡すと、そのノードの nodeActions だけで比べる
 * = ノードを取る操作をどの役割でも注目のノードで確かめる R1〜R3 の表)。旗の名前の書き違いで何も比べずに通らないよう、持つはずの旗のキーが can にあることも確かめる。
 */
trait ChecksCaseOperationCans
{
    /** 操作 → 画面の can の該当の旗(行・詳細。一時保存の画面を開く row.editDraft は、その画面から送る上書きと申請の旗) */
    private const CAN_FLAGS = [
        'overwrite_draft' => ['row.editDraft'], 'apply' => ['row.editDraft'], 'delete_draft' => ['row.deleteDraft'],
        'approve' => ['detail.approve'], 'approve_early' => ['detail.approveEarly'], 'reject' => ['detail.reject'], 'send_back' => ['detail.sendBack'],
        'pull_back' => ['row.pullBack', 'detail.pullBack'], 'reapply' => ['row.reapply', 'detail.reapply'], 'withdraw' => ['row.withdraw', 'detail.withdraw'],
    ];

    /**
     * 操作の前に、閲覧できる人ごとに画面が出す can(行 = {@see CaseRows::rows()}、詳細 = {@see CaseDetailQuery::show()}。キー = row.<旗>・detail.<旗>)。
     * 旗の無い組(案件が無い・閲覧できない)は持たない。一時保存は詳細を持たないので行だけ。
     *
     * @param  string  $visible  閲覧できる人の記号を並べた文字列
     * @param  int|null  $focusNodeId  注目のノード(null = nodeActions のどれか)
     * @return array<string, array<string, mixed>> 人の記号 → can
     */
    private function cansBefore(?WorkflowCase $case, string $visible, ?int $focusNodeId = null): array
    {
        if ($case === null) {
            return [];
        }

        $isDraft = $case->state === WorkflowCaseState::Draft;
        $flags = array_values(array_filter(array_unique(array_merge(...array_values(self::CAN_FLAGS))), fn (string $flag) => ! $isDraft || str_starts_with($flag, 'row.')));
        $cans = [];
        foreach (str_split($visible) as $letter) {
            $context = $this->contextOf($this->person($letter));
            $row = app(CaseRows::class)->rows(Requester::fromAccessContext($context), $context->date, $context->localeCode, WorkflowCase::query()->whereKey($case->id)->get())[0]['can'];
            $detail = $isDraft ? [] : $this->detailCans(app(CaseDetailQuery::class)->show($context, $case->id), $focusNodeId);
            $cans[$letter] = Arr::dot(['row' => $row, 'detail' => $detail]);
            $this->assertSame([], array_values(array_diff($flags, array_keys($cans[$letter]))), "{$letter} の can に無い旗");
        }

        return $cans;
    }

    /**
     * 1 つの組の該当の旗(CAN_FLAGS)が、業務処理の成否と一致するか。
     *
     * @param  array<string, mixed>  $can  その人の can(row.<旗>・detail.<旗>)
     */
    private function assertCansMatch(array $can, WorkflowOperation $operation, string $who, bool $succeeds): void
    {
        $shown = array_intersect_key($can, array_flip(self::CAN_FLAGS[$operation->value] ?? []));
        $this->assertSame(array_map(fn () => $succeeds, $shown), $shown, "{$operation->value} by {$who} の can");
    }

    /**
     * 詳細の can(案件に 1 つの can と、nodeActions のどれかのノード(注目のノードを渡せばそのノード)で自分として押せる承認・承認終了・否認・差戻し)。
     * nodeActions の旗は自分か代理元のどれかとして許される操作で出る(ボタンは選択肢があれば出る = 案件の運用/画面/案件の画面.md の 4 節)ので、
     * 自分として操作できるかは、その操作の principals に自分の選択肢(isSelf)があるかで読む。代理元として操作する役割は {@see ChecksCaseOperationTable} が別に比べる。
     *
     * @param  array{can: array<string, bool>, nodeActions: list<array<string, mixed>>}  $detail
     * @return array<string, bool>
     */
    private function detailCans(array $detail, ?int $focusNodeId): array
    {
        $actions = array_filter($detail['nodeActions'], fn (array $action) => $focusNodeId === null || $action['nodeId'] === $focusNodeId);
        $isSelf = fn (array $choice) => $choice['isSelf'] === true;
        $asSelf = fn (array $action, string $flag) => $action[$flag] === true
            && array_filter($action['principals'][$flag] ?? [], $isSelf) !== [];
        $any = fn (string $flag, bool $guarded) => array_filter($actions, fn (array $action) => $asSelf($action, $flag) && (! $guarded || $action['blockedReason'] === null)) !== [];

        return [...$detail['can'], 'approve' => $any('approve', true), 'approveEarly' => $any('approveEarly', true), 'reject' => $any('reject', true), 'sendBack' => $any('sendBack', false)];
    }
}
