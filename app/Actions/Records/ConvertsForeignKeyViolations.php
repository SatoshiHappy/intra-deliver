<?php

namespace App\Actions\Records;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * 消す文で起きた外部キー違反を、項目つきの検証の誤りに変える 1 か所(docs の 設計/業務データの入れ物/データモデル/制約の分担と検査.md
 * 6 節の例外 1。レコードの削除と項目の削除)。制約の名前もどの表が指しているかも見ない。PostgreSQL は ON DELETE RESTRICT の違反を
 * SQLSTATE 23001(restrict_violation)、NO ACTION の違反を 23503(foreign_key_violation)で返すので、両方を変える。
 * 違反の後のトランザクションは文を受け付けないので、変えた例外を投げて抜ける(呼び手のトランザクションが巻き戻す)。
 */
trait ConvertsForeignKeyViolations
{
    /** 外部キー違反の SQLSTATE(restrict_violation と foreign_key_violation) */
    private const FOREIGN_KEY_VIOLATIONS = ['23001', '23503'];

    /**
     * 消す文を走らせ、外部キー違反ならキー $key・文言 $message の検証の誤りにして投げる。ほかの失敗はそのまま投げる。
     *
     * @param  Closure(): mixed  $statements
     *
     * @throws ValidationException 外部キー違反のとき
     * @throws QueryException ほかの失敗のとき
     */
    private function convertingForeignKeyViolations(Closure $statements, string $key, string $message): void
    {
        try {
            $statements();
        } catch (QueryException $exception) {
            throw in_array($exception->getCode(), self::FOREIGN_KEY_VIOLATIONS, true)
                ? ValidationException::withMessages([$key => [$message]])
                : $exception;
        }
    }
}
