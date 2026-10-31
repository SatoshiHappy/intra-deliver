<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\ApplicantApprovalTerm;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Authz\Api\Requester;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * 案件の詳細のノードごとの操作の可否(docs の 設計/ワークフロー/ルートの深さ/画面/案件の側の画面.md の 6 節・7 節の nodeActions、
 * 設計/ワークフロー/案件の運用/画面/案件の画面.md の 4 節): 判定を求める者が処理対象者の現在のノードごとに、操作ごとの処理担当者の選択肢
 * principals(処理者 P の候補 = {@see ProcessorCandidates} のうち、P を処理者として状態 × 役割の表 {@see CaseOperationDecider::permitsIn()}
 * = 業務処理と同じ口が許すもの。案件の運用/案件の操作/README.md の 4.3 節)を引く。操作の真偽は選択肢があるか。差戻しは候補
 * {@see SendBackCandidates}(業務処理と同じ規則)が空なら偽にする。候補と可否はここでだけ作り、呼び手は上書きしない。
 * blockedReason(承認・承認終了・否認)と holdBlockedReason(保留)= 申請者承認防止に当たれば業務処理と同じ文言({@see ApplicantApprovalGuard}。
 * 処理者 = その操作の既定の処理担当者 = 選択肢の先頭 = 案件の運用/案件の操作/同時実行と検査.md の 13 節。業務処理が正本で、画面は「決定」を押せなくするだけ)。指定の欄は
 * {@see CaseDesignations}。sendBackRestricted = 差戻しの制限の 1 行を出すか、isCouncilVote = 縦配置の子(否認のダイアログの文を変える)。
 * 各選択肢の canAttach = 操作 × 処理者 × 現在のノードの添付の可否({@see CaseAttachability}。設計/ワークフロー/申請の周辺/添付/README.md の 5 節)。
 * 鍵を取らない。
 *
 * @phpstan-import-type Person from WorkflowPeople
 *
 * @phpstan-type Principal array{id: int, name: string, isSelf: bool, canAttach: bool}
 * @phpstan-type NodeAction array{nodeId: int, name: string, approve: bool, approveEarly: bool, reject: bool,
 *     sendBack: bool, hold: bool, release: bool, transfer: bool, blockedReason: string|null,
 *     holdBlockedReason: string|null,
 *     sendBackTargets: list<array{id: int, name: string}>, sendBackRestricted: bool, isCouncilVote: bool,
 *     principals: array<string, list<Principal>>}
 * @phpstan-type SendBack array{sendBackTargets: list<array{id: int, name: string}>, sendBackRestricted: bool}
 */
class CaseNodeActions
{
    /** 操作のキー → 操作(並び = 見出しの右のボタンの順 = 案件の画面.md の 4 節) */
    private const OPERATIONS = [
        'approve' => WorkflowOperation::Approve,
        'approveEarly' => WorkflowOperation::ApproveEarly,
        'reject' => WorkflowOperation::Reject,
        'sendBack' => WorkflowOperation::SendBack,
        'hold' => WorkflowOperation::Hold,
        'release' => WorkflowOperation::Release,
        'transfer' => WorkflowOperation::Transfer,
    ];

    /** 申請者承認防止で拒む操作のうち、承認の文言を出すもの(処理対象者.md 6.1) */
    private const GUARDED = ['approve', 'approveEarly', 'reject'];

    /** 申請者承認防止で拒む操作のうち、保留の文言を出すもの(案件の運用/案件の操作/README.md の 5 節) */
    private const HOLD_GUARDED = ['hold'];

    public function __construct(
        private CaseOperationDecider $decider,
        private SendBackCandidates $sendBackCandidates,
        private ApplicantApprovalGuard $guard,
        private CaseAttachability $attachability,
    ) {}

