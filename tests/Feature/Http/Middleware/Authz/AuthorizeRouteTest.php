<?php

namespace Tests\Feature\Http\Middleware\Authz;

use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\CreateRoutePrefixResourceGroup;
use App\Actions\Authz\CreateRouteResource;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Enums\Authz\MetaSubject;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\ResourceGroupAttributeKey;
use App\Http\Middleware\Authz\AuthorizeRoute;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroupAttribute;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Support\AccessContext;
use Illuminate\Support\Facades\Route;
use Tests\Support\TransactionalTestCase;

/**
 * ルートの認可(docs の 設計/メニュー/認可との結び付け.md 2.3 の検査 4: 名前の無いルート・リソースの無いルート・許可の無いルート・閉塞したルートで
 * 403 を返し、許可のあるルートを通す。判定を求める者はゲスト)。判定を求める者を作るのは Requester::fromAccessContext の 1 か所(docs の 設計/メニュー/判定の入力.md 5 節)で、
 * セッションで選んだ組織が判定に入ることを、兼務の利用者と組織の条件の許可で確かめる。テストのルートは、配線と同じく web グループとこのミドルウェアを付けて足す。
 */
final class AuthorizeRouteTest extends TransactionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', AuthorizeRoute::class])->group(function (): void {
            Route::get('/test/unnamed', fn () => 'ok');
            Route::get('/test/without-resource', fn () => 'ok')->name('test.without_resource');
            Route::get('/test/without-permit', fn () => 'ok')->name('test.without_permit');
            Route::get('/test/permitted', fn () => 'ok')->name('test.permitted');
        });
    }

    public function test_passes_a_route_permitted_to_the_requester(): void
    {
        $this->permitGuestOn('test.permitted');

        $this->get('/test/permitted')->assertOk()->assertSee('ok');
    }

    public function test_forbids_a_route_without_a_name(): void
    {
        $this->get('/test/unnamed')->assertForbidden();
    }

    public function test_forbids_a_route_without_its_resource(): void
    {
        $this->permitGuestOn('test.permitted');

        $this->get('/test/without-resource')->assertForbidden();
    }

    public function test_forbids_a_route_without_a_permit_for_the_requester(): void
    {
        $this->resourceOf('test.without_permit');

        $this->get('/test/without-permit')->assertForbidden();
    }

    public function test_forbids_a_blocked_route_even_with_a_permit(): void
    {
        $resource = $this->permitGuestOn('test.permitted');
        AuthzResourceGroupAttribute::factory()->create(['authz_resource_group_id' => $resource->authz_resource_group_id, 'key' => ResourceGroupAttributeKey::Blocked->value, 'value' => 'ALL']);

        $this->get('/test/permitted')->assertForbidden();
    }

    public function test_passes_a_route_permitted_to_the_selected_department_of_a_concurrent_member(): void
    {
        $humanResources = $this->departmentId('human-resources');
        $this->permitDepartmentOn('test.permitted', $humanResources);

        $this->actingAs($this->concurrentMember())
            ->withSession([AccessContext::CURRENT_DEPARTMENT_SESSION_KEY => $humanResources])
            ->get('/test/permitted')
            ->assertOk();
    }

    public function test_forbids_a_route_permitted_to_another_department_than_the_selected_one(): void
    {
        $this->permitDepartmentOn('test.permitted', $this->departmentId('human-resources'));

        $this->actingAs($this->concurrentMember())
            ->withSession([AccessContext::CURRENT_DEPARTMENT_SESSION_KEY => $this->departmentId('general-affairs')])
            ->get('/test/permitted')
            ->assertForbidden();
    }

    private function permitGuestOn(string $routeName): AuthzResource
    {
        $resource = $this->resourceOf($routeName);
        $guest = app(FindOrCreateSingleConditionSubjectGroup::class)->execute(new SubjectConditionInput(metaSubject: MetaSubject::Anonymous), []);
        app(CreatePolicy::class)->execute($resource->authz_resource_group_id, $guest->id, 'service', 'execute', PolicyEffect::Permit);

        return $resource;
    }

    /**
     * 許可するのは兼務先(主所属でない組織)にする。主所属に許可すると、選んだ組織を判定に渡さなくても通ってしまうため。
     */
    private function permitDepartmentOn(string $routeName, int $departmentId): AuthzResource
    {
        $resource = $this->resourceOf($routeName);
        $department = app(FindOrCreateSingleConditionSubjectGroup::class)->execute(new SubjectConditionInput(departmentId: $departmentId), []);
        app(CreatePolicy::class)->execute($resource->authz_resource_group_id, $department->id, 'service', 'execute', PolicyEffect::Permit);

        return $resource;
    }

    /**
     * 兼務の利用者(初期データの u0009。主所属 = 総務部、兼務 = 人事部)。
     */
    private function concurrentMember(): User
    {
        return User::query()->where('code', 'u0009')->sole();
    }

    private function departmentId(string $code): int
    {
        return Department::query()->where('code', $code)->sole()->id;
    }

    private function resourceOf(string $routeName): AuthzResource
    {
        app(CreateRoutePrefixResourceGroup::class)->execute('test', []);

        return app(CreateRouteResource::class)->execute($routeName, []);
    }
}
