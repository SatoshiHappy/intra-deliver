<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentCreateTool;
use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Actions\Ai\Api\AgentUpdateTool;
use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Api\PriorToolCall;
use App\Actions\Ai\Api\RecordConfirmation;
use App\Enums\Ai\AiRunStopReason;
use App\Enums\Ai\AiToolCallDecision;
use App\Enums\Ai\AiToolCallStatus;
use App\Enums\Ai\AiToolWriteDenial;
use App\Models\Ai\AiAgentDefinition;
use App\Models\Ai\AiToolCall;
use App\Models\Ai\AiToolCallText;
use App\Queries\Ai\Api\RecordMarkers;
use App\Queries\Authz\Api\AuthorizationReuse;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 書く時の部品(docs の 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.5 の「実行」の後の書き方と表 2、設計/AI/エージェントの実行/道具の包みと止める.md
 * 9.4 の承認待ちの件、設計/AI/実行の記録.md 2.4 の T7)。判定から書き込みまでを件ごとに使い回さない区間で囲むのは、書く時点の依頼者の今の権限で
 * 判定するため(設計/AI/道具と権限/読み書きのたびの今の権限.md 10.4)。行を処理中で先に確定するのは、worker が落ちた件を結果不明として拾うため。
 */
class ToolCallWrites
{
    public function __construct(
        private readonly AgentTools $tools,
        private readonly ToolArgumentProblems $problems,
        private readonly ToolWriteRechecks $rechecks,
        private readonly ToolWriteJudge $judge,
        private readonly ToolCallRows $rows,
        private readonly AuthorizationReuse $reuse,
        private readonly ToolWriteResults $results,
        private readonly ToolWriteStops $stops,
    ) {}

    /**
     * @param  AiAgentDefinition  $definition  答えの実行の始めに読んだ定義
     * @param  list<AiToolCall>  $approved  承認した件(呼び出しの順。同じステップの問うの件)
     * @param  Closure(): ?AiRunStopReason  $stopped  止める印(利用者)か時間の上限に当たったときの理由
     */
    public function write(
        ToolStep $step,
        AiAgentDefinition $definition,
        array $approved,
        Closure $stopped,
    ): ToolStepOutcome {
        $done = new ToolWriteProgress;

        foreach ($approved as $index => $row) {
            $tool = $this->tools->find($row->tool_name);
            $writes = $tool instanceof AgentCreateTool || $tool instanceof AgentUpdateTool;
            $reason = $writes ? $stopped() : null;

            if ($reason !== null) {
                return $this->stops->stopped($step, array_slice($approved, $index), $reason, $done->results);
            }

            if (! $writes) {
                $done->prior[] = new PriorToolCall($row->call_id, $row->tool_name, null, AiToolCallDecision::Ask);
            }

            $item = $writes ? $this->one($step, $definition, $row, $tool, $done) : $this->results->unknown($step, $row);

            if ($item === null) {
                return new ToolStepOutcome($done->results, conversationMissing: true);
            }

            $done->results[] = ['callId' => $row->call_id, 'toolCallId' => $row->id, 'item' => $item];
        }

        return new ToolStepOutcome($done->results);
    }

    /** 1 件を通す(会話が無ければ null) */
    private function one(
        ToolStep $step,
        AiAgentDefinition $definition,
        AiToolCall $row,
        AgentCreateTool|AgentUpdateTool $tool,
        ToolWriteProgress $done,
    ): ?ConversationItem {
        $text = AiToolCallText::query()->whereKey($row->id)->first();
        $written = $text?->written_arguments;
        $prior = $done->prior;
        $done->prior[] = new PriorToolCall($row->call_id, $row->tool_name, $written, AiToolCallDecision::Ask);
        $call = new ModelToolCall($row->call_id, $row->tool_name, $text === null ? [] : $text->arguments);

        if ($text === null || $written === null) {
            return $this->missing($step, $row, $call, "承認した道具の行 {$row->id} に書く値の引数が無い(前の版の行か、原文の期限で消えた行)");
        }

        if ($tool instanceof AgentUpdateTool && $row->confirmation_version === null) {
            return $this->missing($step, $row, $call, "承認した直すの道具の行 {$row->id} に呼ばれた時の版が無い(前の版の行)");
        }

        $write = new ToolWriteCall($row, $call, $tool, $written, new RecordConfirmation($text->confirmation ?? []));

        $checked = fn (): ?ConversationItem => $this->checked($step, $definition, $write, $prior, $done);

        return $this->reuse->without($checked);
    }

    /** 書くのに要る値が行に無い件(作りの誤りでも実行を落とさず、包みの中の例外 = T7 の道具の誤り) */
    private function missing(ToolStep $step, AiToolCall $row, ModelToolCall $call, string $message): ?ConversationItem
    {
        try {
            ToolCallException::during($call, ToolCallException::WRAPPER, fn () => throw new LogicException($message));
        } catch (ToolCallException $exception) {
            return $this->results->exception($step, $row, $exception);
        }
    }

