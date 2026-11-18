<?php

namespace App\Queries\Records\Api;

/**
 * 持ち主の画面へのリンクと状態の札({@see RecordHoldNotices::stateOf()} が返す値)。
 */
final readonly class RecordHoldState
{
    /** 札の色の値(画面の札の色の段階と同じ) */
    public const TONES = ['pending', 'returned', 'settled', 'rejected'];

    /**
     * @param  string  $status  札の語
     * @param  string  $tone  札の色({@see self::TONES} のどれか。外の値は束ねる 1 か所が報告して案内の無い形にする)
     * @param  string|null  $suffix  札に続ける補いの語
     */
    public function __construct(
        public RecordHoldLink $link,
        public string $status,
        public string $tone,
        public ?string $suffix,
    ) {}
}
