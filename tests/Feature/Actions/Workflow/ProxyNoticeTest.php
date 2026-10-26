<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Notice\Api\NoticeMessage;
use App\Actions\Notice\Api\SendNotices;
use App\Actions\Workflow\DeleteProxy;
use App\Actions\Workflow\ProxyInput;
use App\Actions\Workflow\RegisterProxy;
use App\Models\Master\User;
use App\Models\Notice\Notice;
use App\Models\Workflow\WorkflowProxy;
use App\Queries\Notice\NoticeList;
use App\Queries\Workflow\WorkflowNoticeTargets;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\StartsWithoutSeededNotices;
use Tests\Support\TransactionalTestCase;

/**
 * 代理の登録の通知「代理」(docs の 設計/ワークフロー/案件の運用/通知.md の 3〜5 節): 宛先 = 代理先(登録した人と、今日に有効でない人を除く)に
 * 1 行、文の値は作った時の写し(代理元の名前・別・フロー名・開始日・最後の有効な日)、起こした人 = 登録した人。通知の書き込みが失敗すれば登録ごと巻き戻る。
 * 行の移り先は、代理の行がまだあり今の利用者がその代理先なら代理の設定、ほかは「この代理の設定は削除されました。」。今日 = 2026-10-13。
 */
final class ProxyNoticeTest extends TransactionalTestCase
{
    use RunsWorkflowOperations;
    use StartsWithoutSeededNotices;

    /**
     * 宛先の表: 代理先 = 登録した人(代理の管理で自分を代理先にする)は作らない、代理先が今日に有効でない(入社前・退職)は作らない、ほかは代理先へ 1 行。
     */
    public function test_the_notice_goes_only_to_a_proxy_who_is_not_the_registrant_and_is_effective_today(): void
    {
        $admin = $this->seededUser('u0004');
        $principal = $this->seededUser('u0003');

        $this->register($admin, $this->seededUser('u0016'), $admin);
        $this->register($principal, $principal, $this->seededUser('u0015'));
        $this->register($principal, $principal, $this->seededUser('u0014'));
        $id = $this->register($principal, $principal, $this->seededUser('u0009'));

        $this->assertSame([[
            'u0009', 'workflow.proxy', WorkflowNoticeTargets::PROXY_TARGET_TYPE, $id, 'u0003',
        ]], $this->notices());
    }

    /**
     * 文の値: すべてのフロー・終了日なし、フローの指定・終了日あり(最後の有効な日 = 入力の終了日)。代理の管理で登録すると、代理元の名前と起こした人 = 管理者。
     */
    public function test_the_params_are_a_copy_of_the_proxy_when_registered(): void
    {
        $principal = $this->seededUser('u0003');
        $this->register($principal, $principal, $this->seededUser('u0009'));
        $this->register($principal, $principal, $this->seededUser('u0002'), 'apply', 'project_plan', '2026-10-31');
        $admin = $this->seededUser('u0004');
        $this->register($admin, $this->seededUser('u0016'), $principal, 'approve', 'project_plan', '2026-10-18');

        $params = Notice::query()->with(['user', 'actor'])->orderBy('id')->get()
            ->map(fn (Notice $notice) => [$notice->user?->code, $notice->actor?->code, $notice->params])->all();

        // params は jsonb のキーの順で返るので、キーの順を問わずに比べる
        $this->assertEquals([
            ['u0009', 'u0003', $this->params('高橋 誠', 'approve', null, null)],
            ['u0002', 'u0003', $this->params('高橋 誠', 'apply', 'プロジェクト計画の承認', '2026-10-31')],
            ['u0003', 'u0004', $this->params('清水 美穂', 'approve', 'プロジェクト計画の承認', '2026-10-18')],
        ], $params);
    }

    public function test_a_failure_to_write_the_notice_rolls_back_the_registration(): void
    {
        $seeded = WorkflowProxy::query()->count();
        $this->mock(SendNotices::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execute')->once()->andThrow(new RuntimeException('通知を書けない'));
        });
        $principal = $this->seededUser('u0003');

        $this->assertThrows(
            fn () => $this->register($principal, $principal, $this->seededUser('u0009')),
            RuntimeException::class,
        );
        $this->assertSame($seeded, WorkflowProxy::query()->count());
    }

    /**
     * 移り先の表: 行がある && 今の利用者が代理先 → 代理の設定(対象の表示名なし)。行があっても代理先でない(宛先の誤りを直に作る)・行が無い → 移り先なしと 1 行。
     */
    public function test_the_destination_is_the_settings_only_for_the_proxy_of_an_existing_row(): void
    {
        $principal = $this->seededUser('u0003');
        $kept = $this->register($principal, $principal, $this->seededUser('u0009'));
        $deleted = $this->register($principal, $principal, $this->seededUser('u0002'));
        $target = WorkflowNoticeTargets::PROXY_TARGET_TYPE;
        $message = new NoticeMessage('workflow.proxy', $target, $kept, $principal->id, []);
        app(SendNotices::class)->execute($message, [$this->seededUser('u0010')->id]);
        app(DeleteProxy::class)->executeOwn($this->contextOf($principal), $deleted);
        $unavailable = [[null, null, WorkflowNoticeTargets::PROXY_UNAVAILABLE_TEXT]];

        $this->assertSame([[route('workflow.proxies.index'), null, null]], $this->destinations('u0009'));
        $this->assertSame($unavailable, $this->destinations('u0010'));
        $this->assertSame($unavailable, $this->destinations('u0002'));
    }

    private function register(
        User $by,
        User $principal,
        User $proxy,
        string $kind = 'approve',
        ?string $flow = null,
        ?string $end = null,
    ): int {
        $input = new ProxyInput($proxy->id, $kind, $flow, '2026-10-13', $end);

        return app(RegisterProxy::class)->execute($this->contextOf($by), $principal->id, $input);
    }

    /**
     * @return array<string, string|null>
     */
    private function params(string $principalName, string $kind, ?string $flowName, ?string $end): array
    {
        return [
            'principal_name' => $principalName, 'kind' => $kind, 'flow_name' => $flowName,
            'start_date' => '2026-10-13', 'end_date' => $end,
        ];
    }

    /**
     * @return list<array{string|null, string, string|null, int|null, string|null}> [宛先, 種類, 対象の種類, 対象の id, 起こした人]
     */
    private function notices(): array
    {
        return array_values(Notice::query()->with(['user', 'actor'])->orderBy('id')->get()
            ->map(fn (Notice $notice) => [
                $notice->user?->code, $notice->type, $notice->target_type, $notice->target_id, $notice->actor?->code,
            ])->all());
    }

    /**
     * @return list<array{string|null, string|null, string|null}> その人の鈴の行の [url, targetLabel, unavailableText]
     */
    private function destinations(string $userCode): array
    {
        $rows = app(NoticeList::class)->latest($this->contextOf($this->seededUser($userCode)));

        return array_map(fn (array $row) => [$row['url'], $row['targetLabel'], $row['unavailableText']], $rows);
    }
}
