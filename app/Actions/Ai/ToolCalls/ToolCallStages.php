<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentRecordTool;
use App\Actions\Ai\Api\AgentToolParties;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Api\PriorToolCall;
use App\Actions\Ai\Instructions\SharedInstructionTemplates;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolCallDecision;
use App\Enums\Ai\AiToolCallStatus;
use App\Queries\Ai\Api\RecordMarkers;
use App\Queries\Authz\Api\AuthorizationReuse;
use LogicException;

/**
 * 道具を呼ぶ包みの、止める印の後の段。どの段で終わっても同じ形の結果と道具の行にするため、1 か所に集める(docs の
 * 設計/AI/エージェントの実行/道具の包みと止める.md 9.4、設計/AI/道具と権限/README.md 10.2、設計/AI/道具と権限/読み書きのたびの今の権限.md 10.4)。
 *
 * @phpstan-type Result array{callId: string, toolCallId: int, item: ConversationItem|null}
 *
 * 結果の項目が null なのは問う(確認待ちで、会話の保存に足さない = 9.4 の 5)。
 */
class ToolCallStages
{
    public function __construct(
        private ToolArgumentProblems $problems,
        private ToolJudge $judge,
        private ToolCallRows $rows,
        private AuthorizationReuse $reuse,
        private SharedInstructionTemplates $shared,
        private ToolPartyNames $partyNames,
        private ToolCallAsks $asks,
    ) {}

    /**
     * 呼び出し 1 つを通す(渡した道具に無ければ無い道具の結果)。会話が無ければ結果は null。
     *
     * @param  list<PriorToolCall>  $prior  同じステップの前の呼び出し
     * @return array{Result|null, PriorToolCall}
     */
    public function through(
        ToolStep $step,
        int $position,
        ModelToolCall $call,
        ?GrantedTool $granted,
        array $prior,
    ): array {
        $name = RecordMarkers::neutralize($call->name);

        if ($granted === null) {
            $item = self::failure($call, AiInstructionTemplate::UnknownTool, ['tool_name' => $name]);

            return $this->failed($step, $position, $call, AiToolCallDecision::UnknownTool, $item, null);
        }

        try {
            $problems = ToolCallException::during(
                $call,
                ToolCallException::WRAPPER,
                fn (): array => $this->problems->of($granted->tool, $call->arguments),
            );
        } catch (ToolCallException $exception) {
            return $this->wrapperException($step, $position, $call, null, $exception);
        }

        if ($problems !== []) {
            $item = self::failure($call, AiInstructionTemplate::ToolInputInvalid, [
                'tool_name' => $name,
                'problems' => array_map(RecordMarkers::neutralize(...), $problems),
            ]);

            return $this->failed($step, $position, $call, AiToolCallDecision::InvalidInput, $item, null);
        }

        // 形を通った引数の鍵は、引数の形の最上位の鍵(文字列)だけ
        $arguments = array_filter($call->arguments, is_string(...), ARRAY_FILTER_USE_KEY);

        return $this->validated($step, $position, $call, $granted, $arguments, $prior);
    }

