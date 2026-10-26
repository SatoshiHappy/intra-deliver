<?php

namespace App\Actions\Ai\Api;

use App\Actions\Ai\CompletingRun;
use App\Actions\Ai\RunRequesterContext;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Queries\Records\Api\RecordShowUnchecked;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

/**
 * 実行の対象のレコードを読む口(完了の受け手が AI に渡す材料を読む 1 か所 = ADR 0029 の決定 5。口の正本 = docs の 設計/AI/公開の口.md 3.3)。
 * 依頼者を判定しない(依頼の時点に呼び手の入口が依頼者の閲覧を確かめた)ので、入れ物の判定しない読み取り {@see RecordShowUnchecked} を
 * 依頼者の文脈の基準日とロケールで呼ぶ。完了のトランザクションの中で呼ばれ、鍵を取らない。
 * `Queries` に置かないのは、完了中の実行({@see CompletingRun})を照らし合わせるため(書き込みの口と同じ名前空間)。
 *
 * @phpstan-import-type ShownRecord from RecordShowUnchecked
 */
class ReadRecordForRun
{
    public function __construct(
        private CompletingRun $completing,
        private RecordDisplayQuery $records,
        private RunRequesterContext $requesters,
        private RecordShowUnchecked $show,
    ) {}

    /**
     * 完了中の実行の対象のレコードの値(入れ物の詳細と同じ形。他モジュール参照の表示名は null = 画面の #<id>)。
     *
     * @return ShownRecord|null 対象のレコードが無い(依頼の後に消された)= null
     *
     * @throws LogicException 完了の受け手の外・実行が処理中でない・対象を持たない実行のとき(作りの誤り)
     * @throws ModelNotFoundException 対象が明細の行のとき・存在を確かめた後に消されたとき(404。入れ物の判定しない読み取りのまま)
     */
    public function execute(int $runId): ?array
    {
        if (! $this->completing->is($runId)) {
            throw new LogicException("AI の読む口は実行 {$runId} の完了の受け手の中からだけ呼べる");
        }

        $run = AiRun::query()->findOrFail($runId);

        if ($run->status !== AiRunStatus::Running) {
            throw new LogicException("AI の実行 {$runId} は処理中でない({$run->status->value})");
        }

        if ($run->original_record_id === null) {
            throw new LogicException("AI の実行 {$runId} は対象のレコードを持たない");
        }

        $recordId = $run->record_id;

        if ($recordId === null || $this->records->topLevelModuleCodeOf($recordId) === null) {
            return null;
        }

        $context = $this->requesters->of($run);

        return $this->show->show($recordId, $context->date, $context->localeCode);
    }
}
