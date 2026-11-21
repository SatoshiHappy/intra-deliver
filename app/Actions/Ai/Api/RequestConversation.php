<?php

namespace App\Actions\Ai\Api;

use App\Actions\Ai\Conversation\ConversationRequestChecks;
use App\Actions\Ai\ConversationKinds;
use App\Actions\Ai\RunBudget;
use App\Actions\Ai\RunRequestChecks;
use App\Enums\Ai\AiConversationAnswer;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiKindType;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Ai\AiToolCallAnswer;
use App\Enums\Ai\AiToolCallStatus;
use App\Jobs\Ai\RunConversationJob;
use App\Models\Ai\AiAgentDefinition;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use App\Models\Ai\AiToolCall;
use App\Queries\Ai\AiSettings;
use App\Queries\Ai\ConversationSettings;
use App\Queries\Ai\KindSettings;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 会話の依頼の 2 つの口(文を送る・確認に答える。docs の 設計/AI/エージェントの実行/口と契約.md 9.12、表 = 設計/AI/公開の口.md 3 節)。
 * 呼び手のトランザクションの中で呼ばれ、実行の記録とジョブを書き、呼び手が会話の保存に足す項目の列を返す(ジョブの行も同じトランザクションに入るので、
 * 呼び手のコミットまで worker に取られない)。断る理由が重なったときは公開の口の 3 節の順で先の 1 つを返す。
 *
 * @phpstan-import-type ConversationKindSettings from KindSettings
 */
class RequestConversation
{
    public const INPUT_TOO_LONG = 'AI に送る文が長すぎます(:max 文字まで)';

    public function __construct(
        private ConversationKinds $kinds,
        private KindSettings $settings,
        private ConversationSettings $conversation,
        private RunBudget $budget,
        private RunRequestChecks $requests,
        private ConversationRequestChecks $checks,
    ) {}

    /**
     * 文を送る。前の実行が確認待ちなら、その文は承認待ちへの理由つきの断りになる(9.3 の H3)。
     *
     * @throws AuthorizationException ゲストのとき(403)
     * @throws LogicException 作りの誤り(種類・受け手の中・定義・前の実行の形)
     * @throws RunRequestDeclined 前の実行の状態・定義・文の長さ・上限で断るとき(422。キー ai)
     */
    public function send(AccessContext $context, ConversationTextRequest $request): ConversationRequested
    {
        $shaped = $this->shape($context, $request->kindCode, $request->definitionId, $request->previousRunId);

        return DB::transaction(function () use ($context, $request, $shaped): ConversationRequested {
            [$requester, $kind, $definition, $tooLarge] = $shaped;
            $this->budget->lock();
            $previousId = $request->previousRunId;
            $previous = $previousId === null ? null : $this->checks->previous($previousId, false);
            $this->checks->throwIfTooLarge($tooLarge);

            if (mb_strlen($request->text) > $kind['max_input_chars']) {
                $message = str_replace(':max', (string) $kind['max_input_chars'], self::INPUT_TOO_LONG);

                throw new RunRequestDeclined(RunRequestDeclined::TOO_LONG, $message);
            }

            $this->budget->ensureWithin($kind['budget'], '0');
            $run = $this->created($context, $request->kindCode, $kind, $requester, $definition, [
                'previous_run_id' => $request->previousRunId,
                'wants_title' => $request->wantsTitle,
            ]);
            AiRunText::query()->create(['ai_run_id' => $run->id, 'user_text' => $request->text]);

            return new ConversationRequested($run->id, $previous?->status === AiRunStatus::AwaitingConfirmation
                ? $this->rejectedWithText($previous, $run, $request->text)
                : [ConversationItem::userText($request->text)]);
        });
    }

    /**
     * 確認に答える(費用では断らない = 9.12 の「断るとき」)。
     *
     * @throws AuthorizationException ゲストのとき(403)
     * @throws LogicException 作りの誤り
     * @throws RunRequestDeclined 前の実行の状態・定義で断るとき(422)
     */
    public function answer(AccessContext $context, ConversationAnswerRequest $request): ConversationRequested
    {
        $shaped = $this->shape($context, $request->kindCode, $request->definitionId, $request->previousRunId);

        return DB::transaction(function () use ($context, $request, $shaped): ConversationRequested {
            [$requester, $kind, $definition, $tooLarge] = $shaped;
            $this->budget->lock();
            $this->checks->previous($request->previousRunId, true);
            $this->checks->throwIfTooLarge($tooLarge);
            $run = $this->created($context, $request->kindCode, $kind, $requester, $definition, [
                'previous_run_id' => $request->previousRunId,
                'wants_title' => false,
                'answer' => $request->answer,
            ]);

            return new ConversationRequested($run->id, []);
        });
    }

