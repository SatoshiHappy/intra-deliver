<?php

namespace App\Actions\Authz;

use App\Actions\Master\WritesCommonMaster;
use App\Enums\Authz\ServiceAction;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Queries\Authz\RouteResources;
use Illuminate\Validation\ValidationException;

/**
 * ルートの画面・処理のリソースを作る(docs の 設計/メニュー/認可との結び付け.md 2.3・2.4)。ルートの名前を受け、リソースグループ ID =
 * http-services. + 名前、親 = 末端を除いた接頭辞のリソースグループ、URI = service:// + 名前の . を / に換えたもの、タイプ service で
 * {@see CreateResource} を通す(並び順は 0)。リソースは初期データで作る(ルートから実行時に作らない)。
 *
 * 止める規則: 名前がアプリのルートにある。名前の規則({@see RouteResources::followsNameRules()})に合う。親(接頭辞のリソースグループ)がある。
 * リソースの規則(URI のバイト数。ID・URI の文字数は DB が止める)は {@see CreateResource}。
 */
class CreateRouteResource
{
    use WritesCommonMaster;

    public function __construct(
        private RouteResources $routeResources,
        private CreateResource $createResource,
    ) {}

    /**
     * @param  array<string, array{display_name: string, description?: string|null}>  $translations  ロケールのコード → 表示名・説明
     *
     * @throws ValidationException 規則に反するとき
     */
    public function execute(string $routeName, array $translations): AuthzResource
    {
        if (! $this->routeResources->hasAppRouteNamed($routeName)) {
            throw ValidationException::withMessages(['route_name' => ["その名前のアプリのルートがありません: {$routeName}"]]);
        }

        if (! $this->routeResources->followsNameRules($routeName)) {
            throw ValidationException::withMessages(['route_name' => ["ルートの名前の規則に一致しません: {$routeName}"]]);
        }

        return $this->writeCommonMaster(function () use ($routeName, $translations) {
            $parentCode = $this->routeResources->parentResourceGroupCodeOf($routeName);
            $parentId = AuthzResourceGroup::query()->where('code', $parentCode)->value('id')
                ?? throw ValidationException::withMessages(['route_name' => ["接頭辞のリソースグループがありません: {$parentCode}"]]);

            return $this->createResource->execute(
                $this->routeResources->resourceGroupCodeOf($routeName),
                $parentId,
                ServiceAction::RESOURCE_TYPE,
                $this->routeResources->uriOf($routeName),
                $translations,
            );
        });
    }
}
