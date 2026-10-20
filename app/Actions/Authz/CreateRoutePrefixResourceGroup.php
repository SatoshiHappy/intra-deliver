<?php

namespace App\Actions\Authz;

use App\Actions\Master\WritesCommonMaster;
use App\Models\Authz\AuthzResourceGroup;
use App\Queries\Authz\RouteResources;
use Illuminate\Validation\ValidationException;

/**
 * ルートの名前の接頭辞のリソースグループを作る(docs の 設計/メニュー/認可との結び付け.md 2.3 の間の階層・2.4)。
 * 接頭辞を受け、リソースグループ ID = http-services. + 接頭辞、親 = 1 つ短い接頭辞のリソースグループ(最も短い接頭辞はセット http-services)
 * で {@see CreateResourceGroup} を通す(並び順は 0)。表示名は、そのルートを持つモジュールの初期データが渡す。
 *
 * 止める規則: 接頭辞の形(区切り [a-z0-9_-]+ を . でつないだもの)。接頭辞がアプリのルートの名前なら拒む(リソースを持つのは階層の末端だけ)。
 * 親が無ければ拒む。リソースグループの規則(親の閉塞の写し)は {@see CreateResourceGroup}(ID の長さは DB が止める)。
 */
class CreateRoutePrefixResourceGroup
{
    use WritesCommonMaster;

    public function __construct(
        private RouteResources $routeResources,
        private CreateResourceGroup $createResourceGroup,
    ) {}

    /**
     * @param  array<string, array{display_name: string, description?: string|null}>  $translations  ロケールのコード → 表示名・説明
     *
     * @throws ValidationException 規則に反するとき
     */
    public function execute(string $prefix, array $translations): AuthzResourceGroup
    {
        if (! $this->routeResources->isPrefixForm($prefix)) {
            throw ValidationException::withMessages(['prefix' => ["ルートの名前の接頭辞の形ではありません: {$prefix}"]]);
        }

        if ($this->routeResources->hasAppRouteNamed($prefix)) {
            throw ValidationException::withMessages(['prefix' => ["アプリのルートの名前は接頭辞のリソースグループにできません: {$prefix}"]]);
        }

        return $this->writeCommonMaster(function () use ($prefix, $translations) {
            $parentCode = $this->routeResources->parentResourceGroupCodeOf($prefix);
            $parentId = AuthzResourceGroup::query()->where('code', $parentCode)->value('id')
                ?? throw ValidationException::withMessages(['prefix' => ["親のリソースグループがありません: {$parentCode}"]]);

            return $this->createResourceGroup->execute($this->routeResources->resourceGroupCodeOf($prefix), $parentId, $translations);
        });
    }
}
