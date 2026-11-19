<?php

namespace App\Actions\Ai\Core\Substitute\Sdk;

use App\Actions\Ai\Core\ProviderTraits;
use App\Actions\Ai\Core\SendFailure;
use GuzzleHttp\Psr7\Exception\TimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Streaming\Events\Error;
use RuntimeException;
use Throwable;

/**
 * 接続先の例外と流れの誤りの事象を、代わりの版の失敗の区分に読む(docs の 設計/AI/会話の中核.md 13.1 の誤りの読み方、受ける所 =
 * 設計/AI/会話の中核の口と受け手.md 13.3)。長すぎるは接続先の性質の句でだけ見分ける(設計/AI/会話の中核.md 13.6)。読めない例外は投げ直す(受け手の例外と
 * 作りの誤りを握らない)。
 */
final readonly class SubstituteErrors
{
    public function __construct(private ProviderTraits $traits) {}

    /**
     * @throws Throwable 接続先の例外でないとき
     */
    public function fromException(Throwable $exception, bool $midStream): SubstituteFailure
    {
        $previous = $exception instanceof AiException ? $exception->getPrevious() : null;
        $readable = $previous instanceof RequestException || $previous instanceof ConnectionException
            || $previous instanceof TimeoutException;
        $inner = $readable ? $previous : $exception;

        return match (true) {
            $inner instanceof TimeoutException => new SubstituteFailure(SendFailure::Timeout, null, $midStream),
            $inner instanceof RequestException => $this->fromStatus(
                $inner->response->status(),
                $inner->response->body(),
                $midStream,
            ),
            $inner instanceof ConnectionException => new SubstituteFailure(
                self::isTimeout($inner->getMessage()) ? SendFailure::Timeout : SendFailure::Provider,
                null,
                $midStream,
            ),
            $inner instanceof AiException => $this->fromStatus(null, $inner->getMessage(), $midStream),
            $midStream && $inner instanceof RuntimeException => new SubstituteFailure(
                SendFailure::Provider,
                null,
                true,
            ),
            default => throw $exception,
        };
    }

    /** 流れの誤りの事象(状態は載らない) */
    public function fromStreamError(Error $error): SubstituteFailure
    {
        return $this->fromStatus(null, $error->message, true);
    }

    /** 誤りの文が長すぎるの句に当たれば長すぎる(句が実際の量と上限を拾えばそれも持つ)、ほかは接続先 */
    private function fromStatus(?int $status, string $body, bool $midStream): SubstituteFailure
    {
        foreach ($this->traits->tooLongPatterns as $pattern) {
            if (preg_match($pattern, $body, $match) === 1) {
                return new SubstituteFailure(
                    SendFailure::TooLong,
                    $status,
                    $midStream,
                    isset($match['actual']) ? (int) $match['actual'] : null,
                    isset($match['limit']) ? (int) $match['limit'] : null,
                );
            }
        }

        return new SubstituteFailure(SendFailure::Provider, $status, $midStream);
    }

    private static function isTimeout(string $message): bool
    {
        return stripos($message, 'timeout') !== false || stripos($message, 'timed out') !== false;
    }
}
