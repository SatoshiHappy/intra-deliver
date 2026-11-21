<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Api\ConversationAnswerRequest;
use App\Actions\Ai\Api\ConversationRequested;
use App\Actions\Ai\Api\ConversationTextRequest;
use App\Actions\Ai\Api\RequestConversation;
use App\Actions\Ai\Api\RunRequestDeclined;
use App\Enums\Ai\AiConversationAnswer;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiToolCall;
use Closure;
use Tests\Feature\Ai\RecordingConversationKind;
use Throwable;

/**
 * 会話の依頼の口を依頼者($requester)と定義($definition)で呼ぶ手助けと、前の実行の準備。
 */
trait RequestsConversations
{
    private function send(
        string $text,
        ?int $previousRunId,
        bool $wantsTitle = false,
        ?string $kindCode = null,
        ?int $definitionId = null,
    ): ConversationRequested {
        return app(RequestConversation::class)->send(
            $this->requesterContext($this->requester->id),
            $this->textRequest($text, $previousRunId, $wantsTitle, $kindCode, $definitionId),
        );
    }

    private function answer(AiConversationAnswer $answer, int $previousRunId): ConversationRequested
    {
        return app(RequestConversation::class)->answer(
            $this->requesterContext($this->requester->id),
            new ConversationAnswerRequest(
                RecordingConversationKind::CODE,
                $this->definition->id,
                $answer,
                $previousRunId,
            ),
        );
    }

    private function textRequest(
        string $text,
        ?int $previousRunId,
        bool $wantsTitle = false,
        ?string $kindCode = null,
        ?int $definitionId = null,
    ): ConversationTextRequest {
        return new ConversationTextRequest(
            $kindCode ?? RecordingConversationKind::CODE,
            $definitionId ?? $this->definition->id,
            $text,
            $previousRunId,
            $wantsTitle,
        );
    }

    private function previous(AiRunStatus $status, ?int $requesterUserId = null): AiRun
    {
        $ended = ! in_array($status, [AiRunStatus::Queued, AiRunStatus::Running], true);
        $failed = $status === AiRunStatus::Failed;

        return AiRun::factory()->conversation()->create([
            'kind' => RecordingConversationKind::CODE,
            'requester_user_id' => $requesterUserId ?? $this->requester->id,
            'status' => $status,
            'started_at' => $status === AiRunStatus::Queued ? null : now(),
            'finished_at' => $ended ? now() : null,
            'stop_reason' => $status === AiRunStatus::Stopped ? 'user' : null,
            'error_code' => $failed ? 'provider' : null,
            'error_message' => $failed ? '接続先の誤り' : null,
        ]);
    }

    /** @return list<AiToolCall> */
    private function awaitingRows(AiRun $previous, int $count): array
    {
        $step = AiRunStep::factory()->create(['ai_run_id' => $previous->id]);

        return array_map(
            fn (int $index): AiToolCall => AiToolCall::factory()->inStep($step)->asked()->create([
                'call_id' => 'c'.$index,
            ]),
            range(1, $count),
        );
    }

    private function overBudget(): void
    {
        AiRun::factory()->succeeded('0.010000')->create();
        config(['ai_runs.budget.default.daily_usd' => 0.0001]);
    }

    private function declined(Closure $request): ?RunRequestDeclined
    {
        try {
            $request();
        } catch (RunRequestDeclined $declined) {
            return $declined;
        } catch (Throwable $other) {
            $this->fail('422 でない例外: '.$other::class.' '.$other->getMessage());
        }

        return null;
    }
}
