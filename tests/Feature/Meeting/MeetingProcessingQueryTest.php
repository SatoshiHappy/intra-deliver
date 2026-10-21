<?php

namespace Tests\Feature\Meeting;

use App\Enums\Meeting\MeetingProcessingState;
use App\Models\Ai\AiRun;
use App\Models\Meeting\MeetingRun;
use App\Models\Records\Record;
use App\Queries\Meeting\MeetingProcessingQuery;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 会議の処理の状態(docs の 設計/業務ツール/会議/処理.md の 3.8): 最後の対応(id が最大の行)と、その実行の状態から読む。
 */
final class MeetingProcessingQueryTest extends TransactionalTestCase
{
    /**
     * @return array<string, array{Closure(int): MeetingRun, MeetingProcessingState|null}>
     */
    public static function lastRuns(): array
    {
        $with = fn (Closure $run) => fn (int $recordId) => MeetingRun::factory()
            ->create(['record_id' => $recordId, 'ai_run_id' => $run()->id]);
        $declined = fn (int $recordId) => MeetingRun::factory()->declined()->create(['record_id' => $recordId]);

        return [
            '断られた対応' => [$declined, MeetingProcessingState::Failed],
            '実行が失敗' => [$with(fn () => AiRun::factory()->failed()->create()), MeetingProcessingState::Failed],
            '実行が待ち' => [$with(fn () => AiRun::factory()->create()), MeetingProcessingState::Processing],
            '実行が処理中' => [$with(fn () => AiRun::factory()->running()->create()), MeetingProcessingState::Processing],
            '実行が成功' => [$with(fn () => AiRun::factory()->succeeded()->create()), null],
        ];
    }

    /**
     * @param  Closure(int): MeetingRun  $lastRun
     */
    #[DataProvider('lastRuns')]
    public function test_the_state_follows_only_the_last_run(Closure $lastRun, ?MeetingProcessingState $expected): void
    {
        $recordId = Record::factory()->create()->id;
        $running = AiRun::factory()->running()->create();
        MeetingRun::factory()->create(['record_id' => $recordId, 'ai_run_id' => $running->id]);
        MeetingRun::factory()->declined()->create(['record_id' => $recordId]);
        $lastRun($recordId);

        $states = app(MeetingProcessingQuery::class)->statesOf([$recordId]);

        $this->assertSame($expected === null ? [] : [$recordId => $expected], $states);
    }

    public function test_a_meeting_without_runs_has_no_state_and_an_empty_list_reads_nothing(): void
    {
        $recordId = Record::factory()->create()->id;

        $this->assertSame([], app(MeetingProcessingQuery::class)->statesOf([$recordId]));
        $this->assertSame([], app(MeetingProcessingQuery::class)->statesOf([]));
    }

    public function test_reads_the_states_of_several_meetings_at_once(): void
    {
        [$failed, $processing, $done] = array_map(fn () => Record::factory()->create()->id, range(1, 3));
        MeetingRun::factory()->declined()->create(['record_id' => $failed]);
        MeetingRun::factory()->create(['record_id' => $processing]);
        $succeeded = AiRun::factory()->succeeded()->create();
        MeetingRun::factory()->create(['record_id' => $done, 'ai_run_id' => $succeeded->id]);

        $states = app(MeetingProcessingQuery::class)->statesOf([$failed, $processing, $done]);

        $this->assertSame(
            [$failed => MeetingProcessingState::Failed, $processing => MeetingProcessingState::Processing],
            $states,
        );
    }
}