    /**
     * 鍵の前の確かめと、定義の大きさの測り(大きさは前の実行の行の鍵に依らないので、全員の依頼を直列にする鍵の前に測る。受けるか断るかは
     * 測った時の定義と業務モジュールで決まり、受けた後に変わって上限を超えたら実行の始めの確かめが失敗にする)。
     *
     * @return array{int, ConversationKindSettings, AiAgentDefinition, bool} 依頼者・種類の設定・定義・大きさが上限を超えるか
     */
    private function shape(AccessContext $context, string $kindCode, int $definitionId, ?int $previousRunId): array
    {
        $requester = $this->requests->requesterOf($context);
        $kind = $this->settings->conversation($kindCode)
            ?? throw new LogicException("会話の型の種類 {$kindCode} が設定(config/ai_runs.php の kinds)に無い");
        $this->kinds->of($kindCode);
        $this->requests->throwIfInFailureReceiver();

        $definition = $this->checks->shape($requester, $definitionId, $previousRunId);

        return [$requester, $kind, $definition, $this->checks->tooLarge($definition)];
    }

    /**
     * 実行の記録(待ち)とジョブ。前の実行の一意の制約に当たったら B13 と同じ 422(保存点で戻すので、呼び手のトランザクションは使える状態のまま)。
     *
     * @param  ConversationKindSettings  $kind
     * @param  array{previous_run_id: int|null, wants_title: bool, answer?: AiConversationAnswer}  $chain
     */
    private function created(
        AccessContext $context,
        string $kindCode,
        array $kind,
        int $requester,
        AiAgentDefinition $definition,
        array $chain,
    ): AiRun {
        try {
            $run = DB::transaction(fn (): AiRun => AiRun::query()->create([
                'kind' => $kindCode,
                'kind_type' => AiKindType::Conversation,
                'status' => AiRunStatus::Queued,
                'requester_user_id' => $requester,
                'selected_department_id' => $context->selectedDepartmentId,
                'visitor_id' => $context->visitorId,
                'agent_definition_id' => $definition->id,
                'budget' => $kind['budget'],
                ...$chain,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw new RunRequestDeclined(RunRequestDeclined::CONTINUED, ConversationRequestChecks::CONTINUED);
        }

        $timeout = AiSettings::conversationJobTimeout(
            $kind['run_timeout_seconds'],
            $kind['purposes'][ConversationSettings::CONVERSATION]['timeout_seconds'],
        );
        RunConversationJob::dispatch($run->id, $timeout)->onConnection($this->conversation->queueConnection());

        return $run;
    }

    /**
     * 前の実行の承認待ちへの理由つきの断り(形 = docs の 設計/AI/エージェントの実行/README.md 9.3 の H3)。
     *
     * @return list<ConversationItem>
     */
    private function rejectedWithText(AiRun $previous, AiRun $run, string $text): array
    {
        $rows = AiToolCall::query()
            ->where('ai_run_id', $previous->id)
            ->where('status', AiToolCallStatus::AwaitingConfirmation)
            ->orderBy('ai_run_step_id')
            ->orderBy('position')
            ->get();
        AiToolCall::query()->whereKey($rows->modelKeys())->update([
            'answer' => AiToolCallAnswer::NextText,
            'answer_run_id' => $run->id,
            'status' => AiToolCallStatus::Rejected,
            'finished_at' => now(),
        ]);

        $withReason = [AiInstructionTemplate::ToolUseRejectedWithReason, [ConversationItem::USER_FEEDBACK => $text]];
        $plain = [AiInstructionTemplate::ToolUseRejected, []];

        $item = function (AiToolCall $row, int $index) use ($withReason, $plain): ConversationItem {
            [$template, $values] = $index === 0 ? $withReason : $plain;

            return ConversationItem::toolResult($row->call_id, $template, $values, null, true);
        };

        return array_values($rows->map($item)->all());
    }
}