    /**
     * 処理対象者の現在のノード(写した並び)のうち、どれかの操作がどれかの候補で許されるもの。
     *
     * @param  ProcessorCandidates|null  $candidates  処理者 P の候補(判定を求める者が d に有効でなければ null)
     * @param  array<int, Person>  $people  候補の表示
     * @param  Closure(): bool  $moduleEnabled  案件のフローの業務モジュールの値(詳細の表示で 1 回だけ読む。選択肢があるときに呼ぶ)
     * @return list<NodeAction>
     */
    public function of(
        Requester $requester,
        DateTimeInterface $date,
        CaseSituation $situation,
        ?ProcessorCandidates $candidates,
        array $people,
        Closure $moduleEnabled,
    ): array {
        $isTarget = fn (CurrentNode $node) => $situation->isTargetOf($node);
        $targets = array_values(array_filter($situation->currentNodes, $isTarget));

        if ($candidates === null || $targets === []) {
            return [];
        }

        $rows = self::nodeRowsOf($targets);
        $applicants = null;
        $applicantsOf = function () use (&$applicants, $situation): array {
            return $applicants ??= $this->guard->applicantUserIds($situation->case);
        };
        $attach = $this->attachCheckOf($situation, $candidates, $moduleEnabled);
        $actions = [];

        foreach ($targets as $node) {
            $processors = $this->permittedProcessors($requester, $date, $situation, $node, $candidates);
            [$processors, $sendBack] = $this->withSendBackTargets($processors, $node->id);
            $row = $rows->get($node->id) ?? throw new LogicException("現在のノード {$node->id} の行が無い");

            if ($processors !== []) {
                $actions[] = [
                    'nodeId' => $node->id,
                    'name' => $node->name,
                    'approve' => isset($processors['approve']),
                    'approveEarly' => isset($processors['approveEarly']),
                    'reject' => isset($processors['reject']),
                    'sendBack' => isset($processors['sendBack']),
                    'hold' => isset($processors['hold']),
                    'release' => isset($processors['release']),
                    'transfer' => isset($processors['transfer']),
                    ...$this->blockedReasons($row, $situation, $candidates->userId, $processors, $applicantsOf),
                    ...$sendBack,
                    'isCouncilVote' => $row->parent?->kind === WorkflowNodeKind::Parallel,
                    'principals' => self::principalsOf($processors, $people, $row, $attach, $candidates->userId),
                ];
            }
        }

        return $actions;
    }

