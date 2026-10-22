<?php

namespace Tests\Feature\Queries\Authz;

use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Master\User;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\AuthorizationQuery;
use App\Queries\Authz\ResourceTypeCatalog;
use Database\Seeders\SeedingDates;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 固定の期待値の木に無い初期データの対象者(ロール・認証状態)とリソースタイプも、まとめた判定と単独の判定で
 * 同じ結果になることを確かめる(docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 3.2)。
 */
final class AuthorizationQueryExplainEachSeedTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    public function test_explaining_every_pair_of_the_seed_at_once_agrees_with_explaining_each_pair_alone(): void
    {
        $actionsByGroup = $this->actionsByResourceGroup();

        foreach ($this->requesters() as $requester) {
            $this->assertEachAgreesWithAlone($requester, $actionsByGroup, SeedingDates::BASE);
        }
    }

    /**
     * すべての組を 1 回の explainEach で判定した結果と、組ごとの単独の explain が同じ
     * (判定を求める者の事実をそれぞれで組み立てるよう、別のインスタンスで呼ぶ)。
     *
     * @param  array<int, list<string>>  $actionsByGroup
     */
    private function assertEachAgreesWithAlone(Requester $requester, array $actionsByGroup, string $date): void
    {
        $day = self::day($date);
        $each = app(AuthorizationQuery::class)
            ->explainEach($requester, array_keys($actionsByGroup), self::everyAction($actionsByGroup), $day);
        $authorization = app(AuthorizationQuery::class);

        foreach ($actionsByGroup as $resourceGroupId => $groupActions) {
            foreach ($groupActions as $action) {
                $alone = $authorization->explain($requester, $resourceGroupId, $action, $day);
                $this->assertEquals($alone, $each[$resourceGroupId][$action], "{$resourceGroupId} {$action} {$date}");
            }
        }
    }

    /**
     * 判定するリソースグループ → 比べるアクション。リソースを持つものはそのタイプのアクション、
     * リソースを持たないもの・存在しないものは全タイプのアクション。
     *
     * @return array<int, list<string>>
     */
    private function actionsByResourceGroup(): array
    {
        $actionsByType = app(ResourceTypeCatalog::class)->actionsByResourceType();
        $resources = AuthzResource::query()->whereIn('resource_type', array_keys($actionsByType))->get();
        $this->assertEqualsCanonicalizing(
            array_keys($actionsByType),
            $resources->pluck('resource_type')->unique()->values()->all(),
            '初期データにリソースの無いリソースタイプがある',
        );
        $byGroup = [];

        foreach ($resources as $resource) {
            $byGroup[$resource->authz_resource_group_id] = $actionsByType[$resource->resource_type];
        }

        $everyAction = self::everyAction($actionsByType);
        $byGroup[AuthzResourceGroup::factory()->create()->id] = $everyAction;
        $byGroup[(int) AuthzResourceGroup::query()->max('id') + 1] = $everyAction;

        return $byGroup;
    }

    /**
     * 一般の利用者 u0011・システム管理者 u0004・ゲスト。
     *
     * @return list<Requester>
     */
    private function requesters(): array
    {
        return [
            new Requester(User::query()->where('code', 'u0011')->sole()->id, null),
            new Requester(User::query()->where('code', 'u0004')->sole()->id, null),
            new Requester(null, null),
        ];
    }

    /**
     * @param  array<array-key, list<string>>  $actionLists
     * @return list<string>
     */
    private static function everyAction(array $actionLists): array
    {
        return array_values(array_unique(array_merge(...array_values($actionLists))));
    }
}
