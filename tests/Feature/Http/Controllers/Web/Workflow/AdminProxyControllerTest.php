<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\ProxyRules;
use App\Models\Workflow\WorkflowProxy;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 代理の管理の入口 workflow.admin.proxies.*(docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.1 節、案件の運用/案件の操作/操作.md の 10.9 節、
 * 案件の運用/認可と一覧.md の 4 節): ゲストとシステム管理者でない利用者はルートの認可が 403(行の 404 と形の誤りより先)、システム管理者は
 * 入力の代理元で追加し(登録した人 = 自分)、どの行も消せる(無い行は 404)。成功は元の画面(Referer も直前の URL も無ければ代理の管理)へ戻りトースト。
 * 今日 = 2026-10-13。初期データの代理は消してから始める(テストの TX の中)。
 */
final class AdminProxyControllerTest extends TransactionalTestCase
{
    use RegistersProxies;
    use RunsWorkflowOperations;

    private const URL = '/workflow/admin/proxies';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo($this->operationDay()->setTime(12, 0));
        WorkflowProxy::query()->delete();
    }

    /**
     * ゲストとシステム管理者でない利用者(自分の行を含む): 一覧・追加・削除(自分の・ほかの・無い行)のどれもルートの認可が拒む。
     */
    #[DataProvider('outsiders')]
    public function test_the_route_authorization_rejects_everyone_but_the_system_administrator(?string $userCode): void
    {
        $user = $userCode === null ? $this->seededUser('u0002') : $this->seededUser($userCode);
        $own = $this->addProxy($user, $this->seededUser('u0008'));
        $others = $this->addProxy($this->seededUser('u0010'), $this->seededUser('u0008'));

        if ($userCode !== null) {
            $this->actingAs($user);
        }

        $this->get(self::URL)->assertForbidden();
        $this->post(self::URL, [...$this->payload(), 'principal_user_id' => 'abc'])->assertForbidden();
        $this->delete(self::URL."/{$own->id}")->assertForbidden();
        $this->delete(self::URL."/{$others->id}")->assertForbidden();
        $this->delete(self::URL.'/'.($others->id + 1000))->assertForbidden();
        $this->assertSame(2, WorkflowProxy::query()->count());
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function outsiders(): array
    {
        return ['guest' => [null], 'user u0002' => ['u0002']];
    }

    /**
     * システム管理者: 入力の代理元で追加し(登録した人 = 自分)、自分の行もほかの人の行も 303 で消え、無い行は 404。
     */
    public function test_the_system_administrator_adds_for_a_principal_and_deletes_any_row(): void
    {
        $admin = $this->seededUser('u0004');
        $own = $this->addProxy($admin, $this->seededUser('u0008'));
        $others = $this->addProxy($this->seededUser('u0010'), $this->seededUser('u0008'));
        $this->actingAs($admin);

        $this->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Workflow/Admin/Proxies')->where('proxies.total', 2));
        $this->from(self::URL)->post(self::URL, $this->payload())->assertStatus(302)->assertRedirect(self::URL)
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '代理を追加しました']);
        $added = WorkflowProxy::query()->whereNotIn('id', [$own->id, $others->id])->sole();
        $this->delete(self::URL.'/'.($added->id + 1000))->assertNotFound();
        $this->delete(self::URL."/{$own->id}")->assertStatus(303)->assertRedirect(self::URL)
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '代理を削除しました']);
        $this->delete(self::URL."/{$others->id}")->assertStatus(303)->assertRedirect(self::URL);

        $this->assertSame(
            [$this->seededUser('u0016')->id, $this->seededUser('u0003')->id, $admin->id],
            [$added->principal_user_id, $added->proxy_user_id, $added->registered_by_user_id],
        );
        $this->assertSame([$added->id], WorkflowProxy::query()->pluck('id')->all());
    }

    /**
     * 追加・削除の成功は元の画面へ戻る(絞り込みとページのクエリを残す = 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.9 節の「元の画面」)。
     */
    public function test_adding_and_deleting_return_to_the_filtered_page(): void
    {
        $this->actingAs($this->seededUser('u0004'));
        $row = $this->addProxy($this->seededUser('u0010'), $this->seededUser('u0008'));
        $added = self::URL.'?active=1&page=2';
        $deleted = self::URL.'?kind=approve&principal='.$this->seededUser('u0010')->id;

        $this->from($added)->post(self::URL, $this->payload())->assertStatus(302)->assertRedirect($added);
        $this->from($deleted)->delete(self::URL."/{$row->id}")->assertStatus(303)->assertRedirect($deleted);
    }

    /**
     * 代理元が無い・形の誤りは「代理元を選択してください」(元の画面へ戻り、何も書かない)。
     */
    public function test_a_missing_principal_returns_to_the_form_with_its_key(): void
    {
        $this->actingAs($this->seededUser('u0004'));
        $post = fn (array $values) => $this->from(self::URL)->post(self::URL, [...$this->payload(), ...$values])
            ->assertRedirect(self::URL);

        $required = ['principal_user_id' => ProxyRules::PRINCIPAL_REQUIRED];
        $post(['principal_user_id' => null])->assertSessionHasErrors($required);
        $post(['principal_user_id' => 'abc'])->assertSessionHasErrors($required);
        $this->assertSame(0, WorkflowProxy::query()->count());
    }

    /**
     * {proxy} が [0-9]{1,18} に合わない値はルートに当たらない。
     */
    public function test_a_proxy_argument_out_of_the_form_matches_no_route(): void
    {
        $this->actingAs($this->seededUser('u0004'));

        $this->delete(self::URL.'/abc')->assertNotFound();
    }

    /**
     * 代理元 u0016 → 代理先 u0003、承認・プロジェクト計画の承認・今日から 2026-10-18 まで(設計/ワークフロー/案件の運用/初期データ.md の 4.1 節の管理者が登録した行の形)。
     *
     * @return array<string, int|string|null>
     */
    private function payload(): array
    {
        return [
            'principal_user_id' => $this->seededUser('u0016')->id, 'proxy_user_id' => $this->seededUser('u0003')->id,
            'kind' => 'approve', 'workflow_flow_code' => 'project_plan',
            'start_date' => '2026-10-13', 'end_date' => '2026-10-18',
        ];
    }
}
