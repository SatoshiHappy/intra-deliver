<?php

namespace App\Queries\Demo;

use App\Queries\Menu\Api\HomeSections;
use App\Support\AccessContext;

/**
 * ゲストのホームのあいさつ欄の一文と主ボタン(docs の 設計/デモユーザー切替/デモの取り付け.md 6.3。口と形は main の {@see HomeSections} = 設計/メニュー/ホーム.md 6.2)。
 * 起動(App\Providers の Demo のプロバイダ)が、設定がオンのときだけタグ {@see HomeSections::TAG} に登録する。
 * 今の利用者がいる・候補({@see DemoUserQuery})が 1 人もいなければ null(役割カードを出すのはゲストだけで、カードの無い主ボタンを出さない)。
 * 主ボタンは役割カードの節({@see PeopleHomeSection} の code)へのページ内リンク。
 *
 * @phpstan-import-type HomeLeadSection from HomeSections
 */
class LeadHomeSection implements HomeSections
{
    public function __construct(private DemoUserQuery $demoUsers) {}

    /**
     * @return HomeLeadSection|null
     */
    public function section(AccessContext $context): ?array
    {
        if ($context->userId !== null || $this->demoUsers->candidatesOn($context->date, $context->localeCode) === []) {
            return null;
        }

        return [
            'code' => 'demo-lead',
            'kind' => 'lead',
            'area' => 'hero',
            'text' => '申請 → 課長 → 部長の承認の流れを、利用者を切り替えながら試せます。',
            'action' => ['label' => '利用者を選択して試す', 'url' => '#'.PeopleHomeSection::CODE, 'method' => 'get', 'data' => []],
        ];
    }
}
