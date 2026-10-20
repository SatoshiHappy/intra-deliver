<?php

namespace App\Queries\Demo;

/**
 * 切替の候補 1 人(docs の 設計/デモユーザー切替/デモの取り付け.md 6.5)。{@see DemoUserQuery} が返す。
 */
final readonly class DemoUser
{
    /**
     * @param  int  $id  ユーザー(users.id)。切替で送る user_id
     * @param  string  $code  ユーザーコード
     * @param  string|null  $name  d・ロケールの氏名。行が無ければ null(画面がコードを出す = docs の 設計/デモユーザー切替/README.md の方針 9)
     * @param  string  $role  画面に出す役どころ(config/demo.php の users の role)
     * @param  string  $can  この人で試せること(config/demo.php の users の can。ホームの役割カードの文)
     */
    public function __construct(
        public int $id,
        public string $code,
        public ?string $name,
        public string $role,
        public string $can,
    ) {}
}
