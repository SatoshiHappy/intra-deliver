<?php

namespace App\Queries\Records\Api;

/**
 * 持ち主の画面へのリンク({@see RecordHoldNotices} が返す値)。
 */
final readonly class RecordHoldLink
{
    /**
     * @param  string  $label  リンクの語
     */
    public function __construct(
        public string $label,
        public string $url,
    ) {}
}
