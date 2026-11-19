<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Api\ConversationKind;
use App\Actions\Ai\Api\ConversationStore;
use App\Actions\Ai\ConversationKinds;
use App\Actions\Ai\Core\ConversationCore;
use App\Actions\Ai\Core\LoopEnding;
use App\Actions\Ai\Core\LoopEndingKind;
use App\Actions\Ai\Core\LoopFailure;
use App\Actions\Ai\Core\LoopStop;
use App\Actions\Ai\FailRun;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\ConversationItemType;
use App\Models\Ai\AiAgentDefinition;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiToolCall;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * 会話の型のジョブの本体(順 = docs の 設計/AI/エージェントの実行/README.md 9.1 のジョブの順、状態の移り方 =
 * 設計/AI/エージェントの実行/状態の移り方.md 9.11)。接続先を待つ間は鍵もトランザクションも持たない(書き込みは受け手ごとの短いトランザクション)。
 */
final readonly class ExecuteConversation
{
    public function __construct(
        private StartConversationRun $start,
        private ConversationTitle $title,
        private PrepareConversationRun $prepare,
        private LoadConversationHistory $history,
        private LoopRequestOf $requests,
        private ConversationReceivers $receivers,
        private StepTargetsOf $targets,
        private CompleteConversationRun $complete,
        private AnswerRunStart $answers,
        private FailRun $failRun,
        private ConversationCore $core,
    ) {}

    /**
     * @throws Throwable 失敗で終えたとき(実行は失敗に書いた後。ジョブの失敗の扱いは失敗を書き直さない)
     */
    public function execute(int $runId): void
    {
        $started = $this->start->execute($runId);

        if ($started === null) {
            return;
        }

        [$run, $kind] = $started;

        if ($kind === null) {
            $notRegistered = new LogicException(ConversationKinds::notRegistered($run->kind));
            $this->failRun->execute($runId, AiRunErrorCode::Completion, $notRegistered);

            throw $notRegistered;
        }

        $this->run($run, $kind);
    }

    /**
     * 2〜6。ほかの経路が先に実行を終えていたら(S24)何もしない。
     *
     * @throws Throwable
     */
    private function run(AiRun $run, ConversationKind $kind): void
    {
        $title = null;

        try {
            $ending = $this->proceed($run, $kind, $title);

            if ($ending->kind !== LoopEndingKind::Failed) {
                $this->end($run, $kind, $ending, $title);

                return;
            }
        } catch (RunNoLongerRunning) {
            return;
        } catch (AnswerNotConfirmed $notConfirmed) {
            $this->failRun->execute($run->id, AiRunErrorCode::Confirmation, $notConfirmed, null, $title);

            throw $notConfirmed;
        } catch (Throwable $exception) {
            $this->failRun->execute($run->id, AiRunErrorCode::Completion, $exception, null, $title);

            throw $exception;
        }

        $failure = new RuntimeException("会話の実行 {$run->id} がループの失敗で終わった({$ending->failure?->value})");
        $this->failRun->execute($run->id, self::errorCodeOf($ending->failure), $failure, null, $title);

        throw $failure;
    }

    /**
     * 2〜5。題を作れたら $title に入れる(後の失敗でも失敗の受け手に渡すため)。
     *
     * @param-out string|null $title
     */
    private function proceed(AiRun $run, ConversationKind $kind, ?string &$title): LoopEnding
    {
        $store = $kind->store();
        $items = $store->read($run->id);
        $definition = AiAgentDefinition::query()->findOrFail($run->agent_definition_id);
        $targets = $this->targets->targets($run);
        $title = $this->titleOf($run, $targets, $store, $definition, $items);
        $granted = $this->prepare->execute($run, $definition);

        if ($items === null) {
            return LoopEnding::stopped(LoopStop::User);
        }

        $awaiting = $run->answer === null ? [] : $this->answers->awaiting($run);
        $callIds = array_map(fn (AiToolCall $row): string => $row->call_id, $awaiting);
        [$history, $added] = $this->history->execute($run, $items, $callIds);
        $answered = $run->answer === null
            ? [null, []]
            : $this->answers->start($run, $store, $definition, $awaiting, $history);

        if ($answered === null) {
            return LoopEnding::stopped(LoopStop::User);
        }

        [$answer, $preRunCalls] = $answered;
        $instructions = (string) $definition->instructions;
        $receivers = $this->receivers->loop($run, $targets, $store, $instructions, $granted, $answer);
        $target = $targets->conversation;
        $request = $this->requests->of($run, $target, $history, $granted, $added, $receivers, $preRunCalls);

        return $this->core->loop($request)->ending;
    }

    /**
     * 依頼が題を求め、実行に結ばれた最後の項目が利用者の文のときだけ題のステップを通す(9.10)。
     *
     * @param  list<ConversationItem>|null  $items
     */
    private function titleOf(
        AiRun $run,
        StepTargets $targets,
        ConversationStore $store,
        AiAgentDefinition $definition,
        ?array $items,
    ): ?string {
        $last = $items === null || $items === [] ? null : $items[array_key_last($items)];

        if ($run->wants_title !== true || $last?->type !== ConversationItemType::UserText) {
            return null;
        }

        $receivers = $this->receivers->send($run, $targets, $store, (string) $definition->instructions);

        return $this->title->make($this->core, $run, $definition, $last, $targets->title, $receivers);
    }

    /** 6。ほかの経路が先に終えていたら報告だけする(書いた分は戻さない = 9.1) */
    private function end(AiRun $run, ConversationKind $kind, LoopEnding $ending, ?string $title): void
    {
        if (! $this->complete->execute($run->id, $kind, $ending, $title)) {
            report(new LogicException("会話の実行 {$run->id} は終わりを書く前にほかの経路で終わっていた"));
        }
    }

    private static function errorCodeOf(?LoopFailure $failure): AiRunErrorCode
    {
        return match ($failure) {
            LoopFailure::ContinuationsExhausted, LoopFailure::Refused => AiRunErrorCode::Incomplete,
            LoopFailure::Timeout => AiRunErrorCode::Timeout,
            LoopFailure::Provider => AiRunErrorCode::Provider,
            null => throw new LogicException('失敗の終わり方に失敗の種類が無い'),
        };
    }
}
