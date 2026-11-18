<?php

namespace Tests\Unit\Actions\Ai\Core;

use App\Actions\Ai\Core\CheckPoint;
use App\Actions\Ai\Core\CheckReply;
use App\Actions\Ai\Core\CheckRequest;
use App\Actions\Ai\Core\LoopEnding;
use App\Actions\Ai\Core\LoopEndingKind;
use App\Actions\Ai\Core\LoopFailure;
use App\Actions\Ai\Core\LoopStop;
use App\Actions\Ai\Core\NoticeReply;
use App\Actions\Ai\Core\SendKind;
use App\Actions\Ai\Core\StopKind;
use App\Actions\Ai\Core\TransientText;
use App\Enums\Ai\AiInstructionTemplate;
use LogicException;

/**
 * 会話の中核の約束の値の型(docs の 設計/AI/会話の中核の口と受け手.md 13.3): 作り手が種類に当たる欄だけを持つこと、送る前の位置は量と一緒に
 * だけ作れること、中核の中の値の型の送る役(設計/AI/会話の中核.md 13.2)。
 */
final class CoreValuesTest extends EmptyContainerTestCase
{
    public function test_each_loop_ending_holds_only_the_fields_of_its_kind(): void
    {
        $asking = LoopEnding::awaitingConfirmation(['c1', 'c2']);
        $stopped = LoopEnding::stopped(LoopStop::CostLimit, '上限');
        $failed = LoopEnding::failed(LoopFailure::Refused);

        $this->assertSame([LoopEndingKind::Succeeded, [], null, null], [
            LoopEnding::succeeded()->kind, LoopEnding::succeeded()->askingCallIds, LoopEnding::succeeded()->stop,
            LoopEnding::succeeded()->failure,
        ]);
        $this->assertSame(
            [LoopEndingKind::AwaitingConfirmation, ['c1', 'c2'], null],
            [$asking->kind, $asking->askingCallIds, $asking->stop],
        );
        $this->assertSame([LoopEndingKind::Stopped, LoopStop::CostLimit, '上限', null], [
            $stopped->kind, $stopped->stop, $stopped->stopReason, $stopped->failure,
        ]);
        $this->assertSame(
            [LoopEndingKind::Failed, LoopFailure::Refused, null],
            [$failed->kind, $failed->failure, $failed->stop],
        );
    }

    public function test_every_stop_kind_maps_to_the_loop_stop_of_the_same_value(): void
    {
        foreach (StopKind::cases() as $kind) {
            $this->assertSame($kind->value, LoopStop::fromStopKind($kind)->value);
        }
    }

    public function test_a_check_request_before_sending_carries_the_amounts(): void
    {
        $request = CheckRequest::beforeSend(SendKind::Compaction, 4000, 2000, 180000);

        $this->assertSame([CheckPoint::BeforeSend, SendKind::Compaction, 4000, 2000, 180000], [
            $request->point, $request->kind, $request->chars, $request->outputLimit, $request->sendLimit,
        ]);
        $this->assertNull(CheckRequest::at(CheckPoint::AfterTools, SendKind::Turn)->chars);
    }

    public function test_the_point_before_sending_cannot_be_made_without_the_amounts(): void
    {
        $this->expectException(LogicException::class);

        CheckRequest::at(CheckPoint::BeforeSend, SendKind::Turn);
    }

    public function test_a_point_that_does_not_receive_the_send_kind_cannot_be_made(): void
    {
        $this->expectException(LogicException::class);

        CheckRequest::at(CheckPoint::WhileReading, SendKind::Title);
    }

    public function test_a_check_reply_stops_only_when_made_to_stop(): void
    {
        $toSend = CheckReply::proceedToSend(12);
        $toWait = CheckReply::proceedToWait(30000);
        $stop = CheckReply::stop(StopKind::User, '止める印');

        $this->assertSame([false, 12, null], [$toSend->stops(), $toSend->stepId, $toSend->waitLimitMs]);
        $this->assertSame([false, null, 30000], [$toWait->stops(), $toWait->stepId, $toWait->waitLimitMs]);
        $this->assertSame([true, StopKind::User, '止める印'], [$stop->stops(), $stop->stop, $stop->reason]);
    }

    public function test_notice_replies_tell_a_gone_conversation_from_duplicate_calls(): void
    {
        $accepted = NoticeReply::accepted();
        $duplicate = NoticeReply::duplicateCalls(['c1']);

        $this->assertSame([false, []], [$accepted->conversationGone, $accepted->duplicateCallIds]);
        $this->assertTrue(NoticeReply::noConversation()->conversationGone);
        $this->assertSame([false, ['c1']], [$duplicate->conversationGone, $duplicate->duplicateCallIds]);
    }

    /** 応答が無い旨の印だけがモデルの文として送られ、ほかは利用者の文として送られる */
    public function test_only_the_no_response_marker_is_sent_as_model_text(): void
    {
        $this->assertSame(
            [
                [AiInstructionTemplate::OutputContinuation, false],
                [AiInstructionTemplate::ResumeAfterCut, false],
                [AiInstructionTemplate::NoResponse, true],
                [AiInstructionTemplate::OldTurnsDropped, false],
            ],
            array_map(
                fn (TransientText $text): array => [$text->template, $text->isFromModel()],
                [
                    TransientText::outputContinuation(),
                    TransientText::resumeAfterCut(),
                    TransientText::noResponse(),
                    TransientText::oldTurnsDropped(),
                ],
            ),
        );
    }
}
