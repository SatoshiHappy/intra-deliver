<?php

namespace UpdateCheck;

/**
 * HTTP の応答。見出しの名前は小文字。
 */
final readonly class HttpResponse
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}

    /**
     * 2xx 以外は確認できなかった。429 は理由を「上限」にする(設計/更新チェック.md 5 節)。
     *
     * @throws UnconfirmedException
     */
    public function ensureSuccess(string $what): self
    {
        if ($this->status === 429) {
            throw new UnconfirmedException("上限(HTTP 429): {$what}");
        }

        if ($this->status < 200 || $this->status >= 300) {
            throw new UnconfirmedException("HTTP {$this->status}: {$what}");
        }

        return $this;
    }
}
