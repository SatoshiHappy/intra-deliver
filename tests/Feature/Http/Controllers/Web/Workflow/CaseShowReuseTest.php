<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Models\Authz\Role;
use App\Models\Authz\UserRole;
use App\Models\Master\User;
use App\Queries\Workflow\CaseShowProps;
use App\Queries\Workflow\CaseShowSelection;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\TransactionalTestCase;

/**
 * 申請の詳細の判定の使い回し(docs の 設計/共通マスタ/ロールと認可/認可の確認/判定の使い回し.md 3.4、
 * 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 4.2 節): 最初の組み立てはまとまりの中で同じ判定を 1 回にし、
 * 組み立ての後の判定は使い回さない区間の中で、今の設定と今の判定を求める者の事実で判定する。見本の案件を使う。
 */
final class CaseShowReuseTest extends TransactionalTestCase
{
    use UsesSeededCases;

    /** 見本の詳細(WF-000003 を課長 u0002 が開く全体の応答)の問い合わせの上限(測った値 417 に余裕を見た値。最初の組み立てを使い回さないと 500 本を超える) */
    private const SEEDED_FULL_RESPONSE_QUERY_LIMIT = 460;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * 見本の詳細の全体の応答の問い合わせの数が上限の中(最初の組み立ての判定の使い回しが効いている)。
     */
    public function test_the_seeded_full_response_stays_within_the_query_limit(): void
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $this->showOf('u0002', 3);

        $this->assertLessThanOrEqual(self::SEEDED_FULL_RESPONSE_QUERY_LIMIT, $count);
    }

    /**
     * ふつうの画面の要求の中で、組み立ての途中(履歴を読んだ直後)にフローの閲覧を与えるロールを外すと、組み立ての後の判定が今のロールで判定して 404 になる
     * (区間の外の判定なら、要求の中で覚えた事実のまま閲覧できてしまう)。システム管理者 u0004 はフローの閲覧だけで WF-000005 を見る。
     */
    public function test_a_role_removed_during_the_assembly_of_a_request_is_not_found(): void
    {
        $case = $this->caseNumbered(5);
        $user = User::query()->where('code', 'u0004')->sole();
        $role = Role::query()->where('code', 'system_admin')->sole();
        $this->actingAs($user);
        $this->get("/workflow/cases/{$case}")->assertOk();
        $removed = false;
        DB::listen(function (QueryExecuted $query) use (&$removed, $user, $role): void {
            $histories = 'select * from "workflow_case_histories" where "workflow_case_id" = ?';

            if (! $removed && str_starts_with($query->sql, $histories)) {
                $removed = true;
                UserRole::query()->where('user_id', $user->id)->where('role_id', $role->id)->delete();
            }
        });

        $response = $this->get("/workflow/cases/{$case}");

        $this->assertTrue($removed, '最初の閲覧の判定を通って履歴を読んだ');
        $response->assertNotFound();
    }

    /**
     * 案件番号 WF-00000N の申請の詳細の全体の応答の props(画面の要求を通さずに組む)。
     *
     * @return array<string, mixed>
     */
    private function showOf(string $userCode, int $number): array
    {
        $user = User::query()->where('code', $userCode)->sole();
        $context = new AccessContext($user->id, null, null, CarbonImmutable::today(), AccessContext::LOCALE_CODE);

        return app(CaseShowProps::class)->of($context, $this->caseNumbered($number), new CaseShowSelection);
    }
}
