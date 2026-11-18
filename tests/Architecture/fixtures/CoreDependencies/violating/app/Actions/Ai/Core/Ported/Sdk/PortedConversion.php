<?php

namespace App\Actions\Ai\Core\Ported\Sdk;

use Illuminate\Support\Facades\Http;

/**
 * 変換の 1 か所でもファサードは禁止
 */
final class PortedConversion
{
    public function fake(): void
    {
        Http::fake();
    }
}
