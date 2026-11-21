<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\CompactThresholds;
use App\Actions\Ai\Core\LoopRequest;
use App\Actions\Ai\Core\SendKind;
use App\Actions\Ai\Core\TransientText;

/**
 * ループの送信を組む(回の答えと要約。先頭の日付の文は指示の組み立ての口が返す先頭の文に入る = docs の 設計/AI/会話の中核.md 13.2)。
 * 送る量の上限 = 回の答えは止める量、要約は読める量(設計/AI/会話の中核の口と受け手.md 13.3)。
 */
final readonly class LoopPlans
{
    /** 道具の定義の字数(実行の中で変わらないので 1 回だけ数える) */
    private int $toolChars;

    /**
     * @param  list<string>  $toolNames
     */
    public function __construct(
        private LoopRequest $request,
        private CompactThresholds $thresholds,
        private array $toolNames,
        private SubstituteThinking $thinking,
    ) {
        $this->toolChars = SubstitutePlans::toolChars($request->tools);
    }

    /**
     * 回の答えの送信。
     *
     * @param  list<ConversationItem|TransientText>  $history
     */
    public function turn(array $history): SubstitutePlan
    {
        return $this->plan(SendKind::Turn, $history, true, $this->thresholds->stopAt);
    }

    /**
     * 要約の送信(同じ接続先・モデル・道具の定義で、要約の依頼の文を末尾に置く = 設計/AI/エージェントの実行/途中の表示と上限.md 9.9)。
     *
     * @param  list<ConversationItem|TransientText>  $history
     */
    public function compaction(array $history): SubstitutePlan
    {
        $window = SubstituteAmounts::window($this->request->target->model);

        return $this->plan(SendKind::Compaction, $history, false, $window);
    }

    /**
     * @param  list<ConversationItem|TransientText>  $history
     */
    private function plan(SendKind $kind, array $history, bool $writesCache, int $sendLimit): SubstitutePlan
    {
        $request = $this->request;
        $texts = $request->receivers->texts;
        $date = SubstituteDates::head($history, $request->addedTexts->firstRunDate);
        $composed = $texts->instructions($kind, $this->toolNames, $date);
        $messages = (new SentHistory($texts, $request->target))->build($history, $composed->atStart, $composed->atEnd);

        return SubstitutePlans::of(new SubstituteParts(
            $request->target,
            $kind,
            $composed->system,
            $messages,
            $request->tools,
            null,
            $request->stream,
            [
                ...$request->providerOptions[$kind->value] ?? [],
                ...$this->thinking->options($request->target, $kind, $request->receivers->clock->now()),
            ],
            $writesCache,
            $request->target->model->maxOutputTokens,
            $sendLimit,
            $this->toolChars,
        ));
    }
}
