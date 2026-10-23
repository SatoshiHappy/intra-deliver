<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\DeleteProxy;
use App\Actions\Workflow\ProxyInput;
use App\Actions\Workflow\RegisterProxy;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowProxy;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseVisibility;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 代理の登録と削除(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.9 節、同時実行と検査.md の 13 節): 規則 (1)〜(8) のそれぞれ
 * (ゲストは 403、ほかは 422 のキーと文言)。開始日・終了日の境界、行の end_date = 入力の翌日、代理元の有効期間との重なり、代理先は有効でなくてよい、
 * 登録した人 = 判定を求める者、登録の後の次の判定から到達済みのノードにも効く。削除は自分の代理の呼び方(代理元が自分の行だけ)と代理の管理の呼び方。
 * 今日 = 操作の基準日 2026-10-13。
 */
final class ProxyRegistrationTest extends TransactionalTestCase
{
    use RunsWorkflowOperations;

    public function test_each_rule_rejects_with_its_key_and_writes_nothing(): void
    {
        $this->createFlow();
        [$principal, $proxy] = [$this->seededUser('u0002'), $this->seededUser('u0008')];
        $missing = (int) User::query()->max('id') + 1000;
        // 初期データの代理(設計/ワークフロー/案件の運用/初期データ.md 4.1)の数から増えないこと
        $seeded = WorkflowProxy::query()->count();
        $input = fn (array $values = []) => $this->input([...['proxy' => $proxy->id], ...$values]);
        $register = fn (?int $principalId, ProxyInput $value) => $this->outcome(
            fn () => $this->register($this->seededUser('u0004'), $principalId, $value),
        );

        $this->assertSame([
            ['principal_user_id' => '代理元を選択してください'],
            ['principal_user_id' => '代理元を選択してください'],
            ['proxy_user_id' => '代理先を選択してください'],
            ['proxy_user_id' => '代理先を選択してください'],
            ['proxy_user_id' => '代理元と代理先に同じユーザーは指定できません'],
            ['kind' => '種類を選択してください'],
            ['kind' => '種類を選択してください'],
            ['workflow_flow_code' => '対象のフローを選択してください'],
            ['start_date' => '開始日を入力してください'],
            ['start_date' => '開始日は日付で入力してください'],
            ['end_date' => '終了日は日付で入力してください'],
            ['start_date' => '代理元が有効でない期間には、代理を設定できません'],
        ], [
            $register(null, $input()),
            $register($missing, $input()),
            $register($principal->id, $input(['proxy' => null])),
            $register($principal->id, $input(['proxy' => $missing])),
            $register($principal->id, $input(['proxy' => $principal->id])),
            $register($principal->id, $input(['kind' => null])),
            $register($principal->id, $input(['kind' => 'confirm'])),
            $register($principal->id, $input(['flow' => 'no_such_flow'])),
            $register($principal->id, $input(['start' => ' '])),
            $register($principal->id, $input(['start' => '2026-13-01'])),
            $register($principal->id, $input(['end' => '2026-11-31'])),
            $register($this->seededUser('u0014')->id, $input()),
        ]);
        $this->assertSame($seeded, WorkflowProxy::query()->count());
        $this->assertThrows(
            fn () => app(RegisterProxy::class)->execute($this->contextOf(null), $principal->id, $input()),
            AuthorizationException::class,
        );
    }

