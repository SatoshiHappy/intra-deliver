<?php

namespace App\Actions\Workflow;

use InvalidArgumentException;

/**
 * 操作に付ける連絡の入力(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.5 節)。規則は {@see ContactValidator} が書く前に確かめる。
 */
final readonly class CaseContact
{
    /**
     * @param  list<int>  $userIds  連絡する人(入力 contact.user_ids。入口が整数に変えてから作る)
     * @param  string|null  $message  ひと言(入力 contact.message)
     *
     * @throws InvalidArgumentException 連絡する人が整数の並びでないとき(呼び手の誤り)
     */
    public function __construct(
        public array $userIds,
        public ?string $message,
    ) {
        self::throwIfNotIntList($userIds);
    }

    /**
     * 自分・重複の判定と宛先から除く人は整数の id で比べるので、文字列の id を受けると判定が外れる。
     *
     * @param  array<mixed>  $userIds
     *
     * @throws InvalidArgumentException
     */
    private static function throwIfNotIntList(array $userIds): void
    {
        if (! array_is_list($userIds) || array_filter($userIds, fn (mixed $id) => ! is_int($id)) !== []) {
            throw new InvalidArgumentException('連絡する人(userIds)は整数の並び(list)で渡す');
        }
    }
}
