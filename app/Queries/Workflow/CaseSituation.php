<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCase;

/**
 * 1 つの案件について、判定の表と can が読む状況(docs の 設計/ワークフロー/ルートの深さ/案件の操作/README.md 1.3・3.1)。作るのは
 * {@see CaseOperationDecider::situationsOf()} と、処理者を替える {@see self::withProcessor()}(引戻せるノードを持たない)の 2 つ。
 * 現在のノードは {@see CurrentNodes} の集合(1 つと見なさない)、処理対象者に当たるかはノードごと({@see CaseTargetTerms} の結果)。
 * 役割(d に有効か)はここでは見ず、{@see CaseOperationDecider} が判定を求める者から決める。
 */
final readonly class CaseSituation
{
    /**
     * @param  list<CurrentNode>  $currentNodes  現在のノード(写した並び。無ければ空)
     * @param  list<int>  $targetNodeIds  現在のノードのうち、判定を求める者が処理対象者に当たるものの id(d に有効でなければ空)
     * @param  array<int, array{name: string, blockedReason: string|null}>  $pullBackNodes  判定を求める者が引戻せるノードの id → 表示名と申請者承認防止の文言(写した並び。{@see PullBackableNodes}。d に有効でなければ空)
     * @param  int|null  $processorUserId  判定の処理者 P(自分か、確かめた代理元・一時保存の持ち主。引戻せるノードは P で読む。d に有効でなければ null)
     * @param  array<int, int>  $holderUserIds  保留中の現在のノードの id → 最新の保留の行の処理者({@see CaseHolders})
     * @param  list<int>  $confirmNodeIds  判定を求める者(自分)が確認対象者の、開いている確認のノードの id({@see CaseConfirmations})
     */
    public function __construct(
        public WorkflowCase $case,
        public array $currentNodes,
        public array $targetNodeIds,
        public array $pullBackNodes,
        public ?int $processorUserId,
        public array $holderUserIds,
        public array $confirmNodeIds = [],
    ) {}

    /**
     * 処理者を P に替えた状況(一括承認の組を、処理者の候補ごとに同じ表で引くため = {@see BulkApprovals})。引戻せるノードは処理者で読むので持たない。
     * 承認の表が引戻しの役割を読まない前提で、一括承認の代理元の組の判定に使う(承認の行が引戻しの役割を読むようにするときは、代理元の組の状況を読み直す)。
     */
    public function withProcessor(int $processorUserId): self
    {
        return new self(
            $this->case,
            $this->currentNodes,
            $this->targetNodeIds,
            [],
            $processorUserId,
            $this->holderUserIds,
            $this->confirmNodeIds,
        );
    }

    /**
     * 判定を求める者がそのノードを引戻せるか。
     */
    public function canPullBack(int $nodeId): bool
    {
        return isset($this->pullBackNodes[$nodeId]);
    }

    /**
     * 保留中のノードの最新の保留の行の処理者(保留中でなければ null)。
     */
    public function holderOf(int $nodeId): ?int
    {
        return $this->holderUserIds[$nodeId] ?? null;
    }

    /**
     * その id の現在のノード(現在のノードでなければ null)。
     */
    public function currentNode(int $id): ?CurrentNode
    {
        foreach ($this->currentNodes as $node) {
            if ($node->id === $id) {
                return $node;
            }
        }

        return null;
    }

    /**
     * 判定を求める者がそのノードの処理対象者に当たるか。
     */
    public function isTargetOf(CurrentNode $node): bool
    {
        return in_array($node->id, $this->targetNodeIds, true);
    }

    /**
     * 案件の札の状態({@see CurrentNodes::labelStateOf()}。現在のノードが無ければ null)。
     */
    public function labelState(): ?WorkflowNodeState
    {
        return CurrentNodes::labelStateOf($this->currentNodes);
    }

    /**
     * 現在のステップの文字列(設計/ワークフロー/案件の運用/認可と一覧.md の 2.2 節の「現在のステップ」。無ければ null): 写した並びで表示名を「、」で並べ、
     * 同じ親(親のノードの id で見る)の展開の行が続く所は「{親の名前}({子の名前}、…)」に 1 つにまとめる(詳細の申請情報・ホームの節も同じ値)。
     */
    public function currentNames(): ?string
    {
        if ($this->currentNodes === []) {
            return null;
        }

        $groups = [];

        foreach ($this->currentNodes as $node) {
            $last = array_key_last($groups);

            if ($node->parentId !== null && $last !== null && $groups[$last]['parentId'] === $node->parentId) {
                $groups[$last]['names'][] = $node->childName ?? $node->name;
            } else {
                $groups[] = [
                    'parentId' => $node->parentId,
                    'parent' => $node->parentName,
                    'names' => [$node->childName ?? $node->name],
                ];
            }
        }

        return implode('、', array_map(self::groupName(...), $groups));
    }

    /**
     * まとめた 1 つの文字(展開の行でなければ表示名、展開の行は「{親の名前}({子の名前}、…)」。子が 1 つでも括弧で囲む)。
     *
     * @param  array{parentId: int|null, parent: string|null, names: non-empty-list<string>}  $group
     */
    private static function groupName(array $group): string
    {
        return $group['parent'] === null ? $group['names'][0] : $group['parent'].'('.implode('、', $group['names']).')';
    }

    /**
     * 申請のノードが現在のノードなら、それ(再申請・取止めの操作するノード)。
     */
    public function currentApplyNode(): ?CurrentNode
    {
        foreach ($this->currentNodes as $node) {
            if ($node->kind === WorkflowNodeKind::Apply) {
                return $node;
            }
        }

        return null;
    }
}
