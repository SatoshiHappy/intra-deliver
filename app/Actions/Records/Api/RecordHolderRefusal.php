<?php

namespace App\Actions\Records\Api;

use Illuminate\Validation\ValidationException;

/**
 * 持ち主の規則で書き込みを断った 422(キー record。docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md の 9.2 の 2)。
 * 画面には ValidationException と同じに届く。型を分けるのは、書き込みを呼ぶ後ろのモジュール(AI の書く道具)が開いた後の更新の断り
 * ({@see RecordStaleRefusal})と見分けるため。
 */
final class RecordHolderRefusal extends ValidationException
{
    public static function of(string $reason): self
    {
        return self::withMessages(['record' => [$reason]]);
    }
}
