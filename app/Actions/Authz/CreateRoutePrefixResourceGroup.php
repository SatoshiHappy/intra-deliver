<?php

namespace App\Actions\Authz;

use App\Actions\Master\WritesCommonMaster;
use App\Enums\Authz\ServiceAction;
use App\Models\Authz\AuthzResourceGroup;
use App\Queries\Authz\RouteResources;
use Illuminate\Validation\ValidationException;

/**
 * ルートの名前の接頭辞のリソースグループを作る(docs の 設計/メニュー/認可との結び付け.md 2.3 の間の階層・2.4)。
 * 接頭辞と置き場(2 つのセットのどちらか)を受け、リソースグループ ID = <セット>. + 接頭辞、親 = 1 つ短い接頭辞のリソースグループ
 * (最も短い接頭辞はセット)で {@see CreateResourceGroup} を通す(並び順は兄弟の最大 + 1)。表示名と置き場は、そのルートを持つモジュールの
 * 初期データが渡す(子の無い接頭辞は、初期データの後の検査が止める)。
 *
 * 止める規則: 置き場が 2 つのセットのどちらか。接頭辞の形(区切り [a-z0-9_-]+ を . でつないだもの)。接頭辞がアプリのルートの名前なら拒む
 * (リソースを持つのは階層の末端だけ)。親が無ければ拒む。リソースグループの規則(親の閉塞の写し)は {@see CreateResourceGroup}
 * (ID の長さは DB が止める)。
 */
class CreateRoutePrefixResourceGroup
{
    use WritesCommonMaster;

    public function __construct(
        private RouteResources $routeResources,
        private CreateResourceGroup $createResourceGroup,
    ) {}

    /**
     * @param  string  $set  置き場(ServiceAction::SET_CODES のどちらか)
     * @param  array<string, array{display_name: string, description?: string|null}>  $translations  ロケールのコード → 表示名・説明
     *
     * @throws ValidationException 規則に反するとき
     */
    public function execute(string $prefix, string $set, array $translations): AuthzResourceGroup
    {
        if (! in_array($set, ServiceAction::SET_CODES, true)) {
            throw ValidationException::withMessages(['set' => ["ルートのリソースを置くセットではありません: {$set}"]]);
        }

        if (! $this->routeResources->isPrefixForm($prefix)) {
            throw ValidationException::withMessages(['prefix' => ["ルートの名前の接頭辞の形ではありません: {$prefix}"]]);
        }

        if ($this->routeResources->hasAppRouteNamed($prefix)) {
            throw ValidationException::withMessages(['prefix' => ["アプリのルートの名前は接頭辞のリソースグループにできません: {$prefix}"]]);
        }

        return $this->writeCommonMaster(function () use ($prefix, $set, $translations) {
            $parentCode = $this->routeResources->parentResourceGroupCodeOf($set, $prefix);
            $parentId = AuthzResourceGroup::query()->where('code', $parentCode)->value('id')
                ?? throw ValidationException::withMessages(['prefix' => ["親のリソースグループがありません: {$parentCode}"]]);

            $code = $this->routeResources->resourceGroupCodeOf($set, $prefix);

            return $this->createResourceGroup->execute($code, $parentId, $translations);
        });
    }
}