    /**
     * 相手の名前の引き当てと、書く道具の ③ に共通の確かめ({@see ToolWriteChecks})と、道具の検証(形の外の検証)の後、区間の中で判定の関数と
     * 道具を呼ぶ。
     *
     * @param  array<string, mixed>  $arguments
     * @param  list<PriorToolCall>  $prior
     * @return array{Result|null, PriorToolCall}
     */
    private function validated(
        ToolStep $step,
        int $position,
        ModelToolCall $call,
        GrantedTool $granted,
        array $arguments,
        array $prior,
    ): array {
        $tool = $granted->tool;
        $checked = function () use ($tool, $arguments, $granted, $prior, $step): array {
            if (! $tool instanceof AgentRecordTool) {
                $plain = new ToolPartyResolution($arguments, new AgentToolParties);

                return [$plain, $tool->validate($arguments, $granted->scope, $prior)];
            }

            $resolved = $this->partyNames->resolve($tool, $arguments, $granted->scope, $step->run);

            if ($resolved->error !== null) {
                return [$resolved, $resolved->error];
            }

            $scope = $granted->scope;
            [$written, $error] = ToolWriteChecks::whenCalled($tool, $resolved->arguments, $scope, $prior, $step->tools);
            $resolved = new ToolPartyResolution($written, $resolved->parties);

            return [$resolved, $error ?? $tool->validate($written, $scope, $prior)];
        };

        try {
            [$resolved, $error] = ToolCallException::during($call, ToolCallException::WRAPPER, $checked);
        } catch (ToolCallException $exception) {
            return $this->wrapperException($step, $position, $call, $arguments, $exception);
        }

        if ($error !== null) {
            $values = ['error' => RecordMarkers::neutralize($error)];
            $item = self::failure($call, AiInstructionTemplate::ToolError, $values);

            return $this->failed($step, $position, $call, AiToolCallDecision::ValidationError, $item, $arguments);
        }

        return $this->reuse->without(
            fn (): array => $this->judged($step, $position, $call, $granted, $resolved, $prior),
        );
    }

    /**
     * 判定の関数の答えで、断る(2.4 の T3)か、問う(T5)か、行を処理中にして道具を呼ぶ(T4)。
     *
     * @param  list<PriorToolCall>  $prior
     * @return array{Result|null, PriorToolCall}
     */
    private function judged(
        ToolStep $step,
        int $position,
        ModelToolCall $call,
        GrantedTool $granted,
        ToolPartyResolution $resolved,
        array $prior,
    ): array {
        [$tool, $arguments, $parties] = [$granted->tool, $resolved->arguments, $resolved->parties];

        try {
            $judgement = ToolCallException::during(
                $call,
                ToolCallException::WRAPPER,
                fn (): ToolJudgement => $tool instanceof AgentRecordTool
                    ? $this->judge->judge($tool, $arguments, $granted, $step->run, $parties)
                    : throw new LogicException("道具 {$tool->name()} の判定は無い(業務モジュールを読み書きする道具でない)"),
            );
        } catch (ToolCallException $exception) {
            return $this->wrapperException($step, $position, $call, $arguments, $exception);
        }

        if ($judgement->confirmation !== null) {
            return $this->asks->asked($step, $position, $call, $granted, $judgement, $prior);
        }

        return $judgement->subject === null
            ? $this->denied($step, $position, $call, $judgement, $arguments)
            : $this->allowed($step, $position, $call, $granted, $judgement->subject, $arguments, $parties);
    }

    /**
     * 許す(2.4 の T4): 行を処理中にして道具を呼ぶ。
     *
     * @param  array<string, mixed>  $arguments
     * @return array{Result|null, PriorToolCall}
     */
    private function allowed(
        ToolStep $step,
        int $position,
        ModelToolCall $call,
        GrantedTool $granted,
        AgentToolSubject $subject,
        array $arguments,
        AgentToolParties $parties,
    ): array {
        $tool = $granted->tool;
        $row = $this->rows->running($step, $position, $call);
        $executed = function () use ($step, $row, $call, $tool, $arguments, $subject, $granted, $parties): array {
            $result = $tool->execute($arguments, $subject, $granted->scope, $parties);
            $shaped = ToolResultText::shaped($tool, $result, RecordMarkers::neutralize($call->name));
            [$template, $values, $body] = [$shaped['template'], $shaped['values'], $shaped['body']];
            $item = ConversationItem::toolResult($call->id, $template, $values, $body, false);
            $done = AiToolCallStatus::Done;

            return [$item, $this->rows->completed($step, $row, $done, $shaped['full'], $result->records, $item)];
        };

        try {
            [$item, $written] = ToolCallException::during($call, ToolCallException::TOOL, $executed);
        } catch (ToolCallException $exception) {
            report($exception);
            $item = $this->exceptionItem($call);
            $written = $this->rows->completed($step, $row, AiToolCallStatus::ToolError, null, [], $item);
        }

        $prior = new PriorToolCall($call->id, $call->name, $arguments, AiToolCallDecision::Allow);

        return [$written ? ['callId' => $call->id, 'toolCallId' => $row->id, 'item' => $item] : null, $prior];
    }

