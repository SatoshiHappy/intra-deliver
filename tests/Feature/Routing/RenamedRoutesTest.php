<?php

namespace Tests\Feature\Routing;

use App\Models\Master\User;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\AuthorizationQuery;
use App\Queries\Authz\RouteResources;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Support\TransactionalTestCase;

/**
 * ルートの束の規則で改名した 27 本(docs の work の事実の README の 2 節の表、ADR 0033 の決定 6)。URL とメソッドは改名の前のまま。
 * ルートの認可は今のまま: システム管理者は許され(先頭の許可の継承)、ゲストと u0001 は 403。
 * 画面と処理が 200 になることは、各ルートの入口のテスト(名前を直したもの)が確かめる。
 */
final class RenamedRoutesTest extends TransactionalTestCase
{
    /** 新しい名前 => [メソッド, URI(改名の前のまま)] */
    private const RENAMED = [
        'authz.settings.show' => ['GET', 'authz/overview'],
        'authz.settings.update-cell' => ['PUT', 'authz/editor/cells'],
        'authz.settings.store-subject' => ['POST', 'authz/editor/subjects'],
        'authz.settings.destroy-subject' => ['DELETE', 'authz/editor/subjects/{subject}'],
        'authz.settings.subject-candidates' => ['GET', 'authz/editor/subject-candidates'],
        'records.admin.modules.create-field' => ['GET', 'records/admin/modules/{module}/fields/create'],
        'records.admin.modules.store-field' => ['POST', 'records/admin/modules/{module}/fields'],
        'records.admin.modules.edit-field' => ['GET', 'records/admin/modules/{module}/fields/{field}/edit'],
        'records.admin.modules.update-field' => ['PUT', 'records/admin/modules/{module}/fields/{field}'],
        'records.admin.modules.destroy-field' => ['DELETE', 'records/admin/modules/{module}/fields/{field}'],
        'records.admin.modules.reorder-fields' => ['PUT', 'records/admin/modules/{module}/fields/reorder'],
        'records.admin.modules.store-heading' => ['POST', 'records/admin/modules/{module}/headings'],
        'records.admin.modules.update-heading' => ['PUT', 'records/admin/modules/{module}/headings/{heading}'],
        'records.admin.modules.destroy-heading' => ['DELETE', 'records/admin/modules/{module}/headings/{heading}'],
        'records.admin.modules.update-related-columns' => ['PUT', 'records/admin/modules/{module}/related-columns'],
        'records.admin.modules.store-rule' => ['POST', 'records/admin/modules/{module}/rules'],
        'records.admin.modules.update-rule' => ['PUT', 'records/admin/modules/{module}/rules/{rule}'],
        'records.admin.modules.destroy-rule' => ['DELETE', 'records/admin/modules/{module}/rules/{rule}'],
        'records.admin.categories.reorder-uncategorized' => ['PUT', 'records/admin/modules/reorder'],
        'records.admin.categories.update-module-categories' => ['PUT', 'records/admin/modules/{module}/categories'],
        'workflow.admin.flows.update-referrers' => ['PUT', 'workflow/admin/flows/{flow}/referrers'],
        'workflow.admin.flows.update-settings' => ['PUT', 'workflow/admin/flows/{flow}/settings'],
        'workflow.admin.flows.store-version' => ['POST', 'workflow/admin/flows/{flow}/versions'],
        'workflow.admin.flows.disable-version' => ['POST', 'workflow/admin/flows/{flow}/versions/{version}/disable'],
        'workflow.admin.flows.enable-version' => ['POST', 'workflow/admin/flows/{flow}/versions/{version}/enable'],
        'workflow.admin.flows.update-node' => ['PUT', 'workflow/admin/flows/{flow}/versions/{version}/nodes/{node}'],
        'workflow.admin.flows.subject-candidates' => ['GET', 'workflow/admin/subject-candidates'],
    ];

    /** URL を作るときの引数(ルートの引数の形に合う値。ルートの認可は引数の値を見ない) */
    private const PARAMETERS = [
        'subject' => '1', 'module' => 'project', 'field' => 'name', 'heading' => '1', 'rule' => '1',
        'flow' => 'project_plan', 'version' => '1', 'node' => 'apply',
    ];

    public function test_the_renamed_routes_keep_their_methods_and_urls(): void
    {
        $actual = [];

        foreach (array_keys(self::RENAMED) as $name) {
            $route = Route::getRoutes()->getByName($name);
            $actual[$name] = [$route?->methods()[0], $route?->uri()];
        }

        $this->assertSame(self::RENAMED, $actual);
    }

    public function test_the_system_administrator_is_permitted_every_renamed_route(): void
    {
        $administrator = new Requester(User::query()->where('code', 'u0004')->sole()->id, null);

        $this->assertSame([], $this->namesNotPermittedTo($administrator));
    }

    public function test_a_guest_and_a_general_user_are_forbidden_every_renamed_route(): void
    {
        $forbidden = array_fill_keys(array_keys(self::RENAMED), 403);

        $this->assertSame($forbidden, $this->statuses(), 'ゲスト');

        $this->actingAs(User::query()->where('code', 'u0001')->sole());

        $this->assertSame($forbidden, $this->statuses(), 'u0001');
    }

    /**
     * 改名したルートへ送った要求の応答の状態(引数は {@see self::PARAMETERS})。
     *
     * @return array<string, int>
     */
    private function statuses(): array
    {
        $statuses = [];
        $placeholders = array_map(fn (string $key) => '{'.$key.'}', array_keys(self::PARAMETERS));

        foreach (array_keys(self::RENAMED) as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertInstanceOf(RoutingRoute::class, $route);
            $url = '/'.str_replace($placeholders, array_values(self::PARAMETERS), $route->uri());
            $statuses[$name] = $this->json($route->methods()[0], $url)->status();
        }

        return $statuses;
    }

    /**
     * ルートの認可(AuthorizationQuery::allowsUri)で許されない改名したルートの名前。
     *
     * @return list<string>
     */
    private function namesNotPermittedTo(Requester $requester): array
    {
        $authorization = app(AuthorizationQuery::class);
        $routeResources = app(RouteResources::class);
        $today = CarbonImmutable::today();

        return array_values(array_filter(
            array_keys(self::RENAMED),
            fn (string $name) => ! $authorization->allowsUri(
                $requester,
                $routeResources->uriOf($name),
                'execute',
                $today,
            ),
        ));
    }
}
