<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\ProxyRules;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowProxy;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 代理の設定の入口 workflow.proxies.*(docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.1 節、案件の運用/案件の操作/操作.md の 10.9 節、
 * 同時実行と検査.md の 13 節): ゲストは一覧が空で追加・削除は 403(形の誤りより先)、利用者(システム管理者を含む)は代理元 = 自分で追加し、
 * 代理元が自分の行だけを消せる(ほかの行・無い行は 404)。成功は代理の設定へ戻りトースト、規則の誤りは元の画面へ戻りキーにエラー。
 * 今日 = 2026-10-13。初期データの代理は消してから始める(テストの TX の中)。
 */
final class ProxyControllerTest extends TransactionalTestCase
{
    use RegistersProxies;
    use RunsWorkflowOperations;

    private const URL = '/workflow/proxies';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo($this->operationDay()->setTime(12, 0));
        WorkflowProxy::query()->delete();
    }

    public function test_a_guest_sees_empty_tables_and_cannot_add_or_delete(): void
    {
        $row = $this->addProxy($this->seededUser('u0002'), $this->seededUser('u0008'));
        $missing = $row->id + 1000;

        $this->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Workflow/Proxies')->where('outgoing', [])->where('incoming', []));
        // 形の誤り(proxy_user_id が整数でない)より 403 が先
        $malformed = [...$this->payload($this->seededUser('u0008')), 'proxy_user_id' => 'abc'];
        $this->post(self::URL, $malformed)->assertForbidden();
        $this->delete(self::URL."/{$row->id}")->assertForbidden();
        $this->delete(self::URL."/{$missing}")->assertForbidden();
        $this->assertSame(1, WorkflowProxy::query()->count());
    }

    /**
     * 利用者とシステム管理者(自分の代理は誰でも同じ規則): 一覧は代理元が自分・代理先が自分の行だけ、自分の行は 303 で消え、ほかの行・無い行は 404。
     */
    #[DataProvider('users')]
    public function test_a_user_lists_and_deletes_only_own_rows(string $userCode): void
    {
        $user = $this->seededUser($userCode);
        $own = $this->addProxy($user, $this->seededUser('u0008'));
        $incoming = $this->addProxy($this->seededUser('u0010'), $user);
        $others = $this->addProxy($this->seededUser('u0010'), $this->seededUser('u0008'));
        $missing = $others->id + 1000;
        $this->actingAs($user);

        $this->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Workflow/Proxies')
            ->where('outgoing.0.id', $own->id)->count('outgoing', 1)
            ->where('incoming.0.id', $incoming->id)->count('incoming', 1));
        $this->delete(self::URL."/{$others->id}")->assertNotFound();
        $this->delete(self::URL."/{$missing}")->assertNotFound();
        $this->delete(self::URL."/{$incoming->id}")->assertNotFound();
        $this->delete(self::URL."/{$own->id}")->assertStatus(303)->assertRedirect(self::URL)
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '代理を削除しました']);

        $this->assertEqualsCanonicalizing([$incoming->id, $others->id], WorkflowProxy::query()->pluck('id')->all());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function users(): array
    {
        return ['user u0002' => ['u0002'], 'system administrator u0004' => ['u0004']];
    }

    /**
     * 追加: 代理元 = 自分、登録した人 = 自分、行の end_date = 入力の終了日の翌日。代理の設定へ戻りトースト。
     */
    public function test_adding_writes_the_row_with_the_user_as_the_principal(): void
    {
        $user = $this->seededUser('u0003');
        $proxy = $this->seededUser('u0009');
        $this->actingAs($user);

        $input = [...$this->payload($proxy), 'workflow_flow_code' => 'project_plan', 'end_date' => '2026-10-31'];
        $this->from(self::URL)->post(self::URL, $input)
            ->assertStatus(302)->assertRedirect(self::URL)
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '代理を追加しました']);

        $row = WorkflowProxy::query()->with('workflowFlow')->sole();
        $this->assertSame(
            [$user->id, $proxy->id, 'approve', 'project_plan', '2026-10-13', '2026-11-01', $user->id],
            [$row->principal_user_id, $row->proxy_user_id, $row->kind->value, $row->workflowFlow?->code,
                $row->start_date->toDateString(), $row->end_date?->toDateString(), $row->registered_by_user_id],
        );
    }

    /**
     * 規則の誤りは元の画面へ戻り、業務処理のキーと文言(代理先 = 自分)。形の誤りも、その欄の業務処理の文言(画面の欄からは起きない)。
     * どれも何も書かない。
     */
    public function test_errors_return_to_the_form_with_the_key_and_the_message_of_the_rules(): void
    {
        $user = $this->seededUser('u0003');
        $this->actingAs($user);
        $post = fn (array $values) => $this->from(self::URL)
            ->post(self::URL, [...$this->payload($this->seededUser('u0009')), ...$values])->assertRedirect(self::URL);

        $post(['proxy_user_id' => $user->id])->assertSessionHasErrors(['proxy_user_id' => ProxyRules::SAME_USER]);
        $post(['proxy_user_id' => 'abc'])->assertSessionHasErrors(['proxy_user_id' => ProxyRules::PROXY_REQUIRED]);
        $post(['kind' => ['approve']])->assertSessionHasErrors(['kind' => ProxyRules::KIND_REQUIRED]);
        $post(['workflow_flow_code' => ['project_plan']])
            ->assertSessionHasErrors(['workflow_flow_code' => ProxyRules::FLOW_REQUIRED]);
        $post(['start_date' => ['2026-10-13']])->assertSessionHasErrors(['start_date' => ProxyRules::START_INVALID]);
        $post(['end_date' => ['2026-10-31']])->assertSessionHasErrors(['end_date' => ProxyRules::END_INVALID]);
        $this->assertSame(0, WorkflowProxy::query()->count());
    }

    /**
     * {proxy} が [0-9]{1,18} に合わない値はルートに当たらない。
     */
    public function test_a_proxy_argument_out_of_the_form_matches_no_route(): void
    {
        $this->actingAs($this->seededUser('u0002'));

        $this->delete(self::URL.'/abc')->assertNotFound();
        $this->delete(self::URL.'/'.str_repeat('1', 19))->assertNotFound();
    }

    /**
     * 承認・すべてのフロー・今日から終了日なしの入力。
     *
     * @return array<string, int|string|null>
     */
    private function payload(User $proxy): array
    {
        return [
            'proxy_user_id' => $proxy->id, 'kind' => 'approve', 'workflow_flow_code' => null,
            'start_date' => '2026-10-13', 'end_date' => null,
        ];
    }
}