    /**
     * 断る(2.4 の T3)の行と原文と会話の保存の足す。規則による断りは道具の名前と判定の側が添える文を値に入れる。
     *
     * @param  array<string, mixed>  $arguments
     * @return array{Result|null, PriorToolCall}
     */
    private function denied(
        ToolStep $step,
        int $position,
        ModelToolCall $call,
        ToolJudgement $judgement,
        array $arguments,
    ): array {
        $values = array_map(RecordMarkers::neutralize(...), $judgement->values);
        $values = $judgement->template === AiInstructionTemplate::RuleDenied
            ? ['tool_name' => RecordMarkers::neutralize($call->name), ...$values]
            : $values;
        [$template, $body] = [$judgement->template, $judgement->body];
        $item = ConversationItem::toolResult($call->id, $template, $values, $body, $judgement->isError);
        $ids = $this->rows->finished($step, [[
            'position' => $position,
            'call' => $call,
            'decision' => AiToolCallDecision::Deny,
            'status' => AiToolCallStatus::Rejected,
            'reason' => $judgement->reason,
            'item' => $item,
            'text' => $judgement->body,
        ]]);
        $prior = new PriorToolCall($call->id, $call->name, $arguments, AiToolCallDecision::Deny);

        return [self::result($call, $ids, $item), $prior];
    }

    /**
     * 包みの結果(2.4 の T2)の行と原文と会話の保存の足す。
     *
     * @param  array<string, mixed>|null  $arguments  形を通った引数(通らなければ null)
     * @return array{Result|null, PriorToolCall}
     */
    private function failed(
        ToolStep $step,
        int $position,
        ModelToolCall $call,
        AiToolCallDecision $decision,
        ConversationItem $item,
        ?array $arguments,
    ): array {
        $ids = $this->rows->finished($step, [[
            'position' => $position,
            'call' => $call,
            'decision' => $decision,
            'status' => AiToolCallStatus::ToolError,
            'reason' => null,
            'item' => $item,
            'text' => null,
        ]]);
        $prior = new PriorToolCall($call->id, $call->name, $arguments, $decision);

        return [self::result($call, $ids, $item), $prior];
    }

    /**
     * 包みの中の例外(報告して、道具の名前だけの決まった失敗の文)。
     *
     * @param  array<string, mixed>|null  $arguments
     * @return array{Result|null, PriorToolCall}
     */
    private function wrapperException(
        ToolStep $step,
        int $position,
        ModelToolCall $call,
        ?array $arguments,
        ToolCallException $exception,
    ): array {
        report($exception);
        $item = $this->exceptionItem($call);

        return $this->failed($step, $position, $call, AiToolCallDecision::WrapperException, $item, $arguments);
    }

    /** 道具の例外と包みの中の例外の結果(道具の失敗の包みに、道具の名前だけを入れた決まった失敗の文 = ③ の定型) */
    private function exceptionItem(ModelToolCall $call): ConversationItem
    {
        $name = RecordMarkers::neutralize($call->name);
        $text = $this->shared->text(AiInstructionTemplate::ToolException, ['tool_name' => $name]);

        return self::failure($call, AiInstructionTemplate::ToolError, ['error' => $text]);
    }

    /**
     * @param  array<string, string|list<string>>  $values
     */
    private static function failure(
        ModelToolCall $call,
        AiInstructionTemplate $template,
        array $values,
    ): ConversationItem {
        return ConversationItem::toolResult($call->id, $template, $values, null, true);
    }

    /**
     * @param  list<int>|null  $ids
     * @return Result|null
     */
    private static function result(ModelToolCall $call, ?array $ids, ConversationItem $item): ?array
    {
        return $ids === null ? null : ['callId' => $call->id, 'toolCallId' => $ids[0], 'item' => $item];
    }
}
