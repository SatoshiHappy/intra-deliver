<?php

namespace App\Actions\Alpha;

use Laravel\Ai\Image;

use function Laravel\Ai\agent;

/**
 * 台帳②で AI の呼び出しを許したモジュール(Alpha)の業務処理から AI(エージェント・関数で作ったエージェント・生成の依頼)
 */
final class CallsAi
{
    public function ask(AlphaAgent $agent): string
    {
        $agent->prompt('question');
        agent('instructions')->prompt('question');
        Image::of('sunset')->generate();

        return 'done';
    }
}
