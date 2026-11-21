<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\CheckPoint;
use App\Actions\Ai\Core\ConversationMarkers;
use App\Actions\Ai\Core\CreatedFileMark;
use App\Actions\Ai\Core\LoopEnding;
use App\Actions\Ai\Core\LoopReceivers;
use App\Actions\Ai\Core\LoopStop;
use App\Actions\Ai\Core\ResponseFinish;
use App\Actions\Ai\Core\SendKind;
use App\Enums\Ai\AiInstructionTemplate;
use LogicException;

/**
 * 代わりの版の要約(規則の正本 = docs の 設計/AI/エージェントの実行/途中の表示と上限.md 9.9 の代わりの版)。境目と要約の文を 1 つの知らせで書くのは、
 * 全部か無しかにするため(設計/AI/会話の中核の口と受け手.md 13.3)。
 */
final readonly class SubstituteCompaction
{
    public function __construct(
        private LoopReceivers $receivers,
        private SubstituteState $state,
        private SubstituteStops $stops,
        private SubstituteSender $sender,
        private LoopPlans $plans,
    ) {}

    /** 要約する(止めたら終わり方、済んだか失敗なら null。失敗は数える) */
    public function run(int $estimate): ?LoopEnding
    {
        $sink = new CompactionAnswerSink($this->receivers->notifier);
        $outcome = $this->sender->send($this->plans->compaction($this->state->history), $sink);

        if ($outcome->stop !== null) {
            return $this->stopped($outcome);
        }

        if ($outcome->response !== null) {
            $reply = SubstituteChecks::at($this->receivers->checker, CheckPoint::AfterReading, SendKind::Compaction);

            if ($reply->stop !== null) {
                $this->stops->whileReading([]);

                return LoopEnding::stopped(LoopStop::fromStopKind($reply->stop), $reply->reason);
            }
        }

        $finish = $outcome->response === null ? null : SubstituteUsage::finish($outcome->response->finishReason);
        $answer = $sink->answer();

        if ($answer === '' || ! in_array($finish, [ResponseFinish::Stop, ResponseFinish::ToolCalls], true)) {
            $this->state->compactFailures++;

            return null;
        }

        $stepId = $outcome->stepId ?? throw new LogicException('済んだ要約にステップの番号が無い');
        $this->state->compacted($this->items($answer, $estimate, $stepId));
        $this->state->compactFailures = 0;

        return null;
    }

    private function stopped(SubstituteOutcome $outcome): LoopEnding
    {
        $stop = $outcome->stop ?? throw new LogicException('止めた結びに区分が無い');

        if ($outcome->sent) {
            $this->stops->whileReading([]);
        } else {
            $this->stops->beforeSend($stop);
        }

        return LoopEnding::stopped(LoopStop::fromStopKind($stop), $outcome->stopReason);
    }

    /**
     * 境目と要約の文。
     *
     * @return non-empty-list<ConversationItem>
     */
    private function items(string $answer, int $estimate, int $stepId): array
    {
        $texts = $this->receivers->texts;
        $summary = $texts->compactSummary($answer);
        $forms = $texts->markerForms();
        $files = array_map(
            static fn (CreatedFileMark $file): string => ConversationMarkers::broken(
                "{$file->name} {$file->marker}",
                $forms,
            ),
            $this->receivers->createdFiles->forCompaction(),
        );

        if ($files !== []) {
            $list = $texts->template(AiInstructionTemplate::CreatedFilesAfterCompaction, ['created_files' => $files]);
            $summary .= "\n\n".$list;
        }

        return [
            ConversationItem::compactBoundary(
                $estimate,
                SubstituteDates::today($this->receivers->clock->now()),
                $stepId,
            ),
            ConversationItem::compactSummary($summary),
        ];
    }
}
