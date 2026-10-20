<?php

namespace App\Providers;

use GuzzleHttp\Client;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

/**
 * 起動で HTTP クライアントを作って束縛する(作るだけでは呼び出しでないので副作用に数えない)
 */
final class HttpClientProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Client::class, fn (): Client => new Client(['timeout' => 5]));
        $this->app->singleton(Factory::class, fn (): Factory => new Factory);
    }
}
