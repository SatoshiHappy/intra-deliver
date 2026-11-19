<?php

namespace Tests\Unit\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\ModelSpec;
use App\Actions\Ai\Core\ProviderTraits;
use App\Actions\Ai\Core\SendKind;
use App\Actions\Ai\Core\SendTarget;
use App\Actions\Ai\Core\Substitute\SubstituteThinking;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\Actions\Ai\Core\Support\CoreTestCase;
use Tests\Unit\Actions\Ai\Core\Support\FakeStepGateway;

/**
 * 代わりの版の思考の文脈の管理の指定(docs の 設計/AI/会話の中核.md 13.2): 付ける送信・残す値・1 時間の境。
 */
final class SubstituteThinkingTest extends CoreTestCase
{
    private const ALL = ['context_management' => ['edits' => [['type' => 'clear_thinking_20251015', 'keep' => 'all']]]];

    private const LAST = ['context_management' => ['edits' => [[
        'type' => 'clear_thinking_20251015',
        'keep' => ['type' => 'thinking_turns', 'value' => 1],
    ]]]];

    private function managed(bool $usesThinking = true, ?string $pattern = '/^claude-/'): SendTarget
    {
        $base = $this->target(new FakeStepGateway([]), new ProviderTraits(contextManagementModels: $pattern));
        $model = new ModelSpec('claude-x', 200000, 32000, null, $usesThinking);

        return new SendTarget($base->provider, 'anthropic', $model, $base->traits, 60);
    }

    private static function end(string $at): ConversationItem
    {
        $usage = [
            'input_tokens' => 1,
            'output_tokens' => 1,
            'cache_read_input_tokens' => null,
            'cache_write_input_tokens' => null,
            'reasoning_tokens' => null,
        ];

        $finished = new DateTimeImmutable($at);

        return ConversationItem::responseEnd('r', $usage, null, 'anthropic', 'claude-x', 1, [], $finished);
    }

    /**
     * @param  list<ConversationItem>  $history
     * @return array<string, mixed>
     */
    private function options(array $history, SendKind $kind, string $now, ?SendTarget $target = null): array
    {
        return SubstituteThinking::options($history, $target ?? $this->managed(), $kind, new DateTimeImmutable($now));
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function gaps(): array
    {
        return [
            '1 時間ちょうどは残す' => ['2026-11-18T13:00:00+09:00', self::ALL],
            '1 時間と 1 秒は最後の 1 回' => ['2026-11-18T13:00:01+09:00', self::LAST],
        ];
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    #[DataProvider('gaps')]
    public function test_the_kept_thinking_follows_the_gap_to_this_send(string $now, array $expected): void
    {
        $history = [self::end('2026-11-18T12:00:00+09:00')];

        $this->assertSame($expected, $this->options($history, SendKind::Turn, $now));
    }

    /** 要約は今の送信までを比べない。境目の後ろだけを見る */
    public function test_compaction_and_boundaries(): void
    {
        $later = '2026-11-18T20:00:00+09:00';
        $history = [self::end('2026-11-18T12:00:00+09:00')];
        $this->assertSame(self::ALL, $this->options($history, SendKind::Compaction, $later));

        $gap = [self::end('2026-11-18T08:00:00+09:00'), self::end('2026-11-18T12:00:00+09:00')];
        $this->assertSame(self::LAST, $this->options($gap, SendKind::Compaction, $later));

        $reset = [...$gap, ConversationItem::compactBoundary(1, '2026-11-18')];
        $this->assertSame(self::ALL, $this->options($reset, SendKind::Compaction, $later));
    }

    public function test_it_is_not_added_outside_managed_thinking_sends(): void
    {
        $now = '2026-11-18T12:00:00+09:00';

        $this->assertSame([], $this->options([], SendKind::Turn, $now, $this->managed(false)));
        $this->assertSame([], $this->options([], SendKind::Turn, $now, $this->managed(true, null)));
        $this->assertSame([], $this->options([], SendKind::Turn, $now, $this->managed(true, '/^gpt-/')));
        $this->assertSame([], $this->options([], SendKind::Title, $now));
    }
}
