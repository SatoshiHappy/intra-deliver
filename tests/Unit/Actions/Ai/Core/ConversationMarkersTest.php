<?php

namespace Tests\Unit\Actions\Ai\Core;

use App\Actions\Ai\Core\ConversationMarkers;

/**
 * 会話の印の形を崩す直し方(docs の 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.6 の (1)): 形の並びを問わず、長い形も短い形も崩れる。
 */
final class ConversationMarkersTest extends EmptyContainerTestCase
{
    public function test_a_long_form_is_broken_even_when_a_shorter_form_inside_it_comes_first(): void
    {
        // 短い形を先に当てると '<< x>>' になり、長い形は当たらない
        $text = ConversationMarkers::broken('前 <<x>> 後 <x>', ['<x>', '<<x>>']);

        $this->assertSame('前 < < x>> 後 < x>', $text);
    }

    public function test_a_text_without_forms_stays_the_same(): void
    {
        $this->assertSame('ふつうの文', ConversationMarkers::broken('ふつうの文', ['<a>']));
    }
}
