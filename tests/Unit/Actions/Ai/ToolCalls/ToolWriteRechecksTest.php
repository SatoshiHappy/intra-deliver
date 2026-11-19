<?php

namespace Tests\Unit\Actions\Ai\ToolCalls;

use App\Actions\Ai\ToolCalls\ToolWriteRechecks;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 書く時の id の確かめで除く今の値(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の「承認待ちの呼び出し」の検証のやり直し): 確認の内容の
 * 項目・明細の今の値と参照制限の今の見せる人に入った相手を、入れ子の中まで集める(書く値の相手は数えない)。
 */
final class ToolWriteRechecksTest extends TestCase
{
    public function test_the_current_parties_of_fields_details_and_viewers_are_kept(): void
    {
        $owner = ['current' => ['type' => 'user', 'id' => 1], 'next' => ['type' => 'user', 'id' => 9]];
        $lines = ['current' => [['values' => ['who' => ['type' => 'user', 'id' => 2]]]], 'next' => []];
        $content = [
            'fields' => ['owner' => $owner],
            'details' => ['lines' => $lines],
            'rowAccess' => ['current' => ['restricted' => true, 'viewers' => [['type' => 'department', 'id' => 3]]]],
        ];
        $keys = new ReflectionMethod(ToolWriteRechecks::class, 'currentKeys');

        $this->assertSame(['user:1', 'user:2', 'department:3'], $keys->invoke(null, $content));
    }
}
