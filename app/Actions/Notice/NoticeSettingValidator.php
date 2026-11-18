<?php

namespace App\Actions\Notice;

use App\Queries\Notice\NoticeTypeCatalog;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 通知の設定の 2 つの書き込み(利用者の設定・必須の印)が受ける入力の確かめ(docs の
 * 設計/ワークフロー/申請の周辺/通知の受け取りの設定/利用者と管理の画面.md の 3.3・4.3 の 2 行目)。
 */
class NoticeSettingValidator
{
    /** 種類が一覧に無い・値が真偽でないときの文(誤りのキーによらず 1 つ) */
    public const INVALID_MESSAGE = 'この通知の設定は保存できませんでした。';

    public function __construct(private NoticeTypeCatalog $types) {}

    /**
     * 確かめた種類と値。値の誤りは、書き込みが受ける入力のキー $valueKey で返す(docs の
     * 設計/ワークフロー/申請の周辺/通知の受け取りの設定/利用者と管理の画面.md の 3.3・4.3 の 2 行目)。
     *
     * @return array{string, bool}
     *
     * @throws ValidationException 種類が一覧に無いとき(キー type)、値が真偽でないとき(キー $valueKey。当たるキーごと)
     * @throws LogicException 種類の一覧の実装の答えが規則に外れるとき
     */
    public function validated(mixed $type, string $valueKey, mixed $value): array
    {
        $listedType = is_string($type) && $this->types->has($type) ? $type : null;
        $boolean = is_bool($value) ? $value : null;

        if ($listedType !== null && $boolean !== null) {
            return [$listedType, $boolean];
        }

        $errors = [];

        if ($listedType === null) {
            $errors['type'] = [self::INVALID_MESSAGE];
        }

        if ($boolean === null) {
            $errors[$valueKey] = [self::INVALID_MESSAGE];
        }

        throw ValidationException::withMessages($errors);
    }
}