    /**
     * どれかの選択肢が添付を受けるか(詳細の attachmentLimits を出すかの材料 = 添付/README.md の 5 節)。
     *
     * @param  list<NodeAction>  $actions
     */
    public static function anyCanAttach(array $actions): bool
    {
        foreach ($actions as $action) {
            foreach ($action['principals'] as $choices) {
                if (in_array(true, array_column($choices, 'canAttach'), true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 差戻しの候補(差戻しを許す処理者があるときだけ引く)。候補が空なら差戻しを選択肢から外す。
     *
     * @param  array<string, non-empty-list<int>>  $processors
     * @return array{array<string, non-empty-list<int>>, SendBack}
     */
    private function withSendBackTargets(array $processors, int $nodeId): array
    {
        $sendBack = isset($processors['sendBack'])
            ? $this->sendBackCandidates->withRestriction($nodeId)
            : ['candidates' => [], 'isRestricted' => false];
        $sendBackTarget = fn (WorkflowCaseNode $candidate) => ['id' => $candidate->id, 'name' => $candidate->name];
        $sendBackTargets = array_map($sendBackTarget, $sendBack['candidates']);

        if ($sendBackTargets === []) {
            unset($processors['sendBack']);
        }

        $sendBackProps = ['sendBackTargets' => $sendBackTargets, 'sendBackRestricted' => $sendBack['isRestricted']];

        return [$processors, $sendBackProps];
    }

    /**
     * 操作ごとに、表が許す処理者 P(候補の並び。許す P が無い操作は入れない)。案件の管理者の役割は自分として操作するときだけ
     * ({@see ProcessorRoles})なので、operate の判定はどの P にも同じ値を渡す。
     *
     * @return array<string, non-empty-list<int>>
     */
    private function permittedProcessors(
        Requester $requester,
        DateTimeInterface $date,
        CaseSituation $situation,
        CurrentNode $node,
        ProcessorCandidates $candidates,
    ): array {
        $processors = [];

        foreach (self::OPERATIONS as $key => $operation) {
            $permits = fn (int $processor) => $this->decider->permitsIn(
                $operation, $requester, $date, $candidates->situationFor($situation, $processor), $node->id,
                canOperate: $candidates->canOperate,
            );
            $permitted = array_values(array_filter($candidates->forKind($node->kind), $permits));

            if ($permitted !== []) {
                $processors[$key] = $permitted;
            }
        }

        return $processors;
    }

    /**
     * 申請者承認防止の文言(承認・承認終了・否認 = blockedReason、保留 = holdBlockedReason)。
     *
     * @param  array<string, non-empty-list<int>>  $processors  操作 → 許す処理者(並びの先頭が既定の処理担当者)
     * @param  callable(): list<int>  $applicantsOf  申請者として扱う人(要るときだけ引く)
     * @return array{blockedReason: string|null, holdBlockedReason: string|null}
     */
    private function blockedReasons(
        WorkflowCaseNode $node,
        CaseSituation $situation,
        int $userId,
        array $processors,
        callable $applicantsOf,
    ): array {
        $approval = ApplicantApprovalGuard::approvalMessage(...);
        $hold = ApplicantApprovalGuard::holdMessage(...);

        return [
            'blockedReason' => $this->blockedReason(
                $node, $situation, $userId, $processors, self::GUARDED, $approval, $applicantsOf,
            ),
            'holdBlockedReason' => $this->blockedReason(
                $node, $situation, $userId, $processors, self::HOLD_GUARDED, $hold, $applicantsOf,
            ),
        ];
    }

    /**
     * 申請者承認防止の文言(渡した操作のどれかが許され、ノードの旗が立ち、その操作の既定の処理担当者 P と自分(実行者)の組が拒まれる人のとき。
     * ほかは null)。
     *
     * @param  array<string, non-empty-list<int>>  $processors  操作 → 許す処理者(並びの先頭が既定の処理担当者)
     * @param  list<string>  $operations  防止で拒む操作のキー
     * @param  Closure(ApplicantApprovalTerm): string  $message  拒まれる項の文言(承認か保留)
     * @param  callable(): list<int>  $applicantsOf  申請者として扱う人(要るときだけ引く)
     */
    private function blockedReason(
        WorkflowCaseNode $node,
        CaseSituation $situation,
        int $userId,
        array $processors,
        array $operations,
        Closure $message,
        callable $applicantsOf,
    ): ?string {
        $guarded = array_values(array_intersect_key($processors, array_flip($operations)));

        if ($guarded === [] || ! $node->prevents_applicant_approval) {
            return null;
        }

        $actor = new CaseActor($guarded[0][0], $userId);
        $term = ApplicantApprovalGuard::termOf($actor, $situation->case->user_id, $applicantsOf());

        return $term === null ? null : $message($term);
    }

    /**
     * 処理対象者の現在のノードの行(親つき)。
     *
     * @param  list<CurrentNode>  $targets
     * @return Collection<int, WorkflowCaseNode> id → 行
     */
    private static function nodeRowsOf(array $targets): Collection
    {
        $ids = array_map(fn (CurrentNode $node) => $node->id, $targets);

        return WorkflowCaseNode::query()->with('parent')->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * 操作 × 現在のノード × 処理者の添付の可否(設計/ワークフロー/申請の周辺/添付/README.md の 5 節)。業務モジュールの値は呼び手が
     * 渡す遅延の値で、選択肢があるときに 1 回だけ読まれる。保留解除だけ、処理者で読んだ状況を渡す(保留者側かで変わる = 同 2 節)。
     *
     * @param  Closure(): bool  $moduleEnabled
     * @return Closure(WorkflowOperation, WorkflowCaseNode, int): bool
     */
    private function attachCheckOf(
        CaseSituation $situation,
        ProcessorCandidates $candidates,
        Closure $moduleEnabled,
    ): Closure {
        $case = $situation->case;
        $flow = CaseRows::flowOf($case);

        return fn (WorkflowOperation $op, WorkflowCaseNode $node, int $processor) => $this->attachability->canAttach(
            $op,
            $flow,
            $case,
            $node,
            $op === WorkflowOperation::Release ? $candidates->situationFor($situation, $processor) : null,
            $moduleEnabled(),
        );
    }

    /**
     * 処理担当者の選択肢の表示(名前が無ければコード = 画面の人の表示の規則)と、選択肢ごとの添付の可否。
     *
     * @param  array<string, non-empty-list<int>>  $processors
     * @param  array<int, Person>  $people
     * @param  Closure(WorkflowOperation, WorkflowCaseNode, int): bool  $attach
     * @return array<string, list<Principal>>
     */
    private static function principalsOf(
        array $processors,
        array $people,
        WorkflowCaseNode $node,
        Closure $attach,
        int $userId,
    ): array {
        $result = [];

        foreach (self::OPERATIONS as $key => $operation) {
            $principal = fn (int $id) => [
                'id' => $id,
                'name' => $people[$id]['name'] ?? $people[$id]['code'] ?? throw new LogicException("候補 {$id} の表示が無い"),
                'isSelf' => $id === $userId,
                'canAttach' => $attach($operation, $node, $id),
            ];

            if (isset($processors[$key])) {
                $result[$key] = array_map($principal, $processors[$key]);
            }
        }

        return $result;
    }
}
