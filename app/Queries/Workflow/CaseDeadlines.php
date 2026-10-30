<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCaseNode;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * 到達日時・期限日・経過日数の計算の 1 か所(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 1.4 節)。日付は Asia/Tokyo の暦日
 * (Y-m-d の文字列)。今日は呼び手が渡す基準日(日付だけを使う)。問い合わせをしない(期限超過の絞り込みは、呼び手の問い合わせに条件を足すだけ)。
 *
 * @phpstan-type Deadline array{reachedOn: string|null, elapsedDays: int|null, dueOn: string|null, overdue: bool}
 */
class CaseDeadlines
{
    private const DATE_FORMAT = 'Y-m-d';

    /**
     * 到達した日(Y-m-d)。
     */
    public function reachedOn(DateTimeInterface $reachedAt): string
    {
        return CarbonImmutable::instance($reachedAt)->setTimezone(AccessContext::TIMEZONE)->format(self::DATE_FORMAT);
    }

    /**
     * 期限日(Y-m-d。期限の日数が無ければ null)。
     */
    public function dueOn(DateTimeInterface $reachedAt, ?int $deadlineDays): ?string
    {
        if ($deadlineDays === null) {
            return null;
        }

        return self::day($this->reachedOn($reachedAt))->addDays($deadlineDays)->format(self::DATE_FORMAT);
    }

    /**
     * 経過日数。
     */
    public function elapsedDays(DateTimeInterface $reachedAt, DateTimeInterface $today): int
    {
        return (int) self::day($this->reachedOn($reachedAt))->diffInDays(self::day($today->format(self::DATE_FORMAT)));
    }

    /**
     * 期限を超過したか。
     */
    public function isOverdue(?string $dueOn, DateTimeInterface $today): bool
    {
        return $dueOn !== null && $today->format(self::DATE_FORMAT) > $dueOn;
    }

    /**
     * 期限を超過した現在のノードに絞る({@see self::dueOn()}・{@see self::isOverdue()} と同じ式の SQL。期限日 = 到達した日 + 期限の日数、
     * 今日 > 期限日で超過)。申請操作の一覧の「期限超過だけ」が使う(設計/ワークフロー/案件の運用/認可と一覧.md の 2.3 節)。
     *
     * @param  Builder<WorkflowCaseNode>  $nodes
     * @return Builder<WorkflowCaseNode>
     */
    public function whereOverdue(Builder $nodes, DateTimeInterface $today): Builder
    {
        $bindings = [AccessContext::TIMEZONE, $today->format(self::DATE_FORMAT)];

        return $nodes->whereIn('workflow_case_nodes.state', WorkflowNodeState::current())
            ->whereNotNull('workflow_case_nodes.deadline_days')
            ->whereRaw(
                '(workflow_case_nodes.reached_at at time zone ?)::date + workflow_case_nodes.deadline_days < ?::date',
                $bindings,
            );
    }

    /**
     * 案件ごとの経過日数・期限日・超過(渡すノードの集まりは呼び手が選ぶ)。
     *
     * @param  list<CurrentNode>  $nodes
     */
    public function summaryOf(array $nodes, DateTimeInterface $today): CaseDeadline
    {
        if ($nodes === []) {
            return new CaseDeadline(null, null, false);
        }

        $elapsed = max(array_map(fn (CurrentNode $node) => $this->elapsedDays($node->reachedAt, $today), $nodes));
        $dues = array_values(array_filter(array_map(fn (CurrentNode $node) => $node->dueOn, $nodes), 'is_string'));
        $earliest = $dues === [] ? null : min($dues);

        return new CaseDeadline($elapsed, $earliest, $this->isOverdue($earliest, $today));
    }

    /**
     * 画面の期限の表示(設計/ワークフロー/案件の運用/画面/README.md の 1.2 節の WorkflowDeadline)。到達日 = 集まりで最も早い到達した日
     * (経過日数 = 最も長い と同じノード)。一覧の行と詳細の基本情報が同じ形を使う。
     *
     * @param  list<CurrentNode>  $nodes  対象のノードの集まり(呼び手が選ぶ)
     * @return Deadline
     */
    public function viewOf(array $nodes, DateTimeInterface $today): array
    {
        $summary = $this->summaryOf($nodes, $today);

        return [
            'reachedOn' => $this->earliestReachedOn($nodes),
            'elapsedDays' => $summary->elapsedDays,
            'dueOn' => $summary->dueOn,
            'overdue' => $summary->isOverdue,
        ];
    }

    /**
     * 未処理の集まり(現在のノードのうち、判定を求める者が処理対象者に当たるもの = 1.4 節の対象のノードの集まり)。一覧の未処理の行と
     * ホームの未処理の表・件数カードの期限超過が同じ集まりを使う。
     *
     * @param  list<CurrentNode>  $nodes  1 つの案件の現在のノード
     * @param  list<int>  $targetNodeIds  判定を求める者が処理対象者に当たるノードの id
     * @return list<CurrentNode>
     */
    public function pendingNodesOf(array $nodes, array $targetNodeIds): array
    {
        return array_values(array_filter($nodes, fn (CurrentNode $node) => in_array($node->id, $targetNodeIds, true)));
    }

    /**
     * ノードの集まりで最も早い到達した日(Y-m-d。空なら null)。
     *
     * @param  list<CurrentNode>  $nodes
     */
    public function earliestReachedOn(array $nodes): ?string
    {
        $days = array_map(fn (CurrentNode $node) => $this->reachedOn($node->reachedAt), $nodes);

        return $days === [] ? null : min($days);
    }

    /**
     * 暦日の比べ方をタイムゾーンに依らせないため、日付を UTC の 0 時として作る。
     *
     * @throws LogicException 暦日として読めないとき(この class が作った値だけを渡すので起きない)
     */
    private static function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!'.self::DATE_FORMAT, $date, 'UTC')
            ?? throw new LogicException("暦日として読めない: {$date}");
    }
}
