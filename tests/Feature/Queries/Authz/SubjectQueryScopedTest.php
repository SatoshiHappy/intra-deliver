<?php

namespace Tests\Feature\Queries\Authz;

use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\CreateRoutePrefixResourceGroup;
use App\Actions\Authz\CreateRouteResource;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\SubjectCombination;
use App\Http\Middleware\Authz\AuthorizeRoute;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Master\Department;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\AuthorizationQuery;
use App\Queries\Authz\SubjectQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 判定を求める者の d の事実を、同じ要求の中では別々に解決した判定の口の間でも使い回す(SubjectQuery をコンテナの scoped にする =
 * docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 3.2)。事実を作る問い合わせは、d に有効なユーザーの読みで数える。
 * テストの基底が要求ごとに scoped の実体を捨てることも、ここで確かめる。
 */
final class SubjectQueryScopedTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    private const DATE = '2024-04-01';

    private User $user;

    private Department $department;

    private AuthzResourceGroup $resourceGroup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = $this->department(DepartmentSet::factory()->create(['is_default' => true]));
        $this->user = $this->user();
        $this->join($this->user, $this->department, isMain: true);
        $this->resourceGroup = AuthzResourceGroup::factory()->create();
        AuthzResource::factory()->create([
            'authz_resource_group_id' => $this->resourceGroup->id,
            'resource_type' => 'im-menu-group',
        ]);
        $subjectGroup = AuthzSubjectGroup::factory()->create(['combination' => SubjectCombination::Any]);
        AuthzSubjectCondition::factory()->create([
            'authz_subject_group_id' => $subjectGroup->id,
            'user_id' => $this->user->id,
        ]);
        AuthzPolicy::factory()->create([
            'authz_resource_group_id' => $this->resourceGroup->id,
            'authz_subject_group_id' => $subjectGroup->id,
            'resource_type' => 'im-menu-group',
            'action' => 'read',
            'effect' => PolicyEffect::Permit,
        ]);
    }

    public function test_two_resolved_authorization_queries_build_the_subject_once(): void
    {
        $first = app(AuthorizationQuery::class);
        $second = app(AuthorizationQuery::class);

        $reads = $this->effectiveUserReadsDuring(function () use ($first, $second): void {
            $this->assertTrue($this->allows($first, $this->requester()));
            $this->assertTrue($this->allows($second, $this->requester()));
        });

        $this->assertNotSame($first, $second);
        $this->assertSame(1, $reads);
    }

    #[TestWith(['user'])]
    #[TestWith(['department'])]
    #[TestWith(['ipv4'])]
    #[TestWith(['date'])]
    #[TestWith(['forgotten'])]
    public function test_builds_again_for_another_requester_or_date_or_after_forgetting(string $differs): void
    {
        $this->allows(app(AuthorizationQuery::class), $this->requester());
        $requester = match ($differs) {
            'user' => new Requester($this->user()->id, '192.168.0.1'),
            'department' => new Requester($this->user->id, '192.168.0.1', $this->department->id),
            'ipv4' => new Requester($this->user->id, '192.168.0.2'),
            default => $this->requester(),
        };
        $date = $differs === 'date' ? '2024-04-02' : self::DATE;

        if ($differs === 'forgotten') {
            $this->resetRequestScope();
        }

        $second = app(AuthorizationQuery::class);
        $reads = $this->effectiveUserReadsDuring(
            fn () => $this->allows($second, $requester, $date),
        );

        $this->assertSame(1, $reads);
    }

    /**
     * 1 つの要求の中で 2 人の判定を求める者を交互に判定しても、それぞれの事実で判定する(覚えのキーが判定を求める者ごと)。
     */
    public function test_two_requesters_in_one_request_are_judged_on_their_own_facts(): void
    {
        $other = new Requester($this->user()->id, '192.168.0.1');
        $judge = fn (Requester $requester) => $this->allows(app(AuthorizationQuery::class), $requester);

        $this->assertSame(
            [true, false, true, false],
            [$judge($this->requester()), $judge($other), $judge($this->requester()), $judge($other)],
        );
    }

    /**
     * テストの基底: 1 つのテストの中で、要求 → 所属の書き換え → 要求の順にすると、2 回目の要求は書き換えた後の事実で判定する
     * (テストが送る要求ごとに scoped の実体を捨てる = 本番の要求ごとの寿命)。許可は所属する組織への許可。
     */
    public function test_a_second_request_after_changing_the_membership_is_judged_on_the_new_facts(): void
    {
        Route::middleware(['web', AuthorizeRoute::class])
            ->get('/test/departmental', fn () => 'ok')->name('test.departmental');
        app(CreateRoutePrefixResourceGroup::class)->execute('test', []);
        $resource = app(CreateRouteResource::class)->execute('test.departmental', []);
        $subjectGroup = app(FindOrCreateSingleConditionSubjectGroup::class)
            ->execute(new SubjectConditionInput(departmentId: $this->department->id), []);
        app(CreatePolicy::class)->execute(
            $resource->authz_resource_group_id, $subjectGroup->id, 'service', 'execute', PolicyEffect::Permit,
        );

        $this->actingAs($this->user)->get('/test/departmental')->assertOk();
        DepartmentMembership::query()->where('user_id', $this->user->id)->delete();
        $this->actingAs($this->user)->get('/test/departmental')->assertForbidden();
    }

    /**
     * テストの基底: 要求の中で作った scoped の実体は、要求の後のテストのコードに残らない(要求の後にも寿命を区切る)。
     */
    public function test_the_scoped_instance_made_in_a_request_does_not_stay_after_the_request(): void
    {
        $inRequest = null;
        Route::get('/test/scoped', function () use (&$inRequest) {
            $inRequest = app(SubjectQuery::class);

            return 'ok';
        });

        $this->get('/test/scoped')->assertOk();

        $this->assertInstanceOf(SubjectQuery::class, $inRequest);
        $this->assertNotSame($inRequest, app(SubjectQuery::class));
    }

    /**
     * テストの基底: ルートが覚えたコントローラ(注入した判定の口と事実ごと)も、要求の後に捨てる(同じルートへの次の要求は作り直す)。
     * 先にルートにコントローラを覚えさせ、要求の後に同じルートから消えていることを見る。
     */
    public function test_the_controller_kept_by_the_route_is_flushed_after_the_request(): void
    {
        $route = Route::getRoutes()->getByName('menu.sitemap');
        $this->assertNotNull($route);
        $route->getController();
        $this->assertNotNull($route->controller);

        $this->get(route('menu.sitemap'))->assertOk();

        $this->assertNull($route->controller);
    }

    private function allows(AuthorizationQuery $query, Requester $requester, string $date = self::DATE): bool
    {
        return $query->allows($requester, $this->resourceGroup->id, 'read', self::day($date));
    }

    private function requester(): Requester
    {
        return new Requester($this->user->id, '192.168.0.1');
    }

    /**
     * 処理の間に出た、d に有効なユーザーの読み(判定を求める者の事実を作るたびに 1 回)の数。
     */
    private function effectiveUserReadsDuring(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $callback();
            $sql = array_map(fn (array $query) => strtolower($query['query']), DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        return count(preg_grep('/from "users" where users\.id = any/', $sql) ?: []);
    }
}
