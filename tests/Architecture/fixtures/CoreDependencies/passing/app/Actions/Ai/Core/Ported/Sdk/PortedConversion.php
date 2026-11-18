<?php

namespace App\Actions\Ai\Core\Ported\Sdk;

use GuzzleHttp\Psr7\Exception\TimeoutException;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Streaming\Events\Error;
use Throwable;

/**
 * 変換の 1 か所が版の並びに加えて依れる物(HTTP の例外・laravel/ai の例外・流れの事象・流れを読む途中の時間切れ)
 */
final class PortedConversion
{
    public function kind(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof TimeoutException => 'timeout',
            $exception instanceof RequestException => 'http',
            $exception instanceof AiException => 'ai',
            default => 'other',
        };
    }

    public function streamed(object $event): bool
    {
        return $event instanceof Error;
    }
}
