<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\CompactThresholds;
use App\Actions\Ai\Core\LoopEnding;
use App\Actions\Ai\Core\LoopOutcome;
use App\Actions\Ai\Core\LoopReceivers;
use App\Actions\Ai\Core\LoopRequest;
use App\Actions\Ai\Core\LoopStop;
use App\Actions\Ai\Core\SendReceivers;
use App\Enums\Ai\AiInstructionTemplate;

/**
 * 代わりの版のループ(回の順の正本 = docs の 設計/AI/エージェントの実行/README.md 9.1、要約を試すのをやめる数 = 設計/AI/エージェントの実行/途中の表示と上限.md 9.9)。
 * 知らせの口は返り値の確かめで包み、ループの中のどの知らせも同じ確かめを通す。
 */
final class SubstituteLoop
{
    /** 要約を試すのをやめる、続いた失敗の数 */
    private const COMPACT_FAILURES = 3;

    private readonly LoopRequest $request;

    private readonly SubstituteState $state;

    private readonly CompactThresholds $thresholds;

    private readonly LoopPlans $plans;

    private readonly SubstituteTools $tools;

    private readonly SubstituteCompaction $compaction;

    private readonly SubstituteTurn $turn;

    public function __construct(LoopRequest $given)
    {
        $thinking = new SubstituteThinking($given->thinking);
        $request = self::checked($given, $thinking);
        $receivers = $request->receivers;
        $this->request = $request;
        $this->state = new SubstituteState($receivers->notifier, $request->history, $thinking);
        $this->state->turn = $request->priorTurns + 1;
        $this->thresholds = SubstituteAmounts::of($request->target->model);
        $toolNames = SubstitutePlans::toolNames($request->tools);
        $this->plans = new LoopPlans($request, $this->thresholds, $toolNames, $thinking);
        $stops = new SubstituteStops($this->state);
        $this->tools = new SubstituteTools(
            $receivers->tools,
            $receivers->checker,
            $this->state,
            $stops,
            array_combine($toolNames, $request->tools),
            $request->parallelTools,
        );
        $sender = SubstituteConversationCore::sender(
            new SendReceivers($receivers->checker, $receivers->notifier, $receivers->clock, $receivers->texts),
            $request->target->traits,
            $request->progressSeconds,
        );
        $this->compaction = new SubstituteCompaction($receivers, $this->state, $stops, $sender, $this->plans);
        $this->turn = new SubstituteTurn($request, $this->state, $stops, $sender, $this->tools);
    }

    public function run(): LoopOutcome
    {
        try {
            $ending = $this->ran();
        } catch (ConversationEnded) {
            $ending = LoopEnding::stopped(LoopStop::User);
        }

        return new LoopOutcome($ending, $this->state->history);
    }

    private function ran(): LoopEnding
    {
        if ($this->request->preRunCalls !== [] || $this->request->priorTurns > 0) {
            $ending = $this->tools->run($this->request->preRunCalls, true, $this->request->maxTurns);

            if ($ending !== null) {
                return $ending;
            }
        }

        while (true) {
            $ending = $this->turned();

            if ($ending !== null) {
                return $ending;
            }

            $this->state->turn++;
        }
    }

    /** 1 回(終われば終わり方、次の回へ進むなら null) */
    private function turned(): ?LoopEnding
    {
        $added = $this->pendingAdded();
        $plan = $this->plans->turn([...$this->state->history, ...$added]);
        $estimate = $plan->estimate;
        $compacts = $estimate > $this->thresholds->compactAt && $this->state->compactFailures < self::COMPACT_FAILURES;

        if ($compacts) {
            $ending = $this->compaction->run($estimate);

            if ($ending !== null) {
                return $ending;
            }

            $added = $this->pendingAdded();
        }

        if ($added !== []) {
            $this->state->append($added);
            $this->state->unknownWritesAdded = $this->state->unknownWritesAdded || self::hasUnknownWrites($added);
        }

        if ($compacts) {
            $plan = $this->plans->turn($this->state->history);
        }

        if ($plan->estimate > $this->thresholds->stopAt) {
            return LoopEnding::stopped(LoopStop::Length);
        }

        return $this->turn->sent($plan);
    }

    /**
     * この位置で足す項目(日付が変わった知らせと、まだ足していない結果の分からない書き込みの知らせ = docs の 設計/AI/会話の中核.md 13.2)。
     *
     * @return list<ConversationItem>
     */
    private function pendingAdded(): array
    {
        $values = $this->request->addedTexts;
        $today = SubstituteDates::today($this->request->receivers->clock->now());
        $items = [];

        if ($today !== SubstituteDates::lastNotified($this->state->history, $values->firstRunDate)) {
            $items[] = ConversationItem::addedText(AiInstructionTemplate::DateChange, ['new_date' => $today], []);
        }

        if (! $this->state->unknownWritesAdded && ($values->unknownWrites['unknown_calls'] ?? []) !== []) {
            $items[] = ConversationItem::addedText(
                AiInstructionTemplate::UnknownOutcomeNotice,
                $values->unknownWrites,
                $values->unknownWriteCallIds,
            );
        }

        return $items;
    }

    /**
     * @param  list<ConversationItem>  $items
     */
    private static function hasUnknownWrites(array $items): bool
    {
        foreach ($items as $item) {
            if ($item->content['template'] === AiInstructionTemplate::UnknownOutcomeNotice->value) {
                return true;
            }
        }

        return false;
    }

    /** 知らせの口を返り値の確かめと送信の成功の時刻の受け取りで包んだ受け取る物(ループの中のどの知らせも同じ所を通す) */
    private static function checked(LoopRequest $request, SubstituteThinking $thinking): LoopRequest
    {
        $given = $request->receivers;

        return new LoopRequest(
            $request->target,
            $request->history,
            $request->tools,
            $request->parallelTools,
            $request->stream,
            $request->providerOptions,
            $request->maxTurns,
            $request->priorTurns,
            $request->progressSeconds,
            $request->preRunCalls,
            $request->addedTexts,
            $request->thinking,
            new LoopReceivers(
                $given->tools,
                new CheckedNotices(new ThinkingNotices($given->notifier, $thinking, $given->clock)),
                $given->checker,
                $given->clock,
                $given->texts,
                $given->createdFiles,
            ),
        );
    }
}
