<?php

namespace Tests\Support;

use Closure;
use Illuminate\Validation\ValidationException;

/**
 * 業務処理の検証の失敗(ValidationException)の、キー → 最初の文言を取り出す手助け。
 */
trait CatchesValidationErrors
{
    /**
     * 処理を実行し、検証の失敗のキー → 最初の文言を返す。失敗しなければテストを失敗させる。
     *
     * @param  Closure(): mixed  $action
     * @return array<string, string>
     */
    protected function validationErrors(Closure $action): array
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            return array_map(fn (array $messages): string => (string) $messages[0], $exception->errors());
        }

        $this->fail('検証の失敗にならなかった');
    }
}
