<?php

namespace Tests\Feature\Meeting;

use App\Queries\Meeting\MeetingScreenAddons;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 「音声から作成」と「再作成」に渡す音声の値(docs の 設計/業務ツール/会議/公開デモと画面.md の 9.2・9.3、区分 = 処理.md の 3.9):
 * 音声 1 本の上限(添付の土台の値)と、音声の取り込みの先の止め方の文。
 */
final class MeetingAudioBlockedMessageTest extends TransactionalTestCase
{
    use UsesMeetings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMeetings();
    }

    /**
     * @return array<string, array{string|null, string|null, string|null, string|null}>
     */
    public static function budgetStates(): array
    {
        $minutesDay = '今日の AI の利用の上限に近いため、';
        $transcriptionDay = '今日の音声の取り込みの上限に近いため、';

        return [
            '受けられる' => [null, null, null, null],
            '議事録の区分(日)' => ['day', null, $minutesDay.'音声は選べません。', $minutesDay.'音声から作り直せません。'],
            '議事録の区分(月)' => [
                'month', null, '今月の AI の利用の上限に近いため、音声は選べません。', '今月の AI の利用の上限に近いため、音声から作り直せません。',
            ],
            '議事録の区分(累計)' => [
                'total', null, 'AI の利用の上限に近いため、音声は選べません。', 'AI の利用の上限に近いため、音声から作り直せません。',
            ],
            '文字起こしの区分だけ(日)' => [
                null, 'day', $transcriptionDay.'音声は選べません。テキストの取り込みは使用できます。', $transcriptionDay.'音声から作り直せません。',
            ],
            '文字起こしの区分だけ(月)' => [
                null, 'month', '今月の音声の取り込みの上限に近いため、音声は選べません。テキストの取り込みは使用できます。',
                '今月の音声の取り込みの上限に近いため、音声から作り直せません。',
            ],
            '両方' => ['day', 'day', $minutesDay.'音声は選べません。', $minutesDay.'音声から作り直せません。'],
        ];
    }

    /**
     * 音声の取り込みの先の止め方の文(議事録の区分を先に見る。期間で言い換える)を、一覧の「音声から作成」と、書き起こしが無く受ける音声のある
     * 会議の「再作成」に渡す。書き起こしのある会議の「再作成」には渡さない。
     */
    #[DataProvider('budgetStates')]
    public function test_the_audio_blocked_message_follows_the_budget_states(
        ?string $minutes,
        ?string $transcription,
        ?string $createMessage,
        ?string $regenerateMessage,
    ): void {
        $creator = $this->user();
        $audio = $this->audioMeeting($creator);
        $this->completeTranscription($this->lastRunIdOf($audio), []);
        $pasted = $this->pastedMeeting($creator);
        config([
            'ai_runs.budget.default' => self::limit((string) $minutes),
            'ai_runs.budget.transcription' => self::limit((string) $transcription),
        ]);
        $context = $this->contextOf($creator);
        $addons = app(MeetingScreenAddons::class);
        $create = $addons->index($context)['actions'][0]['props'];

        $this->assertSame([99_614_720, $createMessage], [$create['audioMaxBytes'], $create['audioBlockedMessage']]);
        $regenerate = $addons->show($context, $audio)['actions'][0]['props'];
        $this->assertSame($regenerateMessage, $regenerate['audioBlockedMessage']);
        $this->assertNull($addons->show($context, $pasted)['actions'][0]['props']['audioBlockedMessage']);
    }

    /**
     * 区分の上限(日・月・累計の 1 つだけを小さくする)。
     *
     * @return array{daily_usd: float|null, monthly_usd: float|null, total_usd: float|null}
     */
    private static function limit(string $period): array
    {
        return [
            'daily_usd' => $period === 'day' ? 0.000001 : null,
            'monthly_usd' => $period === 'month' ? 0.000001 : null,
            'total_usd' => $period === 'total' ? 0.000001 : null,
        ];
    }
}
