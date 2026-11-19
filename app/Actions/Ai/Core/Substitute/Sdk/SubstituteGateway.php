<?php

namespace App\Actions\Ai\Core\Substitute\Sdk;

use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use LogicException;

/**
 * 接続先から 1 ステップの口を引く(docs の 設計/AI/会話の中核.md 13.1: SDK の接続先の約束は口を引く操作を持たず、各接続先が持つ。
 * 持たない接続先は作りの誤り)。
 */
final class SubstituteGateway
{
    public static function of(TextProvider $provider): StepTextGateway
    {
        $gateway = method_exists($provider, 'textGateway') ? $provider->textGateway() : null;

        if (! $gateway instanceof StepTextGateway) {
            throw new LogicException('接続先 '.$provider::class.' は 1 ステップの口を持たない');
        }

        return $gateway;
    }
}
