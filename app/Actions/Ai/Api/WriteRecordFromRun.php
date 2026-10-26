<?php

namespace App\Actions\Ai\Api;

use App\Actions\Ai\CompletingRun;
use App\Actions\Ai\PromptKinds;
use App\Actions\Ai\RunRequesterContext;
use App\Actions\Ai\TranscriptionKinds;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\RecordRevisionOrigin;
use App\Actions\Records\Api\UpdateRecordUnchecked;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use App\Queries\Records\Api\RecordDisplayQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * AI の書き込みの口(AI の出力をレコードに書く 1 か所 = ADR 0019。口の正本 = docs の 設計/AI/公開の口.md 3.2)。
 * 種類の完了の受け手の中からだけ、実行の対象のレコードを、種類が固定で持つ業務モジュールの範囲で更新する。
 * 依頼者を判定しない(依頼の時点に呼び手の入口が確かめた = ADR 0029)ので、入れ物の判定しない更新 {@see UpdateRecordUnchecked} を
 * 依頼者の文脈で呼ぶ(持ち主の鍵と入れ物の検証は通る)。開いたときの版は渡さない(処理中に人が直しても上書きする)。
 * 完了のトランザクションの中で呼ばれ、実行の行には鍵を取らない(実行の行の鍵は完了の最後に取る = 実行と費用.md 4 節)。
 * 版の契機に処理の種別を載せ、人の編集と AI の上書きを更新履歴で見分けられるようにする
 * (設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 1 節)。
 */
class WriteRecordFromRun
{
    public function __construct(
        private CompletingRun $completing,
        private PromptKinds $kinds,
        private TranscriptionKinds $transcriptionKinds,
        private RecordDisplayQuery $records,
        private RunRequesterContext $requesters,
        private UpdateRecordUnchecked $updateRecord,
    ) {}

    /**
     * 1 回の完了で 2 回以上呼ばない(書いたときは値が変わらなくても更新履歴の版が 1 つ増えるため = 設計/AI/公開の口.md 3.2 の「書くもの」)。
     *
     * @param  RecordInput  $input  書く欄だけ(入力に無い欄は今のまま。受けない入力 = {@see UpdateRecordUnchecked})
     * @param  string  $operation  処理の種別の語(例「AI(議事録の作成)」。版の契機の処理の種別になり、契機のステップ名と実行者は無し)
     * @return bool 書いた = true。対象のレコードが無い(依頼の後に消された)= false で、何も書かない
     *
     * @throws InvalidArgumentException 処理の種別の語が空か長いとき({@see RecordRevisionOrigin})
     * @throws LogicException 完了の受け手の外・実行が処理中でない・種類が書いてよい業務モジュールが実行の対象と合わないとき、
     *                        判定しない更新が受けない入力のとき
     * @throws ModelNotFoundException 確かめた後に消されたとき(404)
     * @throws ValidationException 持ち主の鍵があるとき・入れ物の検証に失敗したとき(422)
     */
    public function execute(int $runId, RecordInput $input, string $operation): bool
    {
        $origin = new RecordRevisionOrigin($operation);
        [$run, $moduleCode] = $this->callableRun($runId);
        $recordId = $run->record_id;

        if ($recordId === null || $this->records->topLevelModuleCodeOf($recordId) === null) {
            return false;
        }

        $this->updateRecord->execute($this->requesters->of($run), $moduleCode, $recordId, $input, $origin);

        return true;
    }

    /**
     * 呼べる時(設計/AI/公開の口.md 3.2 の表)を確かめた実行と、書く業務モジュールのコード。
     *
     * @return array{AiRun, string}
     *
     * @throws LogicException 呼べる時に合わないとき
     */
    private function callableRun(int $runId): array
    {
        if (! $this->completing->is($runId)) {
            throw new LogicException("AI の書き込みの口は実行 {$runId} の完了の受け手の中からだけ呼べる");
        }

        $run = AiRun::query()->findOrFail($runId);

        if ($run->status !== AiRunStatus::Running) {
            throw new LogicException("AI の実行 {$runId} は処理中でない({$run->status->value})");
        }

        $kind = $this->kinds->find($run->kind) ?? $this->transcriptionKinds->of($run->kind);
        $writesTo = $kind->writesTo();

        if ($writesTo === null || $writesTo !== $run->record_module_code) {
            throw new LogicException("AI の実行の種類 {$run->kind} は実行の対象の業務モジュール {$run->record_module_code} に書けない");
        }

        return [$run, $writesTo];
    }
}
