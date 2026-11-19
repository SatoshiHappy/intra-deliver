<?php

namespace App\Actions\Ai;

use App\Queries\Ai\KindSettings;
use Laravel\Ai\Enums\Lab;

/**
 * 実行ごとに接続先の要求に足す訪問者の ID(送る条件 = docs の 設計/AI/実行と費用/README.md 4.4)。anthropic だけに送るのは、ほかの driver の
 * 接続先は識別子の欄を確かめていないため。文章の型と会話の型のステップが同じ形で足す。
 */
final class VisitorOptions
{
    /**
     * @return array<string, mixed>
     */
    public static function of(?string $driver, ?string $visitorId): array
    {
        return $driver === Lab::Anthropic->value && $visitorId !== null
            ? [KindSettings::RUN_OPTION_KEY => ['user_id' => $visitorId]]
            : [];
    }
}
