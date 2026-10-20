<?php

namespace UpdateCheck;

/**
 * HTTP の要求。資格情報は付けない(レジストリが誰にでも出す匿名のトークンだけを呼び出し側が付ける)。
 */
interface HttpClient
{
    /**
     * @param  array<string, string>  $headers
     *
     * @throws UnconfirmedException 通信できなかったとき(HTTP のエラーの応答は例外にしない)
     */
    public function request(string $method, string $url, array $headers = []): HttpResponse;
}
