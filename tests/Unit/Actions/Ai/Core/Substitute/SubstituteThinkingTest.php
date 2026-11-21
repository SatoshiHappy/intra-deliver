<?php

namespace Tests\Unit\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Core\ModelSpec;
use App\Actions\Ai\Core\ProviderTraits;
use App\Actions\Ai\Core\SendKind;
use App\Actions\Ai\Core\SendTarget;
use App\Actions\Ai\Core\Substitute\SubstituteThinking;
use App\Actions\Ai\Core\ThinkingStart;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\Actions\Ai\Core\Support\CoreTestCase;
use Tests\Unit\Actions\Ai\Core\Support\FakeStepGateway;

/**
 * 代わりの版の思考の文脈の管理の旗(docs の 設計/AI/会話の中核.md 13.2): 付ける送信・回の答えの 1 時間の境(ちょうど・1 秒多い)・始めの値・
 * 要約は比べない・組んだだけでは旗を立てない・境目で下ろす・最後の完了の時刻は戻らない。
 */
final class SubstituteThinkingTest extends CoreTestCase
{
    private const ALL = ['context_management' => ['edits' => [['type' => 'clear_thinking_20251015', 'keep' => 'all']]]];

    private const LAST = ['context_management' => ['edits' => [[
        'type' => 'clear_thinking_20251015',
        'keep' => ['type' => 'thinking_turns', 'value' => 1],
    ]]]];

    private const DONE = '2026-11-18T12:00:00+09:00';

    private const LATER = '2026-11-18T20:00:00+09:00';

    private function managed(bool $usesThinking = true, ?string $pattern = '/^claude-/'): SendTarget
    {
        $base = $this->target(new FakeStepGateway([]), new ProviderTraits(contextManagementModels: $pattern));
        $model = new ModelSpec('claude-x', 200000, 32000, null, $usesThinking);

        return new SendTarget($base->provider, 'anthropic', $model, $base->traits, 60);
    }

    private static function thinking(bool $idled = false, ?string $done = self::DONE): SubstituteThinking
    {
        return new SubstituteThinking(new ThinkingStart($idled, $done === null ? null : new DateTimeImmutable($done)));
    }

    /**
     * @return array<string, mixed>
     */
    private function options(SubstituteThinking $thinking, SendKind $kind, string $now): array
    {
        return $thinking->options($this->managed(), $kind, new DateTimeImmutable($now));
    }

    /**
     * @return array<string, array{bool, string|null, string, array<string, mixed>}>
     */
    public static function turns(): array
    {
        return [
            '1 時間ちょうどは残す' => [false, self::DONE, '2026-11-18T13:00:00+09:00', self::ALL],
            '1 時間と 1 秒は最後の 1 回' => [false, self::DONE, '2026-11-18T13:00:01+09:00', self::LAST],
            '1 時間と 0.6 秒は切り捨てて 1 時間' => [false, self::DONE, '2026-11-18T13:00:00.600000+09:00', self::ALL],
            '始めの旗は立ったまま' => [true, self::DONE, '2026-11-18T12:00:01+09:00', self::LAST],
            '前の完了が無ければ残す' => [false, null, self::LATER, self::ALL],
        ];
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    #[DataProvider('turns')]
    public function test_a_turn_compares_the_gap_from_the_last_completion(
        bool $idled,
        ?string $done,
        string $now,
        array $expected,
    ): void {
        $thinking = self::thinking($idled, $done);

        $this->assertSame($expected, $this->options($thinking, SendKind::Turn, $now));
        $thinking->sending();
        $this->assertSame($expected, $this->options($thinking, SendKind::Compaction, $now));
    }

    /** 要約は比べず、それまでの旗で送る。組んだだけの回の答えは旗を立てない(組んだ後に要約へ進んだ形) */
    public function test_compaction_does_not_compare_and_a_planned_turn_does_not_raise(): void
    {
        $thinking = self::thinking();
        $this->assertSame(self::ALL, $this->options($thinking, SendKind::Compaction, self::LATER));

        $this->assertSame(self::LAST, $this->options($thinking, SendKind::Turn, self::LATER));
        $this->assertSame(self::ALL, $this->options($thinking, SendKind::Compaction, self::LATER));
    }

    /** 境目で下ろし、成功した送信の時刻から比べ直す(前の時刻へは戻らない) */
    public function test_a_boundary_lowers_and_completions_move_forward(): void
    {
        $thinking = self::thinking(true);
        $thinking->compacted();
        $thinking->completed(new DateTimeImmutable('2026-11-18T19:30:00+09:00'));
        $thinking->completed(new DateTimeImmutable('2026-11-18T09:00:00+09:00'));

        $this->assertSame(self::ALL, $this->options($thinking, SendKind::Turn, self::LATER));
        $this->assertSame(self::LAST, $this->options($thinking, SendKind::Turn, '2026-11-18T20:30:01+09:00'));
    }

    public function test_it_is_not_added_outside_managed_thinking_sends(): void
    {
        $now = new DateTimeImmutable(self::LATER);
        $thinking = self::thinking(true);

        $this->assertSame([], $thinking->options($this->managed(false), SendKind::Turn, $now));
        $this->assertSame([], $thinking->options($this->managed(true, null), SendKind::Turn, $now));
        $this->assertSame([], $thinking->options($this->managed(true, '/^gpt-/'), SendKind::Turn, $now));
        $this->assertSame([], $thinking->options($this->managed(), SendKind::Title, $now));
    }
}
