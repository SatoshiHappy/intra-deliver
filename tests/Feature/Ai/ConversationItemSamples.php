<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\ConversationItem;
use DateTimeImmutable;

/**
 * 会話の保存の項目のテストが共に使う見本(使用量の写しと、生の状態を持つ応答の終わり)。
 */
trait ConversationItemSamples
{
    /** 使用量の写し(読めない値は null) */
    private const USAGE = [
        'input_tokens' => 120,
        'output_tokens' => 30,
        'cache_read_input_tokens' => 100,
        'cache_write_input_tokens' => null,
        'reasoning_tokens' => null,
    ];

    /** 生の状態を持つ応答の終わり */
    private static function responseEnd(): ConversationItem
    {
        return ConversationItem::responseEnd(
            'r1',
            self::USAGE,
            [['type' => 'thinking', 'thinking' => '考え', 'signature' => 'sig'], ['type' => 'text', 'text' => '探します']],
            'anthropic',
            'claude-x',
            3,
            ['call_1', 'call_2'],
            new DateTimeImmutable('2026-11-17T10:00:00+09:00'),
        );
    }
}
