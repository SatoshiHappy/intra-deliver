<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Models\Authz\Role;
use App\Models\Authz\UserRole;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\TransactionalTestCase;

/**
 * 案件からの複製の組み立ての後の判定(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/README.md の 12.3 節の
 * 「判定の後」): 使い回さない区間の中で、今のロールで判定する。見本の案件を使う。
 */
final class CaseCopyReuseTest extends TransactionalTestCase
{
    use UsesSeededCases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * 組み立ての途中(写す元の版を読んだ直後)にフローの閲覧を与えるロールを外すと、組み立ての後の判定が今のロールで判定して 404 になる
     * (区間の外の判定なら、要求の中で覚えた事実のまま写しの初期値が返る)。システム管理者 u0004 はフローの閲覧で WF-000005 を見る。
     */
    public function test_a_role_removed_during_the_assembly_is_not_found(): void
    {
        $case = WorkflowCase::query()->findOrFail($this->caseNumbered(5));
        $flowCode = WorkflowFlow::query()->findOrFail($case->workflow_flow_id)->code;
        $user = User::query()->where('code', 'u0004')->sole();
        $role = Role::query()->where('code', 'system_admin')->sole();
        $url = "/workflow/flows/{$flowCode}/apply?copy={$case->id}";
        $this->actingAs($user);
        $this->get($url)->assertOk();
        $removed = false;
        DB::listen(function (QueryExecuted $query) use (&$removed, $user, $role): void {
            if (! $removed && str_contains($query->sql, 'from "record_revisions"')) {
                $removed = true;
                UserRole::query()->where('user_id', $user->id)->where('role_id', $role->id)->delete();
            }
        });

        $response = $this->get($url);

        $this->assertTrue($removed, '写す元の版を読んだ');
        $response->assertNotFound();
    }
}
