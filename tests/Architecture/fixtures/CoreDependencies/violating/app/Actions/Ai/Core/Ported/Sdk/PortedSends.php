<?php

namespace App\Actions\Ai\Core\Ported\Sdk;

use Illuminate\Http\Client\PendingRequest;

/**
 * 変換の 1 か所が自分で HTTP を送る型に依存するのは禁止(許すのは例外と応答の型だけ)
 */
final class PortedSends
{
    public function __construct(public PendingRequest $request) {}
}
