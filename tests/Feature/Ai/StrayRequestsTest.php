<?php

namespace Tests\Feature\Ai;

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Tests\Support\TransactionalTestCase;

/**
 * テストは外部への HTTP を偽装したものだけにする(docs の 設計/AI/データと失敗と検査.md 8 節。共通の基底 Tests\TestCase の Http::preventStrayRequests)。
 * 呼び手のモジュールのテストから AI のジョブが動いても、本物の接続先に送らない。AI の接続先の鍵は設定に載らない。
 */
final class StrayRequestsTest extends TransactionalTestCase
{
    public function test_no_ai_provider_key_reaches_the_test_settings(): void
    {
        $keys = array_map(fn (mixed $provider): mixed => is_array($provider) ? ($provider['key'] ?? null) : null, (array) config('ai.providers'));

        $this->assertSame([], array_filter($keys, fn (mixed $key): bool => $key !== null));
    }

    public function test_an_unfaked_request_is_refused(): void
    {
        $this->expectException(StrayRequestException::class);

        Http::get('https://api.anthropic.com/v1/messages');
    }
}
