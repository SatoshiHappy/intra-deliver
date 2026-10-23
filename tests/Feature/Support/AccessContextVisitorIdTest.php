<?php

namespace Tests\Feature\Support;

use App\Support\AccessContext;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Tests\Support\TransactionalTestCase;

/**
 * 要求の訪問者の ID(docs の 設計/メニュー/判定の入力.md 5 節): ヘッダー X-Visitor-Id の UUID はその値、UUID の形でない値は null と
 * warning(ログに値を出さない)、ヘッダーが無ければ null で warning なし。同じ要求では同じ値を返す。
 */
final class AccessContextVisitorIdTest extends TransactionalTestCase
{
    private const VISITOR_ID = '0b9e8f3a-6c2d-4d1e-9a7b-3f5c1e2d4a6b';

    /** @var list<array{string, string}> 出たログ(水準、文と文脈をつないだもの) */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Log::listen(function (MessageLogged $event): void {
            $this->logged[] = [$event->level, $event->message.json_encode($event->context)];
        });
    }

    public function test_a_uuid_in_the_header_is_the_visitor_id(): void
    {
        $context = AccessContext::of($this->requestWith(self::VISITOR_ID));

        $this->assertSame(self::VISITOR_ID, $context->visitorId);
        $this->assertSame([], $this->logged);
    }

    public function test_a_value_that_is_not_a_uuid_is_none_with_a_warning_without_the_value(): void
    {
        $value = 'recruiter@example.com';

        $context = AccessContext::of($this->requestWith($value));

        $this->assertNull($context->visitorId);
        $this->assertCount(1, $this->logged);
        $this->assertSame('warning', $this->logged[0][0]);
        $this->assertStringNotContainsString($value, $this->logged[0][1]);
    }

    public function test_a_request_without_the_header_has_no_visitor_id_and_no_warning(): void
    {
        $context = AccessContext::of(Request::create('/'));

        $this->assertNull($context->visitorId);
        $this->assertSame([], $this->logged);
    }

    public function test_the_same_request_returns_the_same_visitor_id(): void
    {
        $request = $this->requestWith(self::VISITOR_ID);
        $first = AccessContext::of($request);
        $request->headers->set(AccessContext::VISITOR_ID_HEADER, '1d2c3b4a-5e6f-4a7b-8c9d-0e1f2a3b4c5d');

        $second = AccessContext::of($request);

        $this->assertSame([self::VISITOR_ID, $first], [$second->visitorId, $second]);
    }

    private function requestWith(string $visitorId): Request
    {
        return Request::create('/', server: ['HTTP_X_VISITOR_ID' => $visitorId]);
    }
}
