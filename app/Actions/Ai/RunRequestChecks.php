<?php

namespace App\Actions\Ai;

use App\Actions\Ai\Api\RequestPrompt;
use App\Actions\Ai\Api\RequestTranscription;
use App\Actions\Ai\Api\RunRequestMessages;
use App\Models\Ai\AiRun;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

/**
 * 2 つの依頼の口({@see RequestPrompt}・{@see RequestTranscription})が共通に断る理由(内部。docs の 設計/AI/公開の口.md 3 節の
 * 「依頼の時点の確かめ」の表 B1〜B7)。依頼者の権限は見ない(呼び手の入口が依頼の時点に確かめる = ADR 0029)。
 * 呼び手の口が、断る理由の順(同じ 3 節の「断る理由が重なったとき」)に合わせて呼ぶ。
 */
class RunRequestChecks
{
    public function __construct(
        private CompletingRun $completing,
        private FailingRun $failing,
        private RecordDisplayQuery $records,
    ) {}

    /**
     * 依頼者(B1)。
     *
     * @throws AuthorizationException ゲスト(利用者の無い文脈)のとき(403)
     */
    public function requesterOf(AccessContext $context): int
    {
        return $context->userId ?? throw new AuthorizationException(RunRequestMessages::GUEST);
    }

    /**
     * 種類の失敗の受け手の中からの依頼を断る(B7)。
     *
     * @throws LogicException 失敗を書いている実行があるとき(作りの誤り)
     */
    public function throwIfInFailureReceiver(): void
    {
        if ($this->failing->any()) {
            throw new LogicException('種類の失敗の受け手の中から AI に依頼できない');
        }
    }

    /**
     * 完了中の実行との違い(B4)と、対象のレコードの有無(B2・B5)を確かめ、対象の業務モジュールのコードを返す。
     *
     * @param  int  $requesterUserId  {@see self::requesterOf()} の値
     * @param  int|null  $recordId  対象のレコード(指さなければ null)
     * @return ($recordId is null ? null : string) 対象のレコードの業務モジュールのコード(明細の行なら親の業務モジュール。
     *                                             指さなければ null)
     *
     * @throws LogicException 完了の受け手の中で、依頼者か対象のレコードが完了中の実行と違うとき(作りの誤り)
     * @throws ModelNotFoundException 対象のレコードを指したが無いとき(404。呼び手が確かめた後に消された)
     */
    public function targetModuleCode(int $requesterUserId, ?int $recordId): ?string
    {
        $completingId = $this->completing->runId();

        if ($completingId !== null) {
            $completing = AiRun::query()->findOrFail($completingId);

            if ($completing->requester_user_id !== $requesterUserId || $completing->original_record_id !== $recordId) {
                throw new LogicException("完了中の実行 {$completingId} と、依頼者か対象のレコードが違う依頼");
            }
        }

        if ($recordId === null) {
            return null;
        }

        return $this->records->topLevelModuleCodeOf($recordId)
            ?? throw new ModelNotFoundException("AI の実行の対象のレコード {$recordId} が無い");
    }
}
