<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Workflow\ApplicantApprovalGuard;
use App\Queries\Workflow\NodeTargetExpansion;
use App\Queries\Workflow\PreviousProcessors;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 前のノードからの到達(docs の 設計/ワークフロー/ルートの深さ/案件の操作/README.md 2.2 の 4・5、2.5、処理対象者.md 4〜5 節)。承認のノード = 展開(対象者・起点)か
 * 今の指定の人で到達させる。横配置・縦配置 = 今の指定の人数の展開の行を用意し(足りなければ足し、多い番号は省く)、横配置は子 1 だけ、縦配置は全部を到達させる。
 * 指定 0 人(今の指定の行が 0 件)なら省く。0 人・指定の人に申請基準日に無効な人がいる・申請者承認防止で申請者だけ → 操作全体を拒む(422 case = 4.6・5.3)。
 * 後のノードからの到達(差戻し・引戻し = 処理対象者.md 4.5)は展開せず戻す相手で到達させ、戻す相手が申請基準日に有効でなければ同じく拒む。配置の親への差戻しは
 * 展開し、差戻しで到達させる子(縦配置は全部、横配置は子 1)だけを、差戻しの直前に処理済みならその子が指していた履歴の行の処理者にする(ほかは今の指定の
 * 同じ番号の人)。進むで処理済みにしたノードと子は以前に処理していないのと同じに扱う(設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.1 節の 2)。
 */
class NodeArrival
{
    public function __construct(
        private NodeTargetExpansion $expansion,
        private PreviousProcessors $previousProcessors,
        private ApplicantApprovalGuard $applicantGuard,
    ) {}

    /**
     * @return bool 到達させた・展開した(偽 = 指定 0 人で省いた。呼び手が進め続ける)
     *
     * @throws ValidationException 0 人・無効な人・申請者だけ(case)
     */
    public function arrive(CaseRouteState $state, int $id): bool
    {
        $node = $state->node($id);

        if ($node->kind->isIn(WorkflowNodeKind::expanding())) {
            return $this->expand($state, $id);
        }

        if ($node->target_kind === WorkflowTargetKind::Designated) {
            $userIds = $state->assignmentOf($id);

            if ($userIds === []) {
                $state->skip($id);

                return false;
            }

            $this->requireEffective($state, $userIds, $node);
        } else {
            $userIds = $this->expansion->expand($node, $state->case->department_id, $state->previousDepartmentId, $state->baseDate);
        }

        $state->reach($id, $this->checked($state, $node, $userIds));

        return true;
    }

    /**
     * 横配置の子を到達させる(子 k の承認の後の子 k + 1。到達のたびに人を確かめる = 5.3)。
     *
     * @throws ValidationException 無効な人・申請者だけ(case)
     */
    public function reachChild(CaseRouteState $state, int $parentId, int $sequence): void
    {
        $userId = $state->assignmentOf($parentId)[$sequence - 1] ?? throw new LogicException("ノード {$parentId} に {$sequence} 番目の指定が無い");
        $childId = $state->childrenOf($parentId)[$sequence] ?? throw new LogicException("ノード {$parentId} に {$sequence} 番目の展開の行が無い");
        $child = $state->node($childId);
        $this->requireEffective($state, [$userId], $child);
        $state->reach($childId, $this->checked($state, $child, [$userId]));
    }

    /**
     * 差戻し先を到達させる(処理対象者.md 4.5。以前の処理者 = 差戻しの直前にそのノードが指していた履歴の行の処理者 = {@see PreviousProcessors}): 申請・承認の
     * ノード = 以前の処理者。横配置・縦配置 = 展開し、if(差戻しで到達させる子(縦配置は全部、横配置は子 1)&& 差戻しの直前に処理済み){その子が指していた
     * 履歴の行の処理者}else{今の指定の同じ番号の人}。以前の処理者が null(進むで処理済み)なら {@see self::arriveRecorded()}。
     *
     * @throws ValidationException 戻す相手が申請基準日に有効でない・申請者だけ・展開し直して 0 人(case)
     * @throws LogicException 申請・承認のノードが処理済みでない・配置の親の今の指定が 0 人のとき(差戻しの候補の不変の条件の破れ)
     */
    public function returnTo(CaseRouteState $state, int $id): void
    {
        if ($state->node($id)->kind->isIn(WorkflowNodeKind::expanding())) {
            $children = $state->childrenOf($id);
            $previous = $this->previousProcessors->of(array_values($children));
            $bySequence = array_filter(array_map(fn (int $childId) => $previous[$childId] ?? null, $children));

            if (! $this->expand($state, $id, $bySequence)) {
                throw new LogicException("差戻し先の配置の親 {$id} の今の指定が 0 人");
            }

            return;
        }

        $previous = $this->previousProcessors->of([$id]);

        if (! array_key_exists($id, $previous)) {
            throw new LogicException("差戻し先 {$id} が処理済みでない");
        }

        $previousUserId = $previous[$id];
        $previousUserId === null ? $this->arriveRecorded($state, $id) : $this->reachReturned($state, $id, [$previousUserId]);
    }

