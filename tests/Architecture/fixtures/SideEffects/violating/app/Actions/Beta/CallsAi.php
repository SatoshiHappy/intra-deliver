<?php

namespace App\Actions\Beta;

use App\Actions\Alpha\AlphaAgent;
use Laravel\Ai\Image;

use function Laravel\Ai\agent;

/**
 * 台帳②で AI の呼び出しを許していないモジュール(Beta)の業務処理から AI
 */
final class CallsAi
{
    public function ask(AlphaAgent $agent): void
    {
        $agent->prompt('question');
        agent('instructions')->prompt('question');
        Image::of('sunset')->generate();
    }
}
