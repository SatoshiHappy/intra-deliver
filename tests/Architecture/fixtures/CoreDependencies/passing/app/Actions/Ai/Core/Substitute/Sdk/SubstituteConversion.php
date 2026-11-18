<?php

namespace App\Actions\Ai\Core\Substitute\Sdk;

use Illuminate\Http\Client\Response;

/**
 * 代わりの版の変換の 1 か所が依れる物(HTTP の応答)
 */
final class SubstituteConversion
{
    public function status(Response $response): int
    {
        return $response->status();
    }
}
