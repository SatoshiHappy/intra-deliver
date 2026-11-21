<?php

namespace Tests\Unit\Actions\Ai\Core\Contract;

use App\Actions\Ai\Core\ProviderTraits;
use App\Actions\Ai\Core\ThinkingStart;
use DateTimeImmutable;
use Laravel\Ai\Responses\Data\FinishReason;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\Actions\Ai\Core\Support\FakeClock;
use Tests\Unit\Actions\Ai\Core\Support\FakeStepGateway;

/**
 * 思考の文脈の管理の旗の約束(docs の 設計/AI/会話の中核.md 13.2): 始めの値(旗・最後の完了の時刻と 1 時間の境)・実行の中の成功した送信と
 * 道具の中の送信の完了で比べ直すこと。両方の版で同じ結果になる所だけを置く(要約の境目で下ろすのは版ごとの要約のテスト)。
 */
abstract class ThinkingContractTestCase extends CoreContractTestCase
{
    private const ALL = ['type' => 'clear_thinking_20251015', 'keep' => 'all'];

    private const LAST = ['type' => 'clear_thinking_20251015', 'keep' => ['type' => 'thinking_turns', 'value' => 1]];

    /**
     * @return array<string, array{bool, string|null, array<string, mixed>}>
     */
    public static function starts(): array
    {
        return [
            '始めの旗' => [true, '2026-11-18T11:59:00+09:00', self::LAST],
            '1 時間と 1 秒前の完了' => [false, '2026-11-18T10:59:59+09:00', self::LAST],
            '1 時間ちょうど前の完了' => [false, '2026-11-18T11:00:00+09:00', self::ALL],
            '前の完了が無い' => [false, null, self::ALL],
        ];
    }

    /**
     * 時計の今 = 2026-11-18T12:00:00+09:00。
     *
     * @param  array<string, mixed>  $expected
     */
    #[DataProvider('starts')]
    public function test_the_first_turn_follows_the_start_values(bool $idled, ?string $done, array $expected): void
    {
        $start = new ThinkingStart($idled, $done === null ? null : new DateTimeImmutable($done));

        $this->loop([FakeStepGateway::response('答え')], traits: self::traits(), thinking: $start, thinks: true);

        $this->assertSame([$expected], $this->edits());
    }

    /**
     * 回の答えの成功(12:00)の後に道具が 2 時間動く。道具の中の送信の完了(13:59:30)を受ければ次の回の答え(14:00)は残し、受けなければ
     * 最後の 1 回にする。
     *
     * @return array<string, array{string|null, array<string, mixed>}>
     */
    public static function toolCompletions(): array
    {
        return [
            '道具の中の完了を受ける' => ['2026-11-18T13:59:30+09:00', self::ALL],
            '道具の中の送信が無い' => [null, self::LAST],
        ];
    }

    /**
     * @param  array<string, mixed>  $second
     */
    #[DataProvider('toolCompletions')]
    public function test_completions_inside_the_run_move_the_comparison(?string $inside, array $second): void
    {
        $clock = new FakeClock;
        $during = static function () use ($clock, $inside): ?DateTimeImmutable {
            $clock->wait(7_200_000);

            return $inside === null ? null : new DateTimeImmutable($inside);
        };

        $this->loop(
            [
                FakeStepGateway::response('', FinishReason::ToolCalls, [self::call('c1')]),
                FakeStepGateway::response('答え'),
            ],
            traits: self::traits(),
            clock: $clock,
            thinks: true,
            during: $during,
        );

        $this->assertSame([self::ALL, $second], $this->edits());
    }

    private static function traits(): ProviderTraits
    {
        return new ProviderTraits(contextManagementModels: '/^claude-/');
    }

    /**
     * 送った試しごとの思考の文脈の管理の指定。
     *
     * @return list<mixed>
     */
    private function edits(): array
    {
        return array_map(
            static fn (array $sent): mixed => $sent['options']['context_management']['edits'][0] ?? null,
            $this->gateway->sent,
        );
    }
}
