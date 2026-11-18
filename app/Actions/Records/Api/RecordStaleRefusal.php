<?php

namespace App\Actions\Records\Api;

use Illuminate\Validation\ValidationException;

/**
 * 開いた後にほかで更新されていたので更新を断った 422(キー record。docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md の 9.2 の 2)。
 * 画面には ValidationException と同じに届く。型を分けるのは、書き込みを呼ぶ後ろのモジュール(AI の書く道具)が持ち主の断り
 * ({@see RecordHolderRefusal})と見分けるため。
 */
final class RecordStaleRefusal extends ValidationException
{
    public static function of(string $message): self
    {
        return self::withMessages(['record' => [$message]]);
    }
}
