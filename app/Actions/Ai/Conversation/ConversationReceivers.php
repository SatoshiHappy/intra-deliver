<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Api\ConversationStore;
use App\Actions\Ai\Core\LoopReceivers;
use App\Actions\Ai\Core\SendReceivers;
use App\Actions\Ai\Instructions\InstructionBuilder;
use App\Actions\Ai\RunCosts;
use App\Actions\Ai\ToolCalls\GrantedTools;
use App\Actions\Ai\ToolCalls\ToolCallRows;
use App\Actions\Ai\ToolCalls\ToolCallStages;
use App\Actions\Ai\ToolCalls\ToolCallWrites;
use App\Models\Ai\AiRun;
use App\Queries\Ai\ConversationSettings;
use App\Queries\Ai\KindSettings;
use App\Queries\Ai\RunChainCalls;
use App\Queries\Ai\RunEstimates;
use DateTimeImmutable;
use LogicException;

/**
 * 1 つの実行の受け手を組む(受け手の一覧 = docs の 設計/AI/会話の中核の口と受け手.md 13.3)。ステップの使用量と途中の文は実行ごとに持つので、
 * 組むたびに新しく作る。
 */
final readonly class ConversationReceivers
{
    public function __construct(
        private SendCheck $sendCheck,
        private RunChainCalls $chainCalls,
        private RunEstimates $estimates,
        private RunCosts $costs,
        private ToolCallRows $rows,
        private ToolCallStages $stages,
        private ToolCallWrites $writes,
        private InstructionBuilder $builder,
        private ConversationSettings $settings,
        private KindSettings $kinds,
    ) {}

    /**
     * @param  StepTargets  $targets  実行の送る先(実行ごとに 1 回だけ組む)
     * @param  string  $instructions  エージェントの定義の指示文
     * @param  AnswerCalls|null  $answer  「実行」と答えた答えの実行の承認した件
     *
     * @throws LogicException 実行が始まっていない・種類の設定が無いとき(作りの誤り)
     */
    public function loop(
        AiRun $run,
        StepTargets $targets,
        ConversationStore $store,
        string $instructions,
        GrantedTools $granted,
        ?AnswerCalls $answer = null,
    ): LoopReceivers {
        $startedAt = $run->started_at ?? throw new LogicException("実行 {$run->id} が始まっていない");
        $kind = $this->kinds->conversation($run->kind) ?? throw new LogicException("会話の種類 {$run->kind} の設定が無い");
        $clock = new RunClock(DateTimeImmutable::createFromInterface($startedAt), $kind['run_timeout_seconds']);
        $toolStep = new RunToolStep($run, $granted, $store, $answer);
        $notifier = new RunNotifier(
            $run->id,
            $store,
            $this->chainCalls,
            new StepRecorder($this->estimates, $this->costs),
            new RunProgress($run->id, LoopRequestOf::PROGRESS_SECONDS, $clock),
            $toolStep,
            $this->rows,
        );

        return new LoopReceivers(
            new RunTools($toolStep, $this->stages, $this->writes),
            $notifier,
            new RunChecker($run->id, $targets, $clock, $this->sendCheck),
            $clock,
            new RunTexts($this->builder, $instructions, [
                ...$this->settings->purpose(ConversationSettings::CONVERSATION),
                'provider' => $targets->conversation->provider,
                'model' => $targets->conversation->model,
            ]),
            new RunCreatedFiles,
        );
    }

    /** 1 回の送信(題)の受け手。道具を渡さないので、道具の段の受け手は空の一覧で組む */
    public function send(
        AiRun $run,
        StepTargets $targets,
        ConversationStore $store,
        string $instructions,
    ): SendReceivers {
        $loop = $this->loop($run, $targets, $store, $instructions, new GrantedTools([], []));

        return new SendReceivers($loop->checker, $loop->notifier, $loop->clock, $loop->texts);
    }
}
