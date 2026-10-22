<?php

namespace Tests\Feature\Deploy;

use Tests\Support\PublishSettingsTemplate;
use Tests\TestCase;

/**
 * 公開用の設定ファイルの雛形 .env.publish.example(docs の 設計/公開の配備/DBと秘密.md の 4.4 節)の AI の節: 接続先の鍵は queue だけが受ける
 * ai.env に置いて値を空にし、app.env に置かない(docs の 設計/公開の配備/構成.md の 3.4 節)。
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
}
