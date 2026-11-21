<?php

namespace App\Actions\Ai;

use App\Queries\Ai\RunSpending;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * 接続先の呼び出しの例外から、接続先が返した HTTP の応答の状態コードを引く(docs の 設計/AI/実行の記録.md 2.1・2.3 の応答の状態コード)。
 * 応答を受けていない(接続の失敗・時間切れ)か、状態コードの載らない例外は null。laravel/ai は HTTP の例外を包むので元の例外まで辿る。
 */
final class ProviderStatus
{
    public static function of(?Throwable $exception): ?int
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RequestException) {
                return $current->response->status();
            }
        }

        return null;
    }

    /** 断られた呼び出しか(下限 = {@see RunSpending::DECLINED_FROM}) */
    public static function declined(?Throwable $exception): bool
    {
        return (self::of($exception) ?? 0) >= RunSpending::DECLINED_FROM;
    }
}