    /**
     * 進むで処理済みにしたノードへ戻す(以前に処理していないのと同じに到達させる = 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.1 節の 2)。
     *
     * @throws ValidationException
     */
    private function arriveRecorded(CaseRouteState $state, int $id): void
    {
        $node = $state->node($id);
        $case = $state->case;
        $userIds = match (true) {
            $node->kind === WorkflowNodeKind::Apply => [$case->user_id],
            $node->target_kind === WorkflowTargetKind::Designated => $state->assignmentOf($id),
            default => $this->expansion->expand(
                $node,
                $case->department_id,
                $this->expansion->recordedOriginOf($case, $node, $state->baseDate),
                $state->baseDate,
            ),
        };
        $this->requireEffective($state, $userIds, $node);
        $state->reach($id, $this->checked($state, $node, $userIds));
    }

    /**
     * 戻す相手で到達させる(展開しない。申請基準日に有効か・申請者承認防止で申請者だけかを確かめる = 4.5・4.6)。
     *
     * @param  list<int>  $userIds
     *
     * @throws ValidationException
     */
    public function reachReturned(CaseRouteState $state, int $id, array $userIds): void
    {
        $node = $state->node($id);
        $this->requireEffective($state, $userIds, $node);
        $state->reach($id, $this->checked($state, $node, $userIds));
    }

    /**
     * 横配置・縦配置を展開する(2.5)。差戻し先の展開は、差戻しで到達させる子(縦配置は全部、横配置は子 1)の今の指定の人を、その子の以前の処理者で
     * 置き換える(4.5)。確かめる人(5.3)= 到達させる人と、横配置の子 2〜n の今の指定(子 k − 1 の承認の後に今の指定で到達する = {@see self::reachChild()})。
     *
     * @param  array<int, int>  $previous  番号 → 以前の処理者(差戻し先の展開だけ。差戻しの直前に処理済みの子だけ)
     */
    private function expand(CaseRouteState $state, int $id, array $previous = []): bool
    {
        $assigned = $state->assignmentOf($id);

        if ($assigned === []) {
            $state->skip($id);

            return false;
        }

        $isParallel = $state->node($id)->kind === WorkflowNodeKind::Parallel;
        $userIds = self::usersOnExpand($isParallel, $assigned, $previous);
        $this->requireEffective($state, $userIds, $state->node($id));
        $children = $state->childrenOf($id);

        foreach ($children as $sequence => $childId) {
            if ($sequence > count($userIds) && $state->state($childId) !== WorkflowNodeState::Skipped) {
                $state->skip($childId);
            }
        }

        $state->expand($id);

        foreach ($userIds as $index => $userId) {
            $childId = $children[$index + 1] ?? $state->addChild($id, $index + 1);

            if (self::isReachedOnExpand($isParallel, $index)) {
                $state->reach($childId, $this->checked($state, $state->node($childId), [$userId]));
            }
        }

        return true;
    }

    /**
     * 展開の子ごとの人(4.5): 展開で到達させる子は以前の処理者(あれば)、ほかは今の指定の同じ番号の人。
     *
     * @param  non-empty-list<int>  $assigned  今の指定
     * @param  array<int, int>  $previous  番号 → 以前の処理者
     * @return non-empty-list<int>
     */
    private static function usersOnExpand(bool $isParallel, array $assigned, array $previous): array
    {
        $userIds = [];

        foreach ($assigned as $index => $userId) {
            $userIds[] = self::isReachedOnExpand($isParallel, $index) ? $previous[$index + 1] ?? $userId : $userId;
        }

        return $userIds;
    }

    /**
     * 展開で到達させる子か(2.5: 縦配置は全部、横配置は子 1 だけ)。$index = 番号 − 1。
     */
    private static function isReachedOnExpand(bool $isParallel, int $index): bool
    {
        return $isParallel || $index === 0;
    }

    /**
     * 0 人・申請者承認防止で申請者だけの確かめ(4.6)。
     *
     * @param  list<int>  $userIds
     * @return non-empty-list<int>
     *
     * @throws ValidationException
     */
    private function checked(CaseRouteState $state, WorkflowCaseNode $node, array $userIds): array
    {
        if ($userIds === []) {
            throw CaseOperationErrors::noTargets($node->displayName());
        }

        // 申請者として扱う人は代理の表を引くので、防止がオンのノードだけで引く
        $prevents = $node->prevents_applicant_approval;

        if ($prevents && $this->applicantGuard->onlyApplicants(true, $userIds, $this->applicantGuard->applicantUserIds($state->case))) {
            throw CaseOperationErrors::applicantOnlyTargets($node->displayName(), $userIds, $state->case->user_id);
        }

        return $userIds;
    }

    /**
     * 指定の人が全員、申請基準日に有効か(5.3。1 人でも無効なら 0 人と同じに拒む)。
     *
     * @param  list<int>  $userIds
     *
     * @throws ValidationException
     */
    private function requireEffective(CaseRouteState $state, array $userIds, WorkflowCaseNode $node): void
    {
        if (! $this->expansion->allEffectiveOn($userIds, $state->baseDate)) {
            throw CaseOperationErrors::noTargets($node->displayName());
        }
    }
}
