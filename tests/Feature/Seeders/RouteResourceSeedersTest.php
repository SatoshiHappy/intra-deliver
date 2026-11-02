<?php

namespace Tests\Feature\Seeders;

use App\Enums\Authz\ServiceAction;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzSubjectGroup;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\AuthorizationQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\FindsAppRouteViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 初期データを入れた後のルートのリソースの付け忘れ・登録もれと置き場の検査(docs の 設計/メニュー/認可との結び付け.md 2.3 の検査 2・3・5)。
 * 検査 2 は、ルートのリソースのリソースグループ ID と親も、置き場と名前から決まるもの(2.3 の表)であることを確かめる
 * (判定は {@see FindsAppRouteViolations})。あわせて、2 つのセットの接頭辞が子を持ち、http-services のルートのリソースグループにポリシーと
 * 閉塞が無く、設定不可のセットのルートはゲストユーザか認証済みユーザの直接の許可を持ち、設定不可のセットの接頭辞にポリシーが無いこと(検査 2)と、
 * 全員に開いた http-services のルートの一覧が固定値と同じこと(検査 5)。ポリシーの中身と件数は {@see AuthorizationSeedersTest}。
 */
final class RouteResourceSeedersTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use FindsAppRouteViolations;

    public function test_every_app_route_has_its_service_resource_in_the_resource_groups_given_by_its_name(): void
    {
        $this->assertSame([], $this->routesWithoutTheirResource($this->routeResources()->appRoutes()));
    }

    /**
     * 2 つのセットの接頭辞のリソースグループ(リソースを持たないグループ)は、どれも子を持つ(呼び手が置き場を渡して作るので、子の無い接頭辞を止める)。
     */
    public function test_every_prefix_of_both_sets_has_children(): void
    {
        foreach (ServiceAction::SET_CODES as $set) {
            $childless = $this->prefixGroupsUnder($set)
                ->filter(fn (AuthzResourceGroup $group) => $group->children_count === 0)
                ->pluck('code')->all();

            $this->assertSame([], $childless, $set);
        }
    }

    /**
     * http-services のルートのリソースグループにはポリシーと閉塞を置かない(許可・禁止はルートの束かその上 = 2.3 のルートの束)。
     */
    public function test_no_route_of_http_services_has_a_policy_or_a_block(): void
    {
        $routeGroups = $this->routeGroupsUnder(ServiceAction::SET_CODE);
        $withPolicies = $routeGroups->filter(fn (AuthzResourceGroup $group) => $group->authz_policies_count > 0);
        $blocked = $routeGroups->filter(
            fn (AuthzResourceGroup $group) => $group->authz_resource_group_attributes_count > 0,
        );

        $this->assertSame(53, $routeGroups->count());
        $this->assertSame([], $withPolicies->pluck('code')->values()->all());
        $this->assertSame([], $blocked->pluck('code')->values()->all());
    }

    /**
     * 設定不可のセットのルートは、ルートのリソースグループにゲストユーザか認証済みユーザの直接の許可を持ち、接頭辞にはポリシーが無い
     * (管理者だけのルートを誤って設定不可と宣言すると落ちる)。
     */
    public function test_every_unmanaged_route_permits_the_guest_or_the_authenticated_and_no_prefix_has_a_policy(): void
    {
        $metaSubjects = ['anonymous', 'authenticated'];
        $everyone = AuthzSubjectGroup::query()->has('authzSubjectConditions', '=', 1)
            ->whereHas('authzSubjectConditions', fn ($query) => $query->whereIn('meta_subject', $metaSubjects))
            ->pluck('id')->all();
        $routeGroups = $this->routeGroupsUnder(ServiceAction::UNMANAGED_SET_CODE);
        $withoutPermit = $routeGroups->reject(fn (AuthzResourceGroup $group) => AuthzPolicy::query()
            ->where(['authz_resource_group_id' => $group->id, 'resource_type' => 'service', 'action' => 'execute'])
            ->where('effect', 'permit')->whereIn('authz_subject_group_id', $everyone)->exists());
        $prefixesWithPolicies = $this->prefixGroupsUnder(ServiceAction::UNMANAGED_SET_CODE)
            ->filter(fn (AuthzResourceGroup $group) => $group->authz_policies_count > 0);

        $this->assertCount(2, $everyone);
        $this->assertSame(60, $routeGroups->count());
        $this->assertSame([], $withoutPermit->pluck('code')->values()->all());
        $this->assertSame([], $prefixesWithPolicies->pluck('code')->values()->all());
    }

    /**
     * 検査 5: ゲストユーザか認証済みユーザ(ロールも所属も無い利用者)の判定が許可になる(継承を含む)http-services のルートの名前の一覧は
     * 固定値と同じ(全員に開いた束にルートを足す・上のフォルダに全員向けの許可を置くと落ちる。全員に開いてよいかを確かめて値を直す)。
     */
    public function test_the_routes_of_http_services_open_to_everyone_are_the_fixed_list(): void
    {
        $authorization = app(AuthorizationQuery::class);
        $routeResources = $this->routeResources();
        $requesters = [new Requester(null, null), new Requester($this->user()->id, null)];
        $today = CarbonImmutable::today();
        $isOpen = fn (string $name) => array_filter(
            $requesters,
            fn (Requester $requester) => $authorization->allowsUri(
                $requester,
                $routeResources->uriOf($name),
                'execute',
                $today,
            ),
        ) !== [];

        $open = array_values(array_filter(
            array_map(fn ($route) => (string) $route->getName(), $routeResources->appRoutes()),
            fn (string $name) => $routeResources->setOf($name) === ServiceAction::SET_CODE && $isOpen($name),
        ));

        $this->assertSame(['master.organization-chart'], $open);
    }

    /**
     * セットの配下の接頭辞のリソースグループ(リソースを持たないグループ。子の数・ポリシーの数つき)。
     *
     * @return Collection<int, AuthzResourceGroup>
     */
    private function prefixGroupsUnder(string $setCode): Collection
    {
        return $this->groupsUnder($setCode)
            ->filter(fn (AuthzResourceGroup $group) => $group->authzResource === null)->values();
    }

    /**
     * セットの配下のルートのリソースグループ(リソースを持つグループ。ポリシーの数・属性の数つき)。
     *
     * @return Collection<int, AuthzResourceGroup>
     */
    private function routeGroupsUnder(string $setCode): Collection
    {
        return $this->groupsUnder($setCode)
            ->filter(fn (AuthzResourceGroup $group) => $group->authzResource !== null)->values();
    }

    /**
     * セットの配下のリソースグループ(リソース・子の数・ポリシーの数・属性の数つき)。
     *
     * @return Collection<int, AuthzResourceGroup>
     */
    private function groupsUnder(string $setCode): Collection
    {
        $ids = [];

        for ($level = [AuthzResourceGroup::query()->where('code', $setCode)->sole()->id]; $level !== [];) {
            $level = AuthzResourceGroup::query()->whereIn('parent_id', $level)->pluck('id')->all();
            $ids = [...$ids, ...$level];
        }

        return AuthzResourceGroup::query()->whereIn('id', $ids)->with('authzResource')
            ->withCount(['children', 'authzPolicies', 'authzResourceGroupAttributes'])->orderBy('code')->get();
    }
}
