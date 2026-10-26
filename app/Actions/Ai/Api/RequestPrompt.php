<?php

namespace App\Actions\Ai\Api;

use App\Actions\Ai\PromptKinds;
use App\Actions\Ai\RunBudget;
use App\Actions\Ai\RunRequestChecks;
use App\Enums\Ai\AiRunStatus;
use App\Jobs\Ai\RunPromptJob;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use App\Queries\Ai\AiSettings;
use App\Queries\Ai\KindSettings;
use App\Queries\Ai\RunEstimates;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * AI の実行を依頼する(docs の 設計/AI/README.md 1 節・3 節・5.2)。上限を確かめて実行(queued)と送る文を書き、
 * 同じトランザクションでジョブを入れる(実行と費用.md 4 節の「ジョブの入れ方」)。
 * LLM の応答は種類の実装({@see PromptKind::complete()})が受け取る。依頼者の権限(送るデータを読めるか・対象を更新できるか)は
 * 呼び手の入口が依頼の時点に確かめ、この口は見ない(ADR 0029。断るのは {@see RunRequestChecks})。
 *
 * Ai の公開の口(主体・認可・ロック・原子性・結果・失敗の正本 = 同 3 節の表)。自分のトランザクションを持ち、外側のトランザクションの中では
 * savepoint で外側に含まれる(ジョブの行も既定の DB 接続の表に入るので、外側のコミットまで worker から見えず、外側が巻き戻れば一緒に消える)。
 * 上限の確認と行の追加は AI の費用の勧告ロックで直列にする。
 */
class RequestPrompt
{
    public const INPUT_TOO_LONG = 'AI に送る文が長すぎます(:max 文字まで)';

    public function __construct(
        private PromptKinds $kinds,
        private KindSettings $settings,
        private RunEstimates $estimates,
        private RunBudget $budget,
        private RunRequestChecks $checks,
    ) {}

    /**
     * 断る理由が重なったときは、設計/AI/公開の口.md 3 節の「断る理由が重なったとき」の順で先の 1 つを返す(同じ入力にいつも同じ結果を返すため)。
     *
     * @return int 実行の id(ai_runs.id)
     *
     * @throws AuthorizationException ゲストのとき(403)
     * @throws LogicException 未登録・設定に無い種類、種類の失敗の受け手の中からの依頼、完了の受け手の中で依頼者か対象のレコードが
     *                        完了中の実行と違うとき(作りの誤り)
     * @throws ModelNotFoundException 対象のレコードが無いとき(404)
     * @throws RunRequestDeclined 送る文が長すぎる・上限を超えるとき(422。キー ai)
     */
    public function execute(AccessContext $context, PromptRequest $request): int
    {
        $requesterUserId = $this->checks->requesterOf($context);
        $this->kinds->of($request->kindCode);
        $kind = $this->settings->prompt($request->kindCode) ?? throw new LogicException("AI の実行の種類 {$request->kindCode} が設定(config/ai_runs.php の kinds)に無い");
        $this->checks->throwIfInFailureReceiver();
        $moduleCode = $this->checks->targetModuleCode($requesterUserId, $request->recordId);
        $chars = mb_strlen($request->systemText) + mb_strlen($request->userText);

        if ($chars > $kind['max_input_chars']) {
            throw new RunRequestDeclined(RunRequestDeclined::TOO_LONG, str_replace(':max', (string) $kind['max_input_chars'], self::INPUT_TOO_LONG));
        }

        $reserved = $this->estimates->estimate($kind['provider'], $kind['model'], $chars, $kind['max_output_tokens']);

        $attributes = [
            'kind' => $request->kindCode,
            'status' => AiRunStatus::Queued,
            'requester_user_id' => $requesterUserId,
            'selected_department_id' => $context->selectedDepartmentId,
            'visitor_id' => $context->visitorId,
            'record_id' => $request->recordId,
            'original_record_id' => $request->recordId,
            'record_module_code' => $moduleCode,
            'source_record_ids' => $request->sourceRecordIds,
            'provider' => $kind['provider'],
            'model' => $kind['model'],
            'budget' => $kind['budget'],
            'max_output_tokens' => $kind['max_output_tokens'],
            'reserved_cost_usd' => $reserved,
        ];

        return DB::transaction(function () use ($request, $kind, $attributes, $reserved): int {
            $this->budget->lock();
            $this->budget->ensureWithin($kind['budget'], $reserved);

            $run = AiRun::query()->create($attributes);
            AiRunText::query()->create(['ai_run_id' => $run->id, 'system_text' => $request->systemText, 'user_text' => $request->userText]);
            $jobTimeout = AiSettings::jobTimeout($kind['timeout_seconds']);
            RunPromptJob::dispatch($run->id, $jobTimeout);

            return $run->id;
        });
    }
}
