<?php

namespace Tests\Feature\Support;

use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\CreateRoutePrefixResourceGroup;
use App\Actions\Authz\CreateRouteResource;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Enums\Authz\MetaSubject;
use App\Enums\Authz\PolicyEffect;
use App\Http\Middleware\Authz\AuthorizeRoute;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 要求の判定の入力(docs の 設計/デモユーザー切替/今の利用者.md 5.4・5.9): ユーザー = ガードが引けた今の利用者、選んだ組織 = セッションの値
 * (どちらも整数でなければ null)。セッションの無い要求(web グループの外)では、選んだ組織は null。
 * 今日有効でない利用者は web グループのミドルウェアがその要求でガードから外すので、ユーザーは null(5.4)。
 *
 * テストのルートは、ルートの認可を外さずに足し(ゲストと認証済みの両方に許す)、ルートの認可が作った {@see AccessContext} の値を JSON で返す。
 */
final class AccessContextRequestTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');

        $respondWithTheContext = fn (Request $request): JsonResponse => response()->json([
            'userId' => AccessContext::of($request)->userId,
            'selectedDepartmentId' => AccessContext::of($request)->selectedDepartmentId,
        ]);
        Route::middleware(['web', AuthorizeRoute::class])->get('/test/access-context', $respondWithTheContext)->name('test.access_context');
        Route::middleware([AuthorizeRoute::class])->get('/test/access-context-without-session', $respondWithTheContext)->name('test.access_context_without_session');
        $this->permitEveryoneOn(['test.access_context', 'test.access_context_without_session']);
    }

    public function test_the_guest_has_no_user_and_no_selected_department(): void
    {
        $this->get('/test/access-context')->assertExactJson(['userId' => null, 'selectedDepartmentId' => null]);
    }

    public function test_the_user_is_the_user_on_the_guard(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get('/test/access-context')->assertExactJson(['userId' => $user->id, 'selectedDepartmentId' => null]);
    }

    public function test_the_selected_department_is_the_value_in_the_session(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->withSession([AccessContext::CURRENT_DEPARTMENT_SESSION_KEY => 12])
            ->get('/test/access-context')
            ->assertExactJson(['userId' => $user->id, 'selectedDepartmentId' => 12]);
    }

    public function test_a_selected_department_that_is_not_an_integer_is_null(): void
    {
        $this->actingAs($this->user())
            ->withSession([AccessContext::CURRENT_DEPARTMENT_SESSION_KEY => '12'])
            ->get('/test/access-context')
            ->assertJsonPath('selectedDepartmentId', null);
    }

    public function test_a_user_id_in_the_session_whose_user_does_not_exist_is_not_the_user(): void
    {
        $guard = Auth::guard();
        $this->assertInstanceOf(SessionGuard::class, $guard);

        $this->withSession([$guard->getName() => $this->user()->id + 1000])
            ->get('/test/access-context')
            ->assertExactJson(['userId' => null, 'selectedDepartmentId' => null]);
    }

    public function test_a_user_who_is_not_effective_today_is_logged_out_before_the_context_is_made(): void
    {
        $this->actingAs($this->user([['2020-01-01', '2026-10-10']]))
            ->get('/test/access-context')
            ->assertExactJson(['userId' => null, 'selectedDepartmentId' => null]);
    }

    public function test_a_request_without_a_session_has_no_selected_department(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->withSession([AccessContext::CURRENT_DEPARTMENT_SESSION_KEY => 12])
            ->get('/test/access-context-without-session')
            ->assertExactJson(['userId' => $user->id, 'selectedDepartmentId' => null]);
    }

    /**
     * ルートのリソースを作り、ゲストと認証済みの両方に実行を許す。
     *
     * @param  list<string>  $routeNames
     */
    private function permitEveryoneOn(array $routeNames): void
    {
        app(CreateRoutePrefixResourceGroup::class)->execute('test', []);
        $subjectGroups = array_map(
            fn (MetaSubject $metaSubject) => app(FindOrCreateSingleConditionSubjectGroup::class)->execute(new SubjectConditionInput(metaSubject: $metaSubject), []),
            [MetaSubject::Anonymous, MetaSubject::Authenticated],
        );

        foreach ($routeNames as $routeName) {
            $resource = app(CreateRouteResource::class)->execute($routeName, []);

            foreach ($subjectGroups as $subjectGroup) {
                app(CreatePolicy::class)->execute($resource->authz_resource_group_id, $subjectGroup->id, 'service', 'execute', PolicyEffect::Permit);
            }
        }
    }
}
