<?php

namespace App\Actions\Ai\Api;

use App\Actions\Ai\PromptKinds;
use App\Actions\Ai\RunBudget;
use App\Enums\Ai\AiRunStatus;
use App\Jobs\Ai\RunPromptJob;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use App\Queries\Ai\AiSettings;
use App\Queries\Ai\RunEstimates;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * AI の実行を依頼する(docs の 設計/AI/README.md 1 節・3 節・5.2)。上限を確かめて実行(queued)と送る文を書き、コミットの後にジョブを投入する。
 * LLM の応答は種類の実装({@see PromptKind::complete()})が受け取る。送るデータを読む権限の判定は呼び手(依頼者の Requester で読む)。
 *
 * Ai の公開の口(主体・認可・ロック・原子性・結果・失敗の正本 = 同 3 節の表)。自分のトランザクションを持ち、外側のトランザクションの中では
 * savepoint で外側に含まれる(ジョブは外側のコミットの後に出る。外側が巻き戻れば出ない)。上限の確認と行の追加は AI の費用の勧告ロックで直列にする。
 */
class RequestPrompt
{
    public const GUEST = 'ゲストは AI に依頼できません';

    public const INPUT_TOO_LONG = 'AI に送る文が長すぎます(:max 文字まで)';

    public function __construct(
        private PromptKinds $kinds,
        private AiSettings $settings,
        private RunEstimates $estimates,
        private RunBudget $budget,
        private RecordDisplayQuery $records,
    ) {}

    /**
     * @return int 実行の id(ai_runs.id)
     *
     * @throws AuthorizationException ゲストのとき(403)
     * @throws LogicException 未登録・設定に無い種類、無い対象のレコードのとき(作りの誤り)
     * @throws RunRequestRejected 送る文が長すぎる・上限を超えるとき(422。キー ai)
     */
    public function execute(AccessContext $context, PromptRequest $request): int
    {
        $requesterUserId = $context->userId ?? throw new AuthorizationException(self::GUEST);
        $this->kinds->of($request->kindCode);
        $kind = $this->settings->kind($request->kindCode) ?? throw new LogicException("AI の実行の種類 {$request->kindCode} が設定(config/ai_runs.php の kinds)に無い");
        $chars = mb_strlen($request->systemText) + mb_strlen($request->userText);

        if ($chars > $kind['max_input_chars']) {
            throw new RunRequestRejected(RunRequestRejected::TOO_LONG, str_replace(':max', (string) $kind['max_input_chars'], self::INPUT_TOO_LONG));
        }

        $moduleCode = $request->recordId === null ? null : ($this->records->topLevelModuleCodeOf($request->recordId) ?? throw new LogicException("AI の実行の対象のレコード {$request->recordId} が無い"));
        $reserved = $this->estimates->estimate($kind['provider'], $kind['model'], $chars, $kind['max_output_tokens']);

        $attributes = [
            'kind' => $request->kindCode,
            'status' => AiRunStatus::Queued,
            'requester_user_id' => $requesterUserId,
            'selected_department_id' => $context->selectedDepartmentId,
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
            RunPromptJob::dispatch($run->id, $jobTimeout)->afterCommit();

            return $run->id;
        });
    }
}
