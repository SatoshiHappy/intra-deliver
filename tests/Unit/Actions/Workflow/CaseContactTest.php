<?php

namespace Tests\Unit\Actions\Workflow;

use App\Actions\Workflow\CaseContact;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * 連絡の入力の値のクラスは、連絡する人を整数の並び(list)に限る。外れれば呼び手の誤り(入口が要求の検証で整数に変えてから作る)。
 */
final class CaseContactTest extends TestCase
{
    public function test_a_list_of_integers_is_accepted(): void
    {
        $this->assertSame([3, 5], (new CaseContact([3, 5], null))->userIds);
        $this->assertSame([], (new CaseContact([], 'ひと言'))->userIds);
    }

    public function test_user_ids_other_than_a_list_of_integers_are_a_caller_error(): void
    {
        $contactOf = fn (mixed $userIds) => new CaseContact($userIds, null);
        $inputs = ['文字列の id' => ['5'], '整数と文字列' => [3, '5'], 'list でない' => [1 => 5], 'null を含む' => [null]];

        foreach ($inputs as $label => $userIds) {
            try {
                $contactOf($userIds);
                $this->fail("{$label}: 例外が出ない");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('整数の並び', $exception->getMessage(), $label);
            }
        }
    }
}