    /**
     * 開始日 = 今日・今日 + 1 年は受け、前日・今日 + 1 年 + 1 日は拒む。終了日 = 開始日・開始日 + 1 年は受け、開始日の前日・開始日 + 1 年 + 1 日は拒む。
     * 受けた行の end_date = 入力の翌日、終了日なしは null。
     */
    public function test_the_boundaries_of_the_period(): void
    {
        [$principal, $proxy] = [$this->seededUser('u0002'), $this->seededUser('u0008')];
        $register = fn (string $start, ?string $end) => $this->outcome(fn () => $this->periodOf(
            app(RegisterProxy::class)->execute($this->contextOf($principal), $principal->id, $this->input([
                'proxy' => $proxy->id, 'start' => $start, 'end' => $end,
            ])),
        ));
        $startError = ['start_date' => '開始日は今日から 1 年以内の日付を入力してください'];

        $this->assertSame([
            ['2026-10-13', null],
            ['2027-10-13', null],
            $startError,
            $startError,
            ['2026-11-01', '2026-11-02'],
            ['2026-11-01', '2027-11-02'],
            ['end_date' => '終了日は開始日以降の日付を入力してください'],
            ['end_date' => '終了日は開始日から 1 年以内の日付を入力してください'],
        ], [
            $register('2026-10-13', null),
            $register('2027-10-13', null),
            $register('2026-10-12', null),
            $register('2027-10-14', null),
            $register('2026-11-01', '2026-11-01'),
            $register('2026-11-01', '2027-11-01'),
            $register('2026-11-01', '2026-10-31'),
            $register('2026-11-01', '2027-11-02'),
        ]);
    }

    /**
     * 代理元(入社 2026-11-01 の u0015)が期間の最後の日だけ有効でも受け、期間のどの日にも有効でなければ拒む。代理先(退職した u0014)は有効でなくても受ける。
     * 登録した人 = 判定を求める者(代理の管理で u0004 が登録)。
     */
    public function test_the_principal_must_be_effective_on_some_day_and_the_proxy_need_not_be(): void
    {
        [$admin, $joining] = [$this->seededUser('u0004'), $this->seededUser('u0015')];
        [$retired, $proxy] = [$this->seededUser('u0014'), $this->seededUser('u0008')];
        $register = fn (User $principal, User $to, ?string $end) => $this->outcome(
            fn () => app(RegisterProxy::class)->execute($this->contextOf($admin), $principal->id, $this->input([
                'proxy' => $to->id, 'start' => '2026-10-13', 'end' => $end,
            ])),
        );

        $this->assertSame([
            'success',
            ['start_date' => '代理元が有効でない期間には、代理を設定できません'],
            'success',
        ], [
            $register($joining, $proxy, '2026-11-01'),
            $register($joining, $proxy, '2026-10-31'),
            $register($proxy, $retired, null),
        ]);

        $id = $this->register($admin, $joining->id, $this->input(['proxy' => $proxy->id, 'end' => '2026-11-01']));
        $row = WorkflowProxy::query()->findOrFail($id);
        $this->assertSame(
            [$joining->id, $proxy->id, 'approve', null, $admin->id],
            [$row->principal_user_id, $row->proxy_user_id, $row->kind->value, $row->workflow_flow_id,
                $row->registered_by_user_id],
        );
    }

    /**
     * 登録の後の次の判定から、到達済みのノードにも効く(代理先の未処理に出て、代理元として承認できる)。対象のフローを指定した行はそのフローを持つ。
     * 削除すると次の判定から効かない(期間の中でも)。
     */
    public function test_a_registered_proxy_works_on_a_reached_node_until_it_is_deleted(): void
    {
        $flowId = $this->createFlow();
        [$principal, $proxy] = [$this->seededUser('u0002'), $this->seededUser('u0008')];
        $case = $this->applyAs($this->seededUser('u0001'));
        $pending = fn () => app(CaseVisibility::class)->pending(
            WorkflowCase::query()->whereKey($case->id),
            new Requester($proxy->id, null),
            $this->operationDay(),
        )->exists();
        $before = $pending();

        $id = app(RegisterProxy::class)->execute($this->contextOf($principal), $principal->id, $this->input([
            'proxy' => $proxy->id, 'flow' => self::FLOW_CODE,
        ]));
        $registered = $pending();
        $registeredFlowId = WorkflowProxy::query()->findOrFail($id)->workflow_flow_id;
        app(DeleteProxy::class)->executeOwn($this->contextOf($principal), $id);
        $deleted = $pending();
        $this->register($principal, $principal->id, $this->input(['proxy' => $proxy->id]));
        $nodeId = $this->caseNode($case, 'approval1')->id;
        $revision = $this->revisionOf($case);
        app(ApproveCase::class)
            ->execute($this->contextOf($proxy), $case->id, $revision, $nodeId, null, [], $principal->id);

        $this->assertSame([false, true, false], [$before, $registered, $deleted]);
        $this->assertSame($flowId, $registeredFlowId);
        $this->assertSame('done', $this->nodeStates($case)[1][1]);
    }

