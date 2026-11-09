<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\ConversationKind;
use App\Actions\Ai\Api\PromptKind;
use App\Actions\Ai\PromptKinds;
use App\Queries\Ai\KindSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 会話の型の種類の登録と設定を起動の時の確かめで落とす(docs の 設計/AI/公開の口.md 3.1、設計/AI/データと失敗と検査.md 8 節、
 * 種類の設定 = 設計/AI/エージェントの実行/口と契約.md 9.13): コードは 3 つの型をまたいで一意・実装に設定がある・設定の型が実装の型と同じ。
 * 会話の型の種類の設定は区分・入力の上限・回数と時間の上限・用途ごとの呼び出しの時間の上限と出力の上限を持ち、接続先の要求に足す指定を持たず、
 * 区分が設定にある。合う設定では食い違いが無い(今の文章と文字起こしの分は PromptKindsTest・TranscriptionKindChecksTest)。
 */
final class ConversationKindChecksTest extends TransactionalTestCase
{
    use UsesTestConversationKinds;

    public function test_a_registered_conversation_kind_with_its_settings_has_no_problem(): void
    {
        $this->registerTestConversationKind();

        $this->assertSame([], app(PromptKinds::class)->problems());
        $kind = app(KindSettings::class)->conversation(RecordingConversationKind::CODE);
        $this->assertNotNull($kind);
        $values = [$kind['budget'], $kind['max_input_chars'], $kind['max_turns'], $kind['run_timeout_seconds']];
        $this->assertSame(['default', 1000, 10, 300], $values);
        $this->assertSame(['timeout_seconds' => 100, 'max_output_tokens' => 100], $kind['purposes']['title']);
        $this->assertNull(app(KindSettings::class)->prompt(RecordingConversationKind::CODE));
        $this->assertNull(app(KindSettings::class)->conversation('meeting.minutes'));
    }

    public function test_detects_the_same_code_across_the_prompt_and_conversation_kinds(): void
    {
        $this->app->tag([RecordingPromptKind::class], PromptKind::TAG);
        $this->app->tag([SameCodeConversationKind::class], ConversationKind::TAG);
        $this->configureKind(RecordingPromptKind::CODE, self::promptKindSettings());

        $this->assertSame([
            '種類 test.summary の実装が 2 つある',
            '種類 test.summary の設定の type が実装の型 conversation と違う(config/ai_runs.php の kinds)',
        ], app(PromptKinds::class)->problems());
    }

    public function test_detects_a_conversation_implementation_whose_code_is_not_in_the_settings(): void
    {
        $this->app->tag([RecordingConversationKind::class], ConversationKind::TAG);

        $this->assertSame(
            ['種類 test.conversation が設定(config/ai_runs.php の kinds)に無い'],
            app(PromptKinds::class)->problems(),
        );
    }

    public function test_detects_settings_whose_type_differs_from_the_implementation(): void
    {
        $this->app->tag([RecordingConversationKind::class], ConversationKind::TAG);
        $this->app->tag([RecordingPromptKind::class], PromptKind::TAG);
        $this->configureKind(RecordingConversationKind::CODE, self::promptKindSettings());
        $this->configureKind(RecordingPromptKind::CODE, self::conversationKindSettings());

        $this->assertSame([
            '種類 test.summary の設定の type が実装の型 prompt と違う(config/ai_runs.php の kinds)',
            '種類 test.conversation の設定の type が実装の型 conversation と違う(config/ai_runs.php の kinds)',
        ], app(PromptKinds::class)->problems());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function requiredKeys(): array
    {
        $keys = [];

        foreach (['budget', 'max_input_chars', 'max_turns', 'run_timeout_seconds'] as $key) {
            $keys[$key] = [$key, $key];
        }

        foreach (['conversation', 'title', 'create_file'] as $purpose) {
            foreach (['timeout_seconds', 'max_output_tokens'] as $key) {
                $keys["purposes.{$purpose}.{$key}"] = ["purposes.{$purpose}.{$key}", "purposes.{$purpose}.{$key}"];
            }
        }

        return [...$keys, '用途の全部' => ['purposes', implode('・', array_merge(...array_map(
            fn (string $purpose): array => [
                "purposes.{$purpose}.timeout_seconds",
                "purposes.{$purpose}.max_output_tokens",
            ],
            ['conversation', 'title', 'create_file'],
        )))]];
    }

    /** 必須の鍵の欠け(鍵ごと)。数の鍵は 0 も欠けとみなす(正の整数) */
    #[DataProvider('requiredKeys')]
    public function test_detects_each_missing_key_of_a_conversation_kind(string $path, string $listed): void
    {
        $settings = self::conversationKindSettings();
        $this->forget($settings, explode('.', $path));
        $this->configureKind(RecordingConversationKind::CODE, $settings);

        $this->assertSame(
            ["種類 test.conversation(conversation)の {$listed} が無いか形が合わない(config/ai_runs.php の kinds)"],
            app(PromptKinds::class)->problems(),
        );

        $this->configureKind(RecordingConversationKind::CODE, [...self::conversationKindSettings(), 'max_turns' => 0]);
        $this->assertSame(
            ['種類 test.conversation(conversation)の max_turns が無いか形が合わない(config/ai_runs.php の kinds)'],
            app(PromptKinds::class)->problems(),
        );
    }

    /** 会話の型の種類は接続先の要求に足す指定を持たない(用途ごとに持つ = 設計/AI/実行と費用/README.md 4.4) */
    public function test_detects_provider_options_on_a_conversation_kind(): void
    {
        $settings = [...self::conversationKindSettings(), 'provider_options' => []];
        $this->configureKind(RecordingConversationKind::CODE, $settings);
        $section = 'config/ai_runs.php の conversation';

        $this->assertSame(
            ["種類 test.conversation(conversation)が provider_options を持つ(会話の型は用途ごとに持つ = {$section})"],
            app(PromptKinds::class)->problems(),
        );
    }

    public function test_detects_a_conversation_kind_whose_budget_is_not_in_the_settings(): void
    {
        $settings = [...self::conversationKindSettings(), 'budget' => 'missing'];
        $this->configureKind(RecordingConversationKind::CODE, $settings);

        $this->assertSame(
            ['種類 test.conversation の予算の区分 missing が設定(config/ai_runs.php の budget)に無い'],
            app(PromptKinds::class)->problems(),
        );
    }

    /**
     * 文章の種類の設定。
     *
     * @return array<string, mixed>
     */
    private static function promptKindSettings(): array
    {
        return [
            'type' => 'prompt',
            'provider' => 'anthropic',
            'model' => 'claude-haiku-5-5',
            'budget' => 'default',
            'timeout_seconds' => 100,
            'max_output_tokens' => 100,
            'max_input_chars' => 1000,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function configureKind(string $code, array $settings): void
    {
        config(['ai_runs.kinds' => [...(array) config('ai_runs.kinds'), $code => $settings]]);
    }

    /**
     * 入れ子の配列から、鍵の道の値を消す。
     *
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $path
     */
    private function forget(array &$values, array $path): void
    {
        $key = array_shift($path);

        if ($path === []) {
            unset($values[$key]);

            return;
        }

        $child = (array) $values[$key];
        $this->forget($child, $path);
        $values[$key] = $child;
    }
}
