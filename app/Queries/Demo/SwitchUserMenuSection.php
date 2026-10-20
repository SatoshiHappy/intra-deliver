<?php

namespace App\Queries\Demo;

use App\Queries\Account\UserMenuSections;
use App\Support\AccessContext;

/**
 * 利用者メニューの「利用者の切替」の節(docs の 設計/デモユーザー切替/デモの取り付け.md 6.3。形は 設計/デモユーザー切替/今の利用者.md 5.7)。
 * 起動(App\Providers の Demo のプロバイダ)が、設定がオンのときだけタグ {@see UserMenuSections::TAG} に登録する。
 *
 * 項目 = 候補({@see DemoUserQuery})1 人に 1 つ。code = ユーザーコード、name = d・ロケールの氏名(無ければ null)、note = 役どころ、
 * 送り先 = 切替のルート(名前 demo.switch-user から作る URL)へ POST で {user_id}。候補がいなければ項目の無い節(画面は出さない)。
 */
class SwitchUserMenuSection implements UserMenuSections
{
    public function __construct(private DemoUserQuery $demoUsers) {}

    public function section(AccessContext $context): array
    {
        return [
            'heading' => '利用者の切替',
            'items' => array_map(fn (DemoUser $user) => [
                'code' => $user->code,
                'name' => $user->name,
                'note' => $user->role,
                'url' => route('demo.switch-user'),
                'method' => 'post',
                'data' => ['user_id' => $user->id],
            ], $this->demoUsers->candidatesOn($context->date, $context->localeCode)),
        ];
    }
}
