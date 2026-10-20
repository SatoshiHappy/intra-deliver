<?php

namespace App\Actions\Menu;

use App\Actions\Master\WritesCommonMaster;
use App\Enums\Menu\MenuType;
use App\Models\Menu\Menu;
use App\Queries\Authz\RouteResources;
use Illuminate\Validation\ValidationException;

/**
 * 親のメニューを指定して、メニューフォルダかメニューアイテムを足す(docs の 設計/メニュー/表.md 1.7 の 3)。種別は入力の型で決まる
 * (外部メニューリンクは作らない)。フォルダの URL は NULL、メソッドは get で作る。
 *
 * 止める規則: 親は種別 folder のメニューに限る(本家の「強制的にメニューフォルダに変換」はせずに拒む)。アイテムの URL は次のどちらか:
 * アプリの中のパス({@see RouteResources::isAppPath()})で、メソッドとパスで照合したアプリのルートがあるもの / 外部サイトの絶対 URL
 * (http:// か https:// で始まる)。どちらにも当たらない URL(// で始まるプロトコル相対の URL を含む)は拒む。
 * 階層の深さは制限しない。メニュー ID の一意・引数名の空文字は DB が止める。
 */
class AddMenu
{
    use CreatesMenus;
    use WritesCommonMaster;

    public function __construct(private RouteResources $routeResources) {}

    /**
     * @throws ValidationException 規則に反するとき、無いロケールのコードがあるとき
     */
    public function execute(int $parentId, MenuFolderInput|MenuItemInput $input): Menu
    {
        if ($input instanceof MenuItemInput) {
            $this->validateUrl($input);
        }

        return $this->writeCommonMaster(function () use ($parentId, $input) {
            $parent = Menu::query()->find($parentId);

            if ($parent?->type !== MenuType::Folder) {
                throw ValidationException::withMessages(['parent_id' => ['親はメニューフォルダにしてください']]);
            }

            return $this->createMenu($parent->id, $input);
        });
    }

    /**
     * アプリの中のパスの形でない URL は、照合({@see RouteResources::match()})が一致なしにする。
     *
     * @throws ValidationException URL がアプリのルートに合うパスでも外部サイトの絶対 URL でもないとき
     */
    private function validateUrl(MenuItemInput $input): void
    {
        if (str_starts_with($input->url, 'http://') || str_starts_with($input->url, 'https://')) {
            return;
        }

        if ($this->routeResources->match($input->method->value, $input->url) === null) {
            throw ValidationException::withMessages(['url' => ["URL は、メソッドとパスに一致するアプリのルートがあるアプリの中のパスか、外部サイトの絶対 URL にしてください: {$input->method->value} {$input->url}"]]);
        }
    }
}
