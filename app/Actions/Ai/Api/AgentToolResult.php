<?php

namespace App\Actions\Ai\Api;

/**
 * 道具の結果(docs の 設計/AI/道具と権限/README.md 10.1 の「実行」と「読んだレコードを返す形」・10.2)。$data は JSON にできる配列で、レコードを
 * 指す所に {@see RecordMarker} を置く(包みが印にする。値の中の印と同じ形の文字列は包みが直す)。$records は結果に入ったレコード(道具の行の
 * 読んだ・書いたレコード = 設計/AI/実行の記録.md 2.4)。
 */
final readonly class AgentToolResult
{
    /** 見つからない(無い・見えない)の文。判定の関数の B10 と道具の B3 が同じ文を使う(10.3) */
    public const NOT_FOUND = '指定したものは見つからなかった。';

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<array{module: string, id: int, name: string}>  $records
     */
    public function __construct(public array $data, public array $records = []) {}

    /** 見つからない(10.3 の B3)の結果 */
    public static function notFound(): self
    {
        return new self(['error' => self::NOT_FOUND]);
    }
}
