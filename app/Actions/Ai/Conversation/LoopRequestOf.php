<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\AddedTextValues;
use App\Actions\Ai\Core\CoreToolCall;
use App\Actions\Ai\Core\LoopReceivers;
use App\Actions\Ai\Core\LoopRequest;
use App\Actions\Ai\Core\ModelSpec;
use App\Actions\Ai\Core\ParallelTools;
use App\Actions\Ai\Core\SendTarget;
use App\Actions\Ai\Core\TransientText;
use App\Actions\Ai\ToolCalls\GrantedTool;
use App\Actions\Ai\ToolCalls\GrantedTools;
use App\Actions\Ai\VisitorOptions;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Ai\AiRunStepSendKind;
use App\Enums\Ai\AiRunStepStatus;
use App\Models\Ai\AiRun;
use App\Queries\Ai\AiSettings;
use App\Queries\Ai\ConversationSettings;
use App\Queries\Ai\KindSettings;
use App\Queries\Ai\RunChain;
use App\Queries\Ai\ToolChildSettings;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\AiManager;
use LogicException;

/**
 * 会話のジョブの 5 で中核に渡す物を組む(受け取る物 = docs の 設計/AI/エージェントの実行/README.md 9.1 の口の約束、値 = 実行の記録に写した
 * 用途の接続先とモデルと出力の上限 = 設計/AI/実行の記録.md 2.1)。
 */
final readonly class LoopRequestOf
{
    /** 途中の文を書く間隔(秒。作りの値 = 設計/AI/エージェントの実行/途中の表示と上限.md 9.6) */
    public const PROGRESS_SECONDS = 2;

    /** 会話の用途で送るループの送信の種別(題は 1 回の送信で題の用途 = 設計/AI/実行と費用/接続先と費用.md 5.3) */
    private const LOOP_KINDS = ['turn', 'continuation', 'compaction', 'compaction_fallback'];

    public function __construct(
        private ConversationSettings $settings,
        private AiSettings $aiSettings,
        private KindSettings $kinds,
        private AiManager $ai,
        private ThinkingStartOf $thinking,
        private ToolChildSettings $children,
    ) {}

    /**
     * @param  list<ConversationItem|TransientText>  $history
     * @param  list<CoreToolCall>  $preRunCalls  答えの実行の承認した件(呼び出しの順)
     *
     * @throws LogicException 種類の設定・実行の記録の写しが無いとき(作りの誤り)
     */
    public function of(
        AiRun $run,
        StepTarget $target,
        array $history,
        GrantedTools $granted,
        AddedTextValues $added,
        LoopReceivers $receivers,
        array $preRunCalls = [],
    ): LoopRequest {
        $kind = $this->kinds->conversation($run->kind) ?? throw new LogicException("会話の種類 {$run->kind} の設定が無い");
        $options = $this->options($run, $target->provider);

        return new LoopRequest(
            $this->target($run, $target, $kind['purposes'][ConversationSettings::CONVERSATION]['timeout_seconds']),
            $history,
            array_map(static fn (GrantedTool $tool): SdkTool => new SdkTool($tool), $granted->tools),
            $this->parallel($granted),
            true,
            array_fill_keys(self::LOOP_KINDS, $options),
            $kind['max_turns'],
            $this->priorTurns($run),
            self::PROGRESS_SECONDS,
            $preRunCalls,
            $added,
            $this->thinking->of($run->id, $history),
            $receivers,
        );
    }

    /**
     * 前の実行から引き継ぐ回数(数え方 = docs の 設計/AI/エージェントの実行/途中の表示と上限.md 9.7)。
     */
    private function priorTurns(AiRun $run): int
    {
        if ($run->previous_run_id === null) {
            return 0;
        }

        $awaiting = "ai_runs.status = '".AiRunStatus::AwaitingConfirmation->value."'";
        $row = DB::selectOne(
            RunChain::cte($awaiting)
            .' SELECT count(*) AS turns FROM ai_run_steps AS s JOIN chain ON s.ai_run_id = chain.id'
            ." WHERE s.send_kind = '".AiRunStepSendKind::Turn->value."'"
            ." AND s.status = '".AiRunStepStatus::Succeeded->value."'",
            [$run->previous_run_id],
        );

        return is_object($row) ? (int) ($row->turns ?? 0) : 0;
    }

    private function parallel(GrantedTools $granted): ParallelTools
    {
        $safe = [];

        foreach ($granted->tools as $tool) {
            $safe[$tool->tool->name()] = $tool->tool->isConcurrencySafe();
        }

        return new ParallelTools($safe, $this->children->concurrency());
    }

    private function target(AiRun $run, StepTarget $target, int $timeoutSeconds): SendTarget
    {
        $options = $this->settings->purpose(ConversationSettings::CONVERSATION)['provider_options'];
        $thinking = $options['thinking'] ?? null;
        $snapshot = $run->purposes[ConversationSettings::CONVERSATION]['max_output_tokens']
            ?? throw new LogicException("実行 {$run->id} に出力の上限の写しが無い");

        return new SendTarget(
            $this->ai->textProvider($target->provider),
            $target->provider,
            new ModelSpec(
                $target->model,
                $this->settings->contextWindowTokens(),
                (int) $snapshot,
                is_array($thinking) && is_int($thinking['budget_tokens'] ?? null) ? $thinking['budget_tokens'] : null,
                is_array($thinking) && ($thinking['type'] ?? 'disabled') !== 'disabled',
            ),
            $target->traits,
            $timeoutSeconds,
        );
    }

    /**
     * 用途の足す指定と、実行ごとの訪問者の ID。
     *
     * @return array<string, mixed>
     */
    private function options(AiRun $run, string $provider): array
    {
        return [
            ...$this->settings->purpose(ConversationSettings::CONVERSATION)['provider_options'],
            ...VisitorOptions::of($this->aiSettings->providerDriver($provider), $run->visitor_id),
        ];
    }
}
