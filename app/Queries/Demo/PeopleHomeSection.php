<?php

namespace App\Queries\Demo;

use App\Queries\Menu\Api\HomeSections;
use App\Support\AccessContext;

/**
 * ゲストのホームの役割カード(docs の 設計/デモユーザー切替/デモの取り付け.md 6.3。口と形は main の {@see HomeSections} = 設計/メニュー/ホーム.md 6.2)。
 * 起動(App\Providers の Demo のプロバイダ)が、設定がオンのときだけタグ {@see HomeSections::TAG} に登録する。
 * 今の利用者がいる・候補({@see DemoUserQuery})が 1 人もいなければ null。項目 = 候補 1 人に 1 つ(候補の並び)。code = ユーザーコード、
 * name = d・ロケールの氏名(無ければ null)、affiliation = 主所属の組織名と役職名、role = 役どころ、note = 試せること、
 * action = 切替のルート(名前 demo.switch-user から作る URL)へ POST で {user_id}(利用者メニューの項目と同じ送り先)。
 *
 * @phpstan-import-type HomePeopleSection from HomeSections
 */
class PeopleHomeSection implements HomeSections
{
    /** 節の code(あいさつ欄の主ボタンのページ内リンクの行き先 = {@see LeadHomeSection}) */
    public const CODE = 'demo-people';

    public function __construct(private DemoUserQuery $demoUsers) {}

    /**
     * @return HomePeopleSection|null
     */
    public function section(AccessContext $context): ?array
    {
        if ($context->userId !== null) {
            return null;
        }

        $candidates = $this->demoUsers->candidatesOn($context->date, $context->localeCode);

        if ($candidates === []) {
            return null;
        }

        $affiliations = $this->demoUsers->affiliationsOn($candidates, $context->date, $context->localeCode);

        return [
            'code' => self::CODE,
            'kind' => 'people',
            'area' => 'top',
            'heading' => '試せる利用者',
            'noteLabel' => 'この人で試せること',
            'items' => array_map(fn (DemoUser $user) => [
                'code' => $user->code,
                'name' => $user->name,
                'affiliation' => $affiliations[$user->id],
                'role' => $user->role,
                'note' => $user->can,
                'action' => ['label' => 'この人で始める', 'url' => route('demo.switch-user'), 'method' => 'post', 'data' => ['user_id' => $user->id]],
            ], $candidates),
        ];
    }
}
