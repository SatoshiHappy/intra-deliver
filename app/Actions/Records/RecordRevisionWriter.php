<?php

namespace App\Actions\Records;

use App\Actions\Records\Api\RecordRevisionOrigin;
use App\Enums\Records\RecordRevisionAction;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use App\Queries\Records\RecordDefinitionQuery;
use App\Queries\Records\RecordQuery;
use App\Queries\Records\RecordRevisionComparison;
use App\Queries\Records\RecordRevisionLabels;
use App\Queries\Records\RecordSnapshots;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

/**
 * 更新履歴の版を書く 1 か所(docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 1〜3 節)。書き込みの口が、業務処理の鍵
 * (業務モジュールの共有 → 行)の中で値と明細を書き終えた後に、同じトランザクションで呼ぶ。採番は行の鍵で並ぶので競合しない
 * (一意の違反は呼び手の誤りとして上げる)。
 *
 * 操作した人と代理元の向き = 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.1 節の「代理の列の向き」。
 *
 * @phpstan-import-type RevisionCopy from RecordRevisionComparison
 */
class RecordRevisionWriter
{
    public function __construct(
        private RecordQuery $records,
        private RecordDefinitionQuery $definitions,
        private RecordSnapshots $snapshots,
        private RecordRevisionLabels $labels,
        private RecordRevisionComparison $comparison,
    ) {}

    /**
     * 契機の実行者が書き込みの口の AccessContext の利用者と同じなら止める(本人の実行は null で表す = 呼び手の誤り。
     * 更新履歴.md の 3 節)。入力だけで決まるので、書き込みの口が鍵と書き込みの前に呼ぶ 1 か所。
     *
     * @param  int|null  $userId  書き込みの口に渡した AccessContext の利用者(ゲストなら null)
     *
     * @throws InvalidArgumentException 契機の実行者が利用者と同じとき
     */
    public function ensureExecutorIsNotUser(?RecordRevisionOrigin $origin, ?int $userId): void
    {
        $executorUserId = $origin?->executorUserId;

        if ($executorUserId !== null && $executorUserId === $userId) {
            throw new InvalidArgumentException("契機の実行者が AccessContext の利用者と同じ(本人の実行は null): {$executorUserId}");
        }
    }

    /**
     * 呼び手が {@see self::ensureExecutorIsNotUser()} を先に呼んでおく。
     *
     * @param  int|null  $userId  書き込みの口に渡した AccessContext の利用者(ゲストなら null)
     * @param  RecordRevisionOrigin|null  $origin  契機(null = 業務の画面の操作)
     * @return int|null 書いた版の id(契機が無く、最新の版との差分が空なので作らなかったときは null)
     *
     * @throws ModelNotFoundException<Record|RecordModule> レコードが無い・明細の行のとき
     */
    public function write(
        int $recordId,
        RecordRevisionAction $action,
        ?int $userId,
        ?RecordRevisionOrigin $origin,
    ): ?int {
        $record = $this->records->topLevel($recordId);
        $module = $this->definitions->load(RecordModule::query()->findOrFail($record->record_module_id));
        $snapshot = $this->snapshots->snapshot($record, $module);
        $copy = ['snapshot' => $snapshot, 'labels' => $this->labels->of($module, $snapshot)];
        $latest = RecordRevision::query()->where('record_id', $record->id)->orderByDesc('revision')->first();

        if ($origin === null && $latest !== null && $this->isUnchanged($latest, $copy)) {
            return null;
        }

        $executorUserId = $origin?->executorUserId;

        return RecordRevision::query()->create([
            'record_module_id' => $record->record_module_id,
            'record_id' => $record->id,
            'revision' => ($latest->revision ?? 0) + 1,
            'action' => $action,
            ...$copy,
            'user_id' => $executorUserId ?? $userId,
            'principal_user_id' => $executorUserId === null ? null : $userId,
            'origin_operation' => $origin?->operation,
            'origin_node_name' => $origin?->nodeName,
        ])->id;
    }

    /**
     * 最新の版と、書いた後の写しの差分(更新履歴.md の 5.2 節の規則)が空か。
     *
     * @param  RevisionCopy  $copy
     */
    private function isUnchanged(RecordRevision $latest, array $copy): bool
    {
        $latestCopy = ['snapshot' => $latest->snapshot ?? [], 'labels' => $latest->labels ?? []];

        return $this->comparison->isEmpty($this->comparison->compare($latestCopy, $copy));
    }
}