    /**
     * 自分の代理の呼び方は代理元が自分の行だけ(ほかの人の行・無い行は 404、ゲストは 403)、代理の管理の呼び方は全部の行を消せる。
     */
    public function test_deleting_own_rows_and_any_row(): void
    {
        [$principal, $other] = [$this->seededUser('u0002'), $this->seededUser('u0003')];
        $seeded = WorkflowProxy::query()->count();
        $own = $this->register($principal, $principal->id, $this->input(['proxy' => $this->seededUser('u0008')->id]));
        $others = $this->register($other, $other->id, $this->input(['proxy' => $this->seededUser('u0008')->id]));
        $missing = (int) WorkflowProxy::query()->max('id') + 1000;
        $deleteOwn = fn (?User $user, int $id) => $this->outcome(
            fn () => app(DeleteProxy::class)->executeOwn($this->contextOf($user), $id),
        );

        $this->assertSame(['404', '404', '403', 'success'], [
            $deleteOwn($principal, $others),
            $deleteOwn($principal, $missing),
            $deleteOwn(null, $own),
            $deleteOwn($principal, $own),
        ]);
        app(DeleteProxy::class)->executeOwn($this->contextOf($principal), $own);
        app(DeleteProxy::class)->execute($others);
        $this->assertSame($seeded, WorkflowProxy::query()->count());
        $this->assertThrows(fn () => app(DeleteProxy::class)->execute($missing), ModelNotFoundException::class);
    }

    /**
     * 既定 = 代理先なし・承認の代理・すべてのフロー・今日から終了日なし(キー = proxy・kind・flow・start・end)。
     *
     * @param  array<string, int|string|null>  $values
     */
    private function input(array $values): ProxyInput
    {
        $defaults = ['proxy' => null, 'kind' => 'approve', 'flow' => null, 'start' => '2026-10-13', 'end' => null];
        $values = [...$defaults, ...$values];

        $proxy = $values['proxy'];
        $text = fn (string $key) => $values[$key] === null ? null : (string) $values[$key];

        $proxyId = $proxy === null ? null : (int) $proxy;

        return new ProxyInput($proxyId, $text('kind'), $text('flow'), $text('start'), $text('end'));
    }

    private function register(User $by, ?int $principalId, ProxyInput $input): int
    {
        return app(RegisterProxy::class)->execute($this->contextOf($by), $principalId, $input);
    }

    /**
     * @return array{string, string|null} 行の [start_date, end_date]
     */
    private function periodOf(int $id): array
    {
        $row = WorkflowProxy::query()->findOrFail($id);

        return [$row->start_date->toDateString(), $row->end_date?->toDateString()];
    }

    /**
     * 操作を savepoint の中で行った結果(成功は戻り値か 'success'、422 はキー → 文言、404・403)。
     *
     * @param  Closure(): mixed  $operate
     */
    private function outcome(Closure $operate): mixed
    {
        DB::beginTransaction();

        try {
            $result = $operate();

            return is_array($result) ? $result : 'success';
        } catch (ValidationException $exception) {
            return array_map(fn (array $messages) => (string) $messages[0], $exception->errors());
        } catch (ModelNotFoundException) {
            return '404';
        } catch (AuthorizationException) {
            return '403';
        } finally {
            DB::rollBack();
        }
    }
}
