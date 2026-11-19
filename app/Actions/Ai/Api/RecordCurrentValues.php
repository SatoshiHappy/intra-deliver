<?php

namespace App\Actions\Ai\Api;

/**
 * 直すの確認の内容を作る口の 1 段目が読んだ今の値(docs の 設計/AI/道具と権限/README.md 10.1・10.2。編集の画面と同じ編集の初期値の読み取りの値)。
 * ③ の共通の部品が今の値を使う検証(W8・W3・今の値に無い相手の確かめ = 10.3 の B19)に使い、2 段目に渡す。値は画面の初期値の形で、相手は
 * 参照の値(id・コード・名前・所属。型「ユーザーと組織」は種類も)。
 *
 * @phpstan-type CurrentParty array{type: 'user'|'department', id: int, code: string|null, label: string|null,
 *     affiliation: string|null}
 */
final readonly class RecordCurrentValues
{
    /**
     * @param  array<string, mixed>  $values  項目のコード → 今の値(画面の初期値の形)
     * @param  array<string, list<array{id: int, values: array<string, mixed>}>>  $details  明細の項目のコード → 行
     * @param  array{restricted: bool, viewers: list<CurrentParty>}  $rowAccess  参照制限の今の値
     * @param  string  $version  入れ物の同時の編集の確かめの値(書く時にそのまま渡す)
     */
    public function __construct(
        public int $id,
        public string $name,
        public array $values,
        public array $details,
        public array $rowAccess,
        public string $version,
    ) {}
}
