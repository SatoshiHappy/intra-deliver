<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Conversation\ProviderTraitsOf;
use App\Queries\Ai\ProviderTraitsSettings;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 接続先の性質の設定(docs の 設計/AI/会話の中核.md 13.6)と起動の時の確かめ(設計/AI/実行と費用/接続先と費用.md 5.4 の「接続先の性質の値の形」)。
 */
final class ProviderTraitsSettingsTest extends TestCase
{
    /** 設定の Anthropic の性質が、接続先の誤りの文の形を見分ける */
    public function test_the_anthropic_traits_read_the_error_texts(): void
    {
        $traits = app(ProviderTraitsOf::class)->of('anthropic', 'claude-sonnet-5-5');

        $flags = [$traits->cachesInstructions, $traits->cachesHistory, $traits->estimateFactor];
        $this->assertSame([true, true, 2.0], $flags);
        $tooLong = 'prompt is too long: 137500 tokens > 135000 maximum';
        $this->assertSame(1, preg_match($traits->tooLongPatterns[0], $tooLong, $long));
        $this->assertSame(['137500', '135000'], [$long['actual'], $long['limit']]);
        $overflow = 'input length and `max_tokens` exceed context limit: 188059 + 20000 > 200000';
        $this->assertSame(1, preg_match((string) $traits->contextOverflowPattern, $overflow, $parts));
        $this->assertSame(['188059', '20000', '200000'], [$parts['input'], $parts['limit'], $parts['window']]);
        $this->assertSame(1, preg_match((string) $traits->contextManagementModels, 'claude-sonnet-5-5'));
    }

    public function test_the_configured_purposes_have_no_trait_problems(): void
    {
        $settings = app(ProviderTraitsSettings::class);

        $this->assertSame([], $settings->problems('anthropic', 'claude-sonnet-5-5'));
        $this->assertSame([], $settings->problems('anthropic', 'claude-haiku-5-5'));
    }

    /**
     * 値ごとに 1 つだけ合わないときに止まる。
     *
     * @return array<string, array{string, mixed}>
     */
    public static function wrongValues(): array
    {
        return [
            'キャッシュの印が真偽でない' => ['caches_history', 'yes'],
            '係数が無い' => ['estimate_factors', []],
            '係数が 0' => ['estimate_factors', ['claude-sonnet-5-5' => 0]],
            '句が正規表現でない' => ['too_long_patterns', ['not a pattern']],
            '句が並びでない' => ['too_long_patterns', 'x'],
            '見出しが空' => ['retry_header', ''],
            'モデルの並びが正規表現でない' => ['context_management_models', '('],
        ];
    }

    #[DataProvider('wrongValues')]
    public function test_a_wrong_value_is_a_problem(string $key, mixed $value): void
    {
        config(["ai_runs.conversation_providers.anthropic.{$key}" => $value]);

        $problems = app(ProviderTraitsSettings::class)->problems('anthropic', 'claude-sonnet-5-5');

        $this->assertCount(1, $problems);
        $this->expectException(LogicException::class);
        app(ProviderTraitsOf::class)->of('anthropic', 'claude-sonnet-5-5');
    }

    public function test_a_provider_without_traits_is_a_problem(): void
    {
        $this->assertCount(1, app(ProviderTraitsSettings::class)->problems('openai', 'gpt-x'));
    }
}
