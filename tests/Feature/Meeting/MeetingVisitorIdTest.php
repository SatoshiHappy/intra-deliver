<?php

namespace Tests\Feature\Meeting;

use App\Models\Ai\AiRun;
use App\Models\Master\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TransactionalTestCase;

/**
 * AI の依頼の口を呼ぶ会議の HTTP の口(docs の 設計/AI/データと失敗と検査.md 8 節の「依頼の口を呼ぶ HTTP の口」と「2 段目の訪問者の ID」):
 * 作成・再作成のルートは GET・HEAD でない。X-Visitor-Id に UUID を付けた作成・再作成から作られた実行の記録はその ID を持ち、
 * 文字起こしの完了の中の 2 段目の依頼(議事録)も同じ ID を持つ。
 */
final class MeetingVisitorIdTest extends TransactionalTestCase
{
    use UsesMeetings;

    private const VISITOR_ID = '0b9e8f3a-6c2d-4d1e-9a7b-3f5c1e2d4a6b';

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMeetings();
        $this->creator = $this->user();
    }

    public function test_the_routes_that_request_the_ai_are_not_get_or_head(): void
    {
        foreach (['meeting.store', 'meeting.regenerate'] as $name) {
            $methods = Route::getRoutes()->getByName($name)?->methods() ?? ['GET'];

            $this->assertSame([], array_values(array_intersect($methods, ['GET', 'HEAD'])), $name);
        }
    }

    public function test_a_create_from_a_text_writes_the_visitor_id_to_the_minutes_run(): void
    {
        $this->postWithVisitorId('/meetings', ['text' => '[10:00] 佐藤: はじめます'])->assertSessionHasNoErrors();

        $this->assertSame(self::VISITOR_ID, $this->lastAiRunOf($this->latestMeetingId())->visitor_id);
    }

    public function test_a_create_from_an_audio_writes_the_visitor_id_to_the_transcription_and_the_minutes(): void
    {
        $this->postWithVisitorId('/meetings', ['file' => $this->audioFile('定例.mp3')])->assertSessionHasNoErrors();
        $recordId = $this->latestMeetingId();
        $transcription = $this->lastAiRunOf($recordId);

        $this->completeTranscription($transcription->id, [['はじめます', 'A', 0.5, 1.0]]);

        $minutes = $this->lastAiRunOf($recordId);
        $this->assertNotSame($transcription->id, $minutes->id);
        $this->assertSame([self::VISITOR_ID, self::VISITOR_ID], [$transcription->visitor_id, $minutes->visitor_id]);
    }

    public function test_a_regenerate_writes_the_visitor_id_of_its_request_to_the_new_run(): void
    {
        $recordId = $this->pastedMeeting($this->creator);
        $this->completeMinutes($this->lastRunIdOf($recordId));

        $this->postWithVisitorId("/meetings/{$recordId}/regenerate", [])->assertSessionHasNoErrors();

        $runs = AiRun::query()->where('record_id', $recordId)->orderBy('id')->pluck('visitor_id')->all();
        $this->assertSame([null, self::VISITOR_ID], $runs);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return TestResponse<Response>
     */
    private function postWithVisitorId(string $uri, array $input): TestResponse
    {
        return $this->actingAs($this->creator)->from('/records/data/meeting')
            ->withHeader('X-Visitor-Id', self::VISITOR_ID)->post($uri, $input);
    }

    private function lastAiRunOf(int $recordId): AiRun
    {
        return AiRun::query()->findOrFail($this->lastRunIdOf($recordId));
    }
}
