<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordFieldType;

/**
 * 型「ユーザーと組織」の値の相手 {type: 'user'|'department', id}(docs の 設計/業務データの入れ物/項目と規則/README.md 2 節の表、ADR 0028 の決定 3)の
 * 形の 1 か所。業務処理の入力の検証・一時保存の入力の読み直し・参照の行の読み取りが同じ形(キーの順 type・id)で比べられるようにする。
 * 種類の値は項目の型の値(user・department)と同じ(版の写しの相手 = {@see RecordValueReader::target()} と同じ形)。
 *
 * @phpstan-type Party array{type: 'user'|'department', id: int}
 */
final class RecordParty
{
    /**
     * 値が相手の形(キーが type と id だけ、type が user・department、id が整数)なら、キーの順をそろえた相手。合わなければ null。
     * id の 10 進の文字列は受けない(参照の値は整数だけ = 項目と規則/README.md 2 節の最後)。
     *
     * @return Party|null
     */
    public static function of(mixed $value): ?array
    {
        if (! is_array($value) || count($value) !== 2 || ! array_key_exists('type', $value)) {
            return null;
        }

        $id = $value['id'] ?? null;
        [$user, $department] = [RecordFieldType::User->value, RecordFieldType::Department->value];

        return match (true) {
            ! is_int($id) => null,
            $value['type'] === $user => ['type' => $user, 'id' => $id],
            $value['type'] === $department => ['type' => $department, 'id' => $id],
            default => null,
        };
    }

    /**
     * 相手の鍵(「user:<id>」・「department:<id>」。同じ相手 = 種類と id が同じ)。
     *
     * @param  Party  $party
     */
    public static function key(array $party): string
    {
        return "{$party['type']}:{$party['id']}";
    }
}
