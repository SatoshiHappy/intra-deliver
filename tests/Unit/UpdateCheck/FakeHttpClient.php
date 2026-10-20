<?php

namespace Tests\Unit\UpdateCheck;

use UpdateCheck\HttpClient;
use UpdateCheck\HttpResponse;

/**
 * 決めた応答を返す HTTP の代わり(通信しない)。URL(メソッド + 空白 + URL)で引く。
 */
final class FakeHttpClient implements HttpClient
{
    /**
     * @param  array<string, HttpResponse>  $responses
     * @param  \ArrayObject<int, string>|null  $log  要求の記録(コマンドの記録と順番を比べるのに使う)
     */
    public function __construct(
        private readonly array $responses,
        private readonly ?\ArrayObject $log = null,
    ) {}

    public function request(string $method, string $url, array $headers = []): HttpResponse
    {
        $this->log?->append("http:{$method} {$url}");

        return $this->responses["{$method} {$url}"] ?? new HttpResponse(404, [], '');
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    public static function json(array $body, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, [], (string) json_encode($body));
    }
}
