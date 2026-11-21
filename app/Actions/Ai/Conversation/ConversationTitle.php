<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\ConversationCore;
use App\Actions\Ai\Core\ModelSpec;
use App\Actions\Ai\Core\ResponseFinish;
use App\Actions\Ai\Core\SendFailed;
use App\Actions\Ai\Core\SendReceivers;
use App\Actions\Ai\Core\SendRequest;
use App\Actions\Ai\Core\SendTarget;
use App\Actions\Ai\Core\SentResponse;
use App\Actions\Ai\Instructions\InstructionBuilder;
use App\Actions\Ai\Instructions\InstructionRequest;
use App\Actions\Ai\VisitorOptions;
use App\Enums\Ai\AiInstructionCall;
use App\Models\Ai\AiAgentDefinition;
use App\Models\Ai\AiRun;
use App\Queries\Ai\AiSettings;
use App\Queries\Ai\ConversationSettings;
use App\Queries\Ai\KindSettings;
use App\Queries\Ai\TitleBudget;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\AiManager;
use LogicException;
use RuntimeException;

/**
 * 会話のジョブの 2 の題のステップ(docs の 設計/AI/エージェントの実行/途中の表示と上限.md 9.10、終わりの理由の表の題の欄 = 設計/AI/会話の中核.md 13.6)。
 * 題のステップの失敗は実行の終わり方を変えず、送信の失敗は報告して続ける(握りつぶさない)。
 */
final readonly class ConversationTitle
{
    public function __construct(
        private ConversationSettings $settings,
        private AiSettings $aiSettings,
        private KindSettings $kinds,
        private InstructionBuilder $builder,
        private AiManager $ai,
        private TitleBudget $budget,
    ) {}

    /**
     * @return string|null 作れた題(作らなかったときは null)
     */
    public function make(
        ConversationCore $core,
        AiRun $run,
        AiAgentDefinition $definition,
        ConversationItem $userText,
        StepTarget $target,
        SendReceivers $receivers,
    ): ?string {
        if (preg_match('/^[\s\p{Z}]*\z/u', (string) ($userText->content['text'] ?? '')) === 1) {
            return null;
        }

        $field = $this->builder->titleField();
        $schema = new JsonSchemaTypeFactory;
        $type = $schema->string();
        $result = $core->send(new SendRequest(
            $this->target($run, $target),
            $this->instructions($run, $definition, $target),
            [$userText],
            [],
            [$field->name => $field->description === '' ? $type : $type->description($field->description)],
            [
                ...$this->settings->purpose(ConversationSettings::TITLE)['provider_options'],
                ...VisitorOptions::of($this->aiSettings->providerDriver($target->provider), $run->visitor_id),
            ],
            $this->budgetOf($run),
            $receivers,
        ));

        return $this->titleOf($result, $field->name);
    }

    private function titleOf(mixed $result, string $field): ?string
    {
        if ($result instanceof SendFailed) {
            report(new RuntimeException("会話の題の送信が失敗した({$result->failure->value})"));
        }

        if (! $result instanceof SentResponse || $result->finish !== ResponseFinish::Stop) {
            return null;
        }

        $title = $result->structured[$field] ?? null;

        return is_string($title) ? $title : null;
    }

    /** 題の持ち時間 = 実行の時間の上限 × 設定の割合(9.10) */
    private function budgetOf(AiRun $run): int
    {
        $kind = $this->kinds->conversation($run->kind) ?? throw new LogicException("会話の種類 {$run->kind} の設定が無い");

        return $this->budget->milliseconds($kind['run_timeout_seconds']);
    }

    private function target(AiRun $run, StepTarget $target): SendTarget
    {
        $kind = $this->kinds->conversation($run->kind) ?? throw new LogicException("会話の種類 {$run->kind} の設定が無い");
        $snapshot = $run->purposes[ConversationSettings::TITLE]['max_output_tokens']
            ?? throw new LogicException("実行 {$run->id} に出力の上限の写しが無い");

        return new SendTarget(
            $this->ai->textProvider($target->provider),
            $target->provider,
            new ModelSpec($target->model, null, (int) $snapshot, null, false),
            $target->traits,
            $kind['purposes'][ConversationSettings::TITLE]['timeout_seconds'],
        );
    }

    private function instructions(AiRun $run, AiAgentDefinition $definition, StepTarget $target): string
    {
        $purpose = $this->settings->purpose(ConversationSettings::TITLE);

        return $this->builder->build(new InstructionRequest(
            AiInstructionCall::Title,
            (string) $definition->instructions,
            [],
            $target->provider,
            $target->model,
            $purpose['display_name'],
            $purpose['knowledge_cutoff'],
            InstructionRequest::JAPANESE,
            $run->started_at ?? throw new LogicException("実行 {$run->id} が始まっていない"),
        ))->system;
    }
}
