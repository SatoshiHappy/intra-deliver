<?php

namespace Tests\Support;

use Illuminate\Validation\ValidationException;

/**
 * 業務処理が、業務処理で止める規則に反する入力を ValidationException で拒み、その項目のエラーを持つこと。
 */
trait AssertsRejectedByRule
{
    private function assertRejected(string $key, callable $action): void
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());

            return;
        }

        $this->fail("ValidationException({$key})が投げられなかった");
    }
}
