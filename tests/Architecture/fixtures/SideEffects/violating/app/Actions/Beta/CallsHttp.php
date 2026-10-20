<?php

namespace App\Actions\Beta;

use Illuminate\Support\Facades\Http;

/**
 * 台帳②で HTTP を許していないモジュール(Beta)の業務処理から HTTP
 */
final class CallsHttp
{
    public function fetch(): string
    {
        return Http::get('https://example.com')->body();
    }
}
