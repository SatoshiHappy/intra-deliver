<?php

namespace App\Actions\Ai;

use App\Enums\Ai\AiRunErrorCode;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use Throwable;

/**
 * 接続先の呼び出しの失敗(内部。{@see LlmClient}・{@see TranscriptionClient} が laravel/ai・HTTP の例外を包み、失敗の種類 provider / timeout を決める)。
 * 元の例外は previous。
 */
final class LlmCallFailed extends RuntimeException
{
    /** Guzzle の curl の時間切れの誤り(CURLE_OPERATION_TIMEDOUT)の文の頭。laravel/ai は Laravel の HTTP クライアントで接続先を呼ぶ */
    private const CURL_TIMEOUT = 'cURL error 28';

    public function __construct(public readonly AiRunErrorCode $errorCode, Throwable $previous)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
    }

    /**
     * 呼び出しの例外を包む(時間の上限で切れた = timeout、ほか = provider)。
     */
    public static function from(Throwable $exception): self
    {
        return new self(self::isTimeout($exception) ? AiRunErrorCode::Timeout : AiRunErrorCode::Provider, $exception);
    }

    /**
     * 時間の上限で切れたか(laravel/ai は接続の誤りを包むので、元の例外まで辿る)。
     */
    private static function isTimeout(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $timedOut = $current instanceof ConnectionException
                && str_starts_with($current->getMessage(), self::CURL_TIMEOUT);

            if ($timedOut) {
                return true;
            }
        }

        return false;
    }
}
