<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Api\AgentCreateTool;
use App\Actions\Ai\Api\AgentUpdateTool;
use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\CoreToolCall;
use App\Actions\Ai\Core\ToolLookup;
use App\Actions\Ai\Core\ToolRun;
use App\Actions\Ai\Core\ToolRunner;
use App\Actions\Ai\Core\ToolRuns;
use App\Actions\Ai\ToolCalls\GrantedTool;
use App\Actions\Ai\ToolCalls\ModelToolCall;
use App\Actions\Ai\ToolCalls\ToolCallStages;
use App\Actions\Ai\ToolCalls\ToolCallWrites;
use LogicException;

/**
 * 道具を動かす口の中身(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の「道具を動かす口」)。探すと動かすを包みの段
 * ({@see ToolCallStages})に通す。無い道具は探すの時に包みが行と結果を書く(設計/AI/実行の記録.md 2.4 の T2)。先に動かす呼び出し(承認した件)は
 * 書く時の部品({@see ToolCallWrites})に通す(道具は登録から引く = 設計/AI/エージェントの実行/README.md 9.3)。
 */
final class RunTools implements ToolRunner
{
    /** @var array<string, GrantedTool> 探すで見つけた道具(呼び出しの id ごと) */
    private array $found = [];

    /** @var array<string, AgentCreateTool|AgentUpdateTool> 探すで見つけた承認した件の道具(呼び出しの id ごと) */
    private array $approved = [];

    public function __construct(
        private readonly RunToolStep $step,
        private readonly ToolCallStages $stages,
        private readonly ToolCallWrites $writes,
        private readonly RunToolChildren $children,
    ) {}

    /**
     * @throws LogicException 先に動かす呼び出しが承認した件でないとき(作りの誤り)
     */
    public function find(CoreToolCall $call, bool $preRun): ToolLookup
    {
        if ($preRun) {
            return $this->findApproved($call);
        }

        $granted = $this->step->tools()->find($call->name);

        if ($granted !== null) {
            $this->found[$call->callId] = $granted;

            return ToolLookup::found();
        }

        $result = $this->through($call, null);

        return $result === null ? ToolLookup::noConversation() : ToolLookup::missing($result);
    }

    /**
     * @throws LogicException 探すで見つけていない呼び出しのとき
     */
    public function run(CoreToolCall $call, bool $preRun): ToolRun
    {
        if ($preRun) {
            return $this->runApproved($call);
        }

        $result = $this->through($call, $this->taken($call));

        return $this->ran($call, $result);
    }

    /**
     * @throws LogicException 探すで見つけていない呼び出しのとき
     */
    public function runParallel(array $calls, int $concurrency): ToolRuns
    {
        $found = array_map(fn (CoreToolCall $call): array => [$call, $this->taken($call)], $calls);
        $items = $this->children->run($this->step, $found, $concurrency);

        return $items === null ? ToolRuns::noConversation() : ToolRuns::results(array_map(
            fn (CoreToolCall $call, ConversationItem $item): ToolRun => ToolRun::result(
                $item,
                $this->step->lastSendFinishedAt($call->callId),
            ),
            $calls,
            $items,
        ));
    }

    /** 探すで見つけた道具を取り出す */
    private function taken(CoreToolCall $call): GrantedTool
    {
        $granted = $this->found[$call->callId] ?? throw new LogicException("呼び出し {$call->callId} を探していない");
        unset($this->found[$call->callId]);

        return $granted;
    }

    private function through(CoreToolCall $call, ?GrantedTool $granted): ?ConversationItem
    {
        [$result, $prior] = $this->stages->through(
            $this->step->toolStep(),
            $this->step->nextPosition(),
            new ModelToolCall($call->callId, $call->name, $call->arguments),
            $granted,
            $this->step->prior(),
        );
        $this->step->passed($prior);

        return $result['item'] ?? null;
    }

    private function findApproved(CoreToolCall $call): ToolLookup
    {
        $answer = $this->answer();
        $row = $answer->row($call->callId);
        $tool = $this->writes->tool($row);

        if ($tool !== null) {
            $this->approved[$call->callId] = $tool;

            return ToolLookup::found();
        }

        $result = $this->writes->unknown($this->step->answerContext(), $row, $answer->progress);

        return $result === null ? ToolLookup::noConversation() : ToolLookup::missing($result);
    }

    private function runApproved(CoreToolCall $call): ToolRun
    {
        $answer = $this->answer();
        $tool = $this->approved[$call->callId] ?? throw new LogicException("承認した件 {$call->callId} を探していない");
        unset($this->approved[$call->callId]);
        $row = $answer->row($call->callId);
        $context = $this->step->answerContext();
        $text = $answer->text($row);
        $result = $this->writes->one($context, $answer->definition, $row, $text, $tool, $answer->progress);

        return $this->ran($call, $result);
    }

    private function ran(CoreToolCall $call, ?ConversationItem $result): ToolRun
    {
        return $result === null
            ? ToolRun::noConversation()
            : ToolRun::result($result, $this->step->lastSendFinishedAt($call->callId));
    }

    private function answer(): AnswerCalls
    {
        return $this->step->answer() ?? throw new LogicException('先に動かす呼び出しは答えの実行の承認した件だけ');
    }
}
