<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\CheckPoint;
use App\Actions\Ai\Core\CheckRequest;
use App\Actions\Ai\Core\CoreChecker;
use App\Actions\Ai\Core\CoreToolCall;
use App\Actions\Ai\Core\LoopEnding;
use App\Actions\Ai\Core\LoopStop;
use App\Actions\Ai\Core\ParallelTools;
use App\Actions\Ai\Core\ToolGroup;
use App\Actions\Ai\Core\ToolLookupAnswer;
use App\Actions\Ai\Core\ToolRun;
use App\Actions\Ai\Core\ToolRunAnswer;
use App\Actions\Ai\Core\ToolRunner;
use Laravel\Ai\Contracts\Tool;
use LogicException;

/**
 * 道具の段(中核の順の正本 = docs の 設計/AI/会話の中核の口と受け手.md 13.3 の道具を動かす口、回数の上限 =
 * 設計/AI/エージェントの実行/途中の表示と上限.md 9.7)。
 */
final readonly class SubstituteTools
{
    /**
     * @param  array<string, Tool>  $given  渡す道具(送る名前 → 道具。組に分けるのに使う)
     */
    public function __construct(
        private ToolRunner $tools,
        private CoreChecker $checker,
        private SubstituteState $state,
        private SubstituteStops $stops,
        private array $given,
        private ParallelTools $parallel,
    ) {}

    /**
     * 呼び出しを動かし、道具の後まで進める(止めた・確認待ち・回数の上限なら終わり方、次の回へ進むなら null)。
     *
     * @param  list<CoreToolCall>  $calls
     * @param  bool  $preRun  先に動かす呼び出し(答えの実行の承認した呼び出し。回数に数えず、問うを返さない)
     *
     * @throws ConversationEnded 受け手が会話が無いを返したとき
     */
    public function run(array $calls, bool $preRun, int $maxTurns): ?LoopEnding
    {
        $asking = [];
        $groups = $this->parallel->groups($calls, $this->given);

        foreach ($groups as $index => $group) {
            $later = array_merge(...array_map(
                static fn (ToolGroup $later): array => $later->calls,
                array_slice($groups, $index + 1),
            ));
            $ending = $group->parallel
                ? $this->ranGroup($group->calls, $later, $asking)
                : $this->ran($group->calls[0], $preRun, [$group->calls[0], ...$later], $asking);

            if ($ending !== null) {
                return $ending;
            }
        }

        $next = $preRun ? $this->state->turn : $this->state->turn + 1;

        return $this->afterTools($asking, $next > $maxTurns);
    }

    /**
     * @param  non-empty-list<CoreToolCall>  $rest  この呼び出しと残りの呼び出し
     * @param  list<string>  $asking
     */
    private function ran(CoreToolCall $call, bool $preRun, array $rest, array &$asking): ?LoopEnding
    {
        $lookup = $this->tools->find($call, $preRun);

        if ($lookup->answer === ToolLookupAnswer::NoConversation) {
            throw new ConversationEnded;
        }

        if ($lookup->missingResult !== null) {
            $this->state->addToolResult($lookup->missingResult);

            return null;
        }

        $reply = SubstituteChecks::ask($this->checker, CheckRequest::at(CheckPoint::BeforeTool, $this->state->kind));

        if ($reply->stop !== null) {
            $left = array_map(static fn (CoreToolCall $call): string => $call->callId, $rest);
            $this->stops->beforeTool($reply->stop, [...$asking, ...$left]);

            return LoopEnding::stopped(LoopStop::fromStopKind($reply->stop), $reply->reason);
        }

        $this->recorded($call, $this->tools->run($call, $preRun), $preRun, $asking);

        return null;
    }

    /**
     * 並べてよい道具の組: 呼び出しの順に探し、組の前に 1 回だけ確かめてから並べて動かす(docs の 設計/AI/会話の中核の口と受け手.md 13.3)。
     *
     * @param  non-empty-list<CoreToolCall>  $calls
     * @param  list<CoreToolCall>  $later  後の組の呼び出し
     * @param  list<string>  $asking
     */
    private function ranGroup(array $calls, array $later, array &$asking): ?LoopEnding
    {
        $found = array_values(array_filter($calls, $this->found(...)));

        if ($found === []) {
            return null;
        }

        $reply = SubstituteChecks::ask($this->checker, CheckRequest::at(CheckPoint::BeforeTool, $this->state->kind));

        if ($reply->stop !== null) {
            $left = array_map(static fn (CoreToolCall $call): string => $call->callId, [...$found, ...$later]);
            $this->stops->beforeTool($reply->stop, [...$asking, ...$left]);

            return LoopEnding::stopped(LoopStop::fromStopKind($reply->stop), $reply->reason);
        }

        $runs = $this->tools->runParallel($found, $this->parallel->concurrency);

        if ($runs->conversationGone) {
            throw new ConversationEnded;
        }

        if (count($runs->runs) !== count($found)) {
            throw new LogicException('並べて動かした組の結果の数が呼び出しの数と違う');
        }

        foreach ($found as $index => $call) {
            $this->recorded($call, $runs->runs[$index], false, $asking);
        }

        return null;
    }

    /** 探す(無い道具は結果を足して false) */
    private function found(CoreToolCall $call): bool
    {
        $lookup = $this->tools->find($call, false);

        if ($lookup->answer === ToolLookupAnswer::NoConversation) {
            throw new ConversationEnded;
        }

        if ($lookup->missingResult !== null) {
            $this->state->addToolResult($lookup->missingResult);

            return false;
        }

        return true;
    }

    /**
     * 動かした結果を状態に入れる。
     *
     * @param  list<string>  $asking
     */
    private function recorded(CoreToolCall $call, ToolRun $run, bool $preRun, array &$asking): void
    {
        match ($run->answer) {
            ToolRunAnswer::NoConversation => throw new ConversationEnded,
            ToolRunAnswer::Asks => $preRun
                ? throw new LogicException("先に動かす呼び出し {$call->callId} に問うが返った")
                : $asking[] = $call->callId,
            ToolRunAnswer::Result => $this->state->addToolResult(
                $run->result ?? throw new LogicException("呼び出し {$call->callId} の結果が無い"),
            ),
        };

        if ($run->lastSendFinishedAt !== null) {
            $this->state->thinking->completed($run->lastSendFinishedAt);
        }
    }

    /**
     * 道具の後(回数の上限 = docs の 設計/AI/エージェントの実行/途中の表示と上限.md 9.7)。
     *
     * @param  list<string>  $asking
     */
    private function afterTools(array $asking, bool $overTurns): ?LoopEnding
    {
        $reply = SubstituteChecks::at($this->checker, CheckPoint::AfterTools, $this->state->kind);

        if ($reply->stop !== null) {
            $this->stops->afterTools($asking, $overTurns);

            return LoopEnding::stopped(LoopStop::fromStopKind($reply->stop), $reply->reason);
        }

        if ($asking !== []) {
            return LoopEnding::awaitingConfirmation($asking);
        }

        if ($overTurns) {
            $this->state->append([ConversationItem::turnLimit()]);

            return LoopEnding::stopped(LoopStop::TurnLimit);
        }

        return null;
    }
}
