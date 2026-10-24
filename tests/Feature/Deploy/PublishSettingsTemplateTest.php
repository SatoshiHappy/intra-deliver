<?php

namespace Tests\Feature\Deploy;

use Dotenv\Dotenv;
use Tests\Support\PublishSettingsTemplate;
use Tests\TestCase;

/**
 * 公開用の設定ファイルの雛形 .env.publish.example(docs の 設計/公開の配備/DBと秘密.md の 4.4 節)の値(docs の 設計/公開の配備/構成/設定.md の 3.4 節):
 * AI の接続先の鍵は queue だけが受ける ai.env に置いて値を空にし、app.env に置かない。毎日の初期化は公開用の雛形だけがオンにし、
 * メンテナンスの状態を DB のキャッシュの表に置く(開発用の .env.example はオフ = 毎日の初期化.md の 5 節)。
 */
final class PublishSettingsTemplateTest extends TestCase
{
    private const AI_KEYS = ['ANTHROPIC_API_KEY', 'GEMINI_API_KEY'];

    public function test_the_ai_keys_are_in_ai_env_with_empty_values(): void
    {
        $this->assertSame(array_fill_keys(self::AI_KEYS, ''), PublishSettingsTemplate::section('ai.env'));
    }

    public function test_app_env_holds_no_ai_key(): void
    {
        $app = PublishSettingsTemplate::section('app.env');

        $this->assertSame([], array_intersect(self::AI_KEYS, array_keys($app)));
        $this->assertSame([], preg_grep('/_API_KEY\z/', array_keys($app)));
    }

    public function test_app_env_turns_the_daily_reset_on_with_the_maintenance_state_in_the_database_cache(): void
    {
        $app = PublishSettingsTemplate::section('app.env');

        $this->assertSame('true', $app['DAILY_RESET_ENABLED'] ?? null);
        $this->assertSame('cache', $app['APP_MAINTENANCE_DRIVER'] ?? null);
        $this->assertSame('database', $app['APP_MAINTENANCE_STORE'] ?? null);
        $this->assertSame('database', $app['CACHE_STORE'] ?? null);
    }

    public function test_the_development_template_keeps_the_daily_reset_off(): void
    {
        $values = Dotenv::parse((string) file_get_contents(base_path('.env.example')));

        $this->assertSame('false', $values['DAILY_RESET_ENABLED'] ?? null);
    }
}
