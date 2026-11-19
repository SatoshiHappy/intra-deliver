<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\AddedTextValues;
use App\Actions\Ai\Core\LoopReceivers;
use App\Actions\Ai\Core\LoopRequest;
use App\Actions\Ai\Core\ModelSpec;
use App\Actions\Ai\Core\SendTarget;
use App\Actions\Ai\Core\TransientText;
use App\Actions\Ai\ToolCalls\GrantedTool;
use App\Actions\Ai\ToolCalls\GrantedTools;
use App\Actions\Ai\VisitorOptions;
use App\Models\Ai\AiRun;
use App\Queries\Ai\AiSettings;
use App\Queries\Ai\ConversationSettings;
use App\Queries\Ai\KindSettings;
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
    ) {}

    /**
     * @param  list<ConversationItem|TransientText>  $history
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
    ): LoopRequest {
        $kind = $this->kinds->conversation($run->kind) ?? throw new LogicException("会話の種類 {$run->kind} の設定が無い");
        $options = $this->options($run, $target->provider);

        return new LoopRequest(
            $this->target($run, $target, $kind['purposes'][ConversationSettings::CONVERSATION]['timeout_seconds']),
            $history,
            array_map(static fn (GrantedTool $tool): SdkTool => new SdkTool($tool), $granted->tools),
            true,
            array_fill_keys(self::LOOP_KINDS, $options),
            $kind['max_turns'],
            self::PROGRESS_SECONDS,
            [],
            $added,
            $receivers,
        );
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
