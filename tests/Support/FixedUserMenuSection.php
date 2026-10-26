<?php

namespace Tests\Support;

use App\Queries\Account\Api\UserMenuSections;
use App\Support\AccessContext;

/**
 * テストの中だけで使う利用者メニューの節(docs の 設計/デモユーザー切替/今の利用者.md 5.9 の「節の口」)。決めた見出しと項目の節を返す。
 * 項目の note に、呼ばれたときの判定の入力のユーザー(ゲストは guest)を入れ、要求の判定の入力で呼ばれたことを確かめられるようにする。
 */
final readonly class FixedUserMenuSection implements UserMenuSections
{
    public function __construct(private string $heading, private string $itemCode) {}

    public function section(AccessContext $context): array
    {
        return [
            'heading' => $this->heading,
            'items' => [[
                'code' => $this->itemCode,
                'name' => null,
                'note' => $context->userId === null ? 'guest' : "user {$context->userId}",
                'url' => '/',
                'method' => 'get',
                'data' => ['code' => $this->itemCode],
            ]],
        ];
    }
}