    /**
     * やり直しと判定の関数の後に書く(どれかの中の例外は包みの中の例外 = 9.4)。
     *
     * @param  list<PriorToolCall>  $prior
     */
    private function checked(
        ToolStep $step,
        AiAgentDefinition $definition,
        ToolWriteCall $write,
        array $prior,
        ToolWriteProgress $done,
    ): ?ConversationItem {
        $row = $write->row;
        $granted = $step->tools->find($row->tool_name);

        try {
            $checks = ToolCallException::during(
                $write->call,
                ToolCallException::WRAPPER,
                fn (): array => $this->checks($step, $definition, $write, $granted, $prior, $done),
            );
        } catch (ToolCallException $exception) {
            return $this->results->exception($step, $row, $exception);
        }

        if ($checks['shape'] !== [] || $checks['error'] !== null) {
            return $this->results->invalid($step, $row, $checks['shape'], $checks['error']);
        }

        if ($checks['denial'] !== null || $checks['subject'] === null) {
            [$denial, $text] = ToolWriteDenials::denial($checks['denial'] ?? AiToolWriteDenial::NoPermission);

            return $this->results->denied($step, $row, $denial, $text);
        }

        $scope = $granted === null ? new AgentToolScope : $granted->scope;

        return $this->written($step, $write, $checks['filled'], $checks['subject'], $scope, $done);
    }

    /**
     * 前で決まれば後を呼ばない(要らない判定の問い合わせを避けるため)。
     *
     * @param  list<PriorToolCall>  $prior
     * @return array{shape: list<string>, error: string|null, denial: AiToolWriteDenial|null,
     *     subject: AgentToolSubject|null, filled: array<string, mixed>}
     */
    private function checks(
        ToolStep $step,
        AiAgentDefinition $definition,
        ToolWriteCall $write,
        ?GrantedTool $granted,
        array $prior,
        ToolWriteProgress $done,
    ): array {
        $answer = ['shape' => [], 'error' => null, 'denial' => null, 'subject' => null, 'filled' => []];
        $answer['shape'] = $this->problems->of($write->tool, $write->call->arguments);
        $subject = ToolSubjects::forRun($step->run);
        $answer['error'] = $answer['shape'] !== [] || $granted === null ? null : $this->rechecks->problems(
            $write->tool, $write->written, $granted, $prior, $step->tools, $subject, $write->confirmation,
        );

        if ($answer['shape'] !== [] || $answer['error'] !== null) {
            return $answer;
        }

        $priorWritten = array_diff(ToolCallRefs::names($write->written), array_keys($done->created)) === [];
        [$answer['denial'], $answer['subject']] = $this->judge->decide(
            $write->tool, $write->written, $definition, $step->run, $priorWritten,
        );
        $answer['filled'] = $answer['denial'] === null ? ToolCallRefs::filled($write->written, $done->created) : [];

        return $answer;
    }

    /**
     * 書く(書く道具のトランザクションと例外の分け方 = 9.4・9.11 の S23)。
     *
     * @param  array<string, mixed>  $filled
     */
    private function written(
        ToolStep $step,
        ToolWriteCall $write,
        array $filled,
        AgentToolSubject $subject,
        AgentToolScope $scope,
        ToolWriteProgress $done,
    ): ?ConversationItem {
        $row = $write->row;
        $this->rows->approved($step, $row);

        try {
            [$item, $records, $appended] = DB::transaction(
                fn (): array => $this->transacted($step, $write, $filled, $subject, $scope),
            );
        } catch (ToolCallException $exception) {
            // トランザクションの中で直した行の値は戻ったので、読み直してから書く
            $row->refresh();
            $mapped = ToolWriteDenials::of($exception->getPrevious(), $write->tool instanceof AgentCreateTool);

            return $mapped === null
                ? $this->results->exception($step, $row, $exception)
                : $this->results->denied($step, $row, ...$mapped);
        }

        $done->createdBy($write->tool, $filled, $records);

        return $appended ? $item : null;
    }

    /**
     * @param  array<string, mixed>  $filled
     * @return array{ConversationItem, list<array{module: string, id: int, name: string}>, bool}
     *
     * @throws ToolCallException 道具の書く口が投げたとき(入れ物の断りを含む。トランザクションは戻る)
     */
    private function transacted(
        ToolStep $step,
        ToolWriteCall $write,
        array $filled,
        AgentToolSubject $subject,
        AgentToolScope $scope,
    ): array {
        [$row, $tool, $confirmation] = [$write->row, $write->tool, $write->confirmation];
        $writeOne = function () use ($tool, $filled, $subject, $scope, $confirmation, $row): array {
            $result = $tool instanceof AgentCreateTool
                ? $tool->write($filled, $subject, $scope, $confirmation)
                : $tool->write($filled, $subject, $scope, $confirmation, (string) $row->confirmation_version);

            return [$result, ToolResultText::shaped($tool, $result, RecordMarkers::neutralize($row->tool_name))];
        };
        [$result, $shaped] = ToolCallException::during($write->call, ToolCallException::TOOL, $writeOne);
        [$template, $values, $body] = [$shaped['template'], $shaped['values'], $shaped['body']];
        $item = ConversationItem::toolResult($row->call_id, $template, $values, $body, false);
        $records = $result->records;
        $appended = $this->rows->answered($step, $row, AiToolCallStatus::Done, null, $shaped['full'], $records, $item);

        return [$item, $records, $appended];
    }
}
