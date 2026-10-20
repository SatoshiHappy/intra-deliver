<?php

namespace Tests\Unit\Support;

use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * 判定の入力を決める 1 か所(docs の 設計/メニュー/判定の入力.md 5 節: 利用者もセッションも無い要求はゲスト・接続元なし・選んだ組織なし・
 * Asia/Tokyo の当日・ja。要求ごとに 1 回だけ作り、同じ要求では同じ値を返す。基準日 d は dateOf が要求ごとに 1 回だけ決め、of はそれを使う =
 * docs の 設計/デモユーザー切替/今の利用者.md 5.4)。要求を通したユーザーと選んだ組織は Tests\Feature\Support\AccessContextRequestTest。
 */
final class AccessContextTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_is_a_guest_without_an_address_in_japanese_on_the_date_in_tokyo(): void
    {
        CarbonImmutable::setTestNow('2026-10-04 15:30:00 UTC');

        $context = AccessContext::of(Request::create('/'));

        $this->assertSame([null, null, null, 'ja'], [$context->userId, $context->ipv4Address, $context->selectedDepartmentId, $context->localeCode]);
        $this->assertSame('2026-10-05', $context->date->format('Y-m-d'));
    }

    public function test_returns_the_same_context_for_the_same_request_across_midnight_in_tokyo(): void
    {
        $request = Request::create('/');
        CarbonImmutable::setTestNow('2026-10-04 14:59:59 UTC');
        $first = AccessContext::of($request);
        CarbonImmutable::setTestNow('2026-10-04 15:00:00 UTC');

        $second = AccessContext::of($request);

        $this->assertSame($first, $second);
        $this->assertSame('2026-10-05', AccessContext::of(Request::create('/'))->date->format('Y-m-d'));
    }

    public function test_date_of_returns_the_same_date_for_the_same_request_across_midnight_in_tokyo(): void
    {
        $request = Request::create('/');
        CarbonImmutable::setTestNow('2026-10-04 14:59:59 UTC');
        $first = AccessContext::dateOf($request);
        CarbonImmutable::setTestNow('2026-10-04 15:00:00 UTC');

        $second = AccessContext::dateOf($request);

        $this->assertSame('2026-10-04', $first->format('Y-m-d'));
        $this->assertSame($first, $second);
        $this->assertSame($first, AccessContext::of($request)->date);
        $this->assertSame('2026-10-05', AccessContext::dateOf(Request::create('/'))->format('Y-m-d'));
    }

    public function test_of_uses_the_date_that_date_of_decided_before_it(): void
    {
        $request = Request::create('/');
        CarbonImmutable::setTestNow('2026-10-04 14:59:59 UTC');
        $date = AccessContext::dateOf($request);
        CarbonImmutable::setTestNow('2026-10-04 15:00:00 UTC');

        $context = AccessContext::of($request);

        $this->assertSame($date, $context->date);
        $this->assertSame($date, AccessContext::dateOf($request));
    }
}
