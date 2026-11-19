<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\CoreToolCall;
use App\Actions\Ai\Core\ToolLookup;
use App\Actions\Ai\Core\ToolRun;
use App\Actions\Ai\Core\ToolRunner;
use App\Actions\Ai\ToolCalls\GrantedTool;
use App\Actions\Ai\ToolCalls\ModelToolCall;
use App\Actions\Ai\ToolCalls\ToolCallStages;
use LogicException;

/**
 * 道具を動かす口の中身(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の「道具を動かす口」)。探すと動かすを包みの段
 * ({@see ToolCallStages})に通す。無い道具は探すの時に包みが行と結果を書く(設計/AI/実行の記録.md 2.4 の T2)。
 */
final class RunTools implements ToolRunner
{
    /** @var array<string, GrantedTool> 探すで見つけた道具(呼び出しの id ごと) */
    private array $found = [];

    public function __construct(
        private readonly RunToolStep $step,
        private readonly ToolCallStages $stages,
    ) {}

    /**
     * @throws LogicException 先に動かす呼び出し(書く道具の承認待ち。まだ繋いでいない)のとき
     */
    public function find(CoreToolCall $call, bool $preRun): ToolLookup
    {
        self::refusePreRun($preRun);
        $granted = $this->step->tools()->find($call->name);

        if ($granted !== null) {
            $this->found[$call->callId] = $granted;

            return ToolLookup::found();
        }

        $result = $this->through($call, null);

        return $result === null ? ToolLookup::noConversation() : ToolLookup::missing($result);
    }

    /**
     * @throws LogicException 先に動かす呼び出しのとき・探すで見つけていない呼び出しのとき
     */
    public function run(CoreToolCall $call, bool $preRun): ToolRun
    {
        self::refusePreRun($preRun);
        $granted = $this->found[$call->callId] ?? throw new LogicException("呼び出し {$call->callId} を探していない");
        unset($this->found[$call->callId]);

        $result = $this->through($call, $granted);

        return $result === null ? ToolRun::noConversation() : ToolRun::result($result);
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

    private static function refusePreRun(bool $preRun): void
    {
        if ($preRun) {
            throw new LogicException('先に動かす呼び出し(承認待ち)は書く道具と一緒に繋ぐ');
        }
    }
}
