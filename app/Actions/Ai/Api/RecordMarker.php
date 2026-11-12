<?php

namespace App\Actions\Ai\Api;

/**
 * 道具の結果の中のレコードの印の場所(docs の 設計/AI/道具と権限/README.md 10.2 の「結果のレコードの印と根拠」)。結果の文にするときに
 * ③ の印の部品が印にする。
 */
final readonly class RecordMarker
{
    public function __construct(public string $module, public int $id) {}
}
