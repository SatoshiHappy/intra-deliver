<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use Tests\Support\OperatesHolds;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 振替(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.3 節、同時実行と検査.md の 13 節・14.2 節): 振替先の規則 (1)〜(5) は
 * どれも 422 to_user_id で何も変わらない。成功で処理対象者の行から振替元が消え振替先が足され、到達日時は変わらず、履歴 = 振替・履歴の人 = 振替先、
 * 改訂番号 +1。保留中の振替で承認待ちに戻る。振替先はさらに振替できる。代理先が代理元として振替すると代理元の行が消える。振替元は未処理から消え処理済に、
 * 振替先とその代理先の未処理に出る。振替したノードへの差戻しで振替先に戻り、それより前への差戻しの後の到達で写しから展開し直す(T-3)。
 */
final class TransferCaseTest extends TransactionalTestCase
{
    use OperatesHolds;
    use RegistersProxies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
    }

    /**
     * 規則 (1)〜(3): 無い・存在しない・申請基準日に有効でない(退職 u0014・入社前 u0015)・自分・代理元(と代理で処理する自分)・すでに処理担当者。
     */
    public function test_the_rules_for_the_person_and_the_node(): void
    {
        $this->addPairRoute();
        $this->addProxy($this->seededUser('u0002'), $this->seededUser('u0008'));
        [$case] = $this->applyOnRoute([]);
        $missing = (int) User::query()->max('id') + 1000;
        $by = fn (string $userCode, ?int $to, ?string $principal = null) => $this->attemptOf(
            fn () => $this->transferOn($case, 'pair', $userCode, $to, $principal),
        );
        $before = $this->snapshot($case);

        $this->assertSame([
            ['to_user_id' => '担当変更先を選択してください'],
            ['to_user_id' => '申請基準日に有効でないユーザーは指定できません'],
            ['to_user_id' => '申請基準日に有効でないユーザーは指定できません'],
            ['to_user_id' => '申請基準日に有効でないユーザーは指定できません'],
            ['to_user_id' => '自分自身と代理元は担当変更先に指定できません'],
            ['to_user_id' => '自分自身と代理元は担当変更先に指定できません'],
            ['to_user_id' => '自分自身と代理元は担当変更先に指定できません'],
            ['to_user_id' => 'このユーザーは、すでにこのステップの処理担当者です'],
        ], [
            $by('u0002', null),
            $by('u0002', $missing),
            $by('u0002', $this->idOf('u0014')),
            $by('u0002', $this->idOf('u0015')),
            $by('u0002', $this->idOf('u0002')),
            $by('u0008', $this->idOf('u0002'), 'u0002'),
            $by('u0008', $this->idOf('u0008'), 'u0002'),
            $by('u0002', $this->idOf('u0003')),
        ]);
        $this->assertSame($before, $this->snapshot($case));
    }

    /**
     * 規則 (4) の縦配置: 子 1(u0005)から、到達したほかの子(u0009)と、処理済みのほかの子の処理者(u0011)へは振替できない。
     */
    public function test_a_parallel_child_cannot_be_transferred_to_the_people_of_the_other_children(): void
    {
        $this->addVersion($this->placementRoute(WorkflowNodeKind::Parallel));
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', 'u0002', ['family' => ['u0005', 'u0009', 'u0011']]);
        $this->approve($case, 'family.3', 'u0011');
        $before = $this->snapshot($case);

        $errors = [
            $this->attemptOf(fn () => $this->transferOn($case, 'family.1', 'u0005', $this->idOf('u0009'))),
            $this->attemptOf(fn () => $this->transferOn($case, 'family.1', 'u0005', $this->idOf('u0011'))),
        ];

        $this->assertSame(array_fill(0, 2, ['to_user_id' => 'このステップのほかの承認者と同じユーザーは指定できません']), $errors);
        $this->assertSame($before, $this->snapshot($case));
    }

    /**
     * 規則 (4) の横配置: 子 2(u0009)から、処理済みの子 1 の処理者(u0005)と、到達していない子 3 の今の指定の人(u0011)へは振替できない。
     */
    public function test_a_serial_child_cannot_be_transferred_to_the_people_of_the_other_children(): void
    {
        $this->addVersion($this->placementRoute(WorkflowNodeKind::Serial));
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', 'u0002', ['family' => ['u0005', 'u0009', 'u0011']]);
        $this->approve($case, 'family.1', 'u0005');
        $before = $this->snapshot($case);

        $errors = [
            $this->attemptOf(fn () => $this->transferOn($case, 'family.2', 'u0009', $this->idOf('u0005'))),
            $this->attemptOf(fn () => $this->transferOn($case, 'family.2', 'u0009', $this->idOf('u0011'))),
        ];

        $this->assertSame(array_fill(0, 2, ['to_user_id' => 'このステップのほかの承認者と同じユーザーは指定できません']), $errors);
        $this->assertSame($before, $this->snapshot($case));
    }

    /**
     * 規則 (5): 申請者承認防止がオンのノードの振替先に、申請者(u0001)と今日の申請者の代理先(u0008)を選べない。
     */
    public function test_a_preventing_node_cannot_be_transferred_to_the_applicant_or_its_proxy(): void
    {
        $guarded = new RouteNodeInput(
            'guarded',
            'guarded',
            WorkflowNodeKind::Approve,
            WorkflowTargetKind::Fixed,
            [$this->userCondition('u0003')],
            ['ja' => 'guarded'],
            preventsApplicantApproval: true,
            sendBackCodes: ['apply'],
        );
        $this->addVersion([[$this->applyNode(), $guarded], $this->chain(['apply', 'guarded'])]);
        $this->addProxy($this->seededUser('u0001'), $this->seededUser('u0008'));
        [$case] = $this->applyOnRoute([]);
        $before = $this->snapshot($case);

        $errors = [
            $this->attemptOf(fn () => $this->transferOn($case, 'guarded', 'u0003', $this->idOf('u0001'))),
            $this->attemptOf(fn () => $this->transferOn($case, 'guarded', 'u0003', $this->idOf('u0008'))),
        ];

        $this->assertSame([
            ['to_user_id' => '申請者本人は、このステップの承認者に選択できません'],
            ['to_user_id' => '申請者の代理先は、このステップの承認者に選択できません'],
        ], $errors);
        $this->assertSame($before, $this->snapshot($case));
    }

    /**
     * u0002 → u0008 の振替: 処理対象者 = u0003・u0008、到達日時は同じ、履歴 = 振替(履歴の人 = u0008)、改訂番号 +1。u0002 は未処理から消え処理済に、
     * u0008 と u0008 の代理先 u0009 の未処理に出る。
     */
    public function test_a_transfer_replaces_the_transferor_with_the_transferee(): void
    {
        $this->addPairRoute();
        $this->addProxy($this->seededUser('u0008'), $this->seededUser('u0009'));
        [$case] = $this->applyOnRoute([]);
        $reachedAt = $this->nodeOf($case, 'pair')->reached_at?->toIso8601String();
        $revision = $this->revisionOf($case);

        $result = $this->transferOn($case, 'pair', 'u0002', $this->idOf('u0008'));

        $this->assertSame('waiting[u0003,u0008]', $this->shape($case)['pair']);
        $after = [$this->nodeOf($case, 'pair')->reached_at?->toIso8601String(), $this->revisionOf($case)];
        $this->assertSame([$reachedAt, $revision + 1], $after);
        $this->assertSame(['transfer', 'u0002', null, [], ['u0008']], $this->latestRow($case));
        $this->assertSame([], $result->reachedNodes);
        $this->assertSame([
            'u0002' => ['processed'],
            'u0003' => ['pending'],
            'u0008' => ['pending'],
            'u0009' => ['pending'],
        ], $this->appearances($case, 'u0002', 'u0003', 'u0008', 'u0009'));
    }

    /**
     * 保留中の振替で承認待ちに戻る。振替先はさらに振替できる。代理先 u0008 が u0002 として振替すると u0002 の行が消える(処理者 = u0002、実行者 = u0008)。
     */
    public function test_a_transfer_releases_a_hold_and_can_be_repeated_and_done_as_the_principal(): void
    {
        $this->addPairRoute();
        $this->addProxy($this->seededUser('u0002'), $this->seededUser('u0008'));
        [$held] = $this->applyOnRoute([]);
        [$asPrincipal] = $this->applyOnRoute([]);
        $this->holdOn($held, 'pair', 'u0002');

        $this->transferOn($held, 'pair', 'u0002', $this->idOf('u0006'));
        $this->transferOn($held, 'pair', 'u0006', $this->idOf('u0007'));
        $this->transferOn($asPrincipal, 'pair', 'u0008', $this->idOf('u0009'), 'u0002');

        $this->assertSame('waiting[u0003,u0007]', $this->shape($held)['pair']);
        $this->assertSame('waiting[u0003,u0009]', $this->shape($asPrincipal)['pair']);
        $this->assertSame(['transfer', 'u0002', 'u0008', [], ['u0009']], $this->latestRow($asPrincipal));
    }

    /**
     * 振替したノードへの差戻しで振替先に戻り、それより前(申請)への差戻しの後の到達で写しから展開し直す(振替は効かない = T-3)。
     */
    public function test_a_send_back_returns_to_the_transferee_and_an_earlier_send_back_expands_again(): void
    {
        $this->addVersion([[
            $this->applyNode(),
            $this->approveNode('a1', 'u0002', ['apply']),
            $this->approveNode('a2', 'u0003', ['apply', 'a1']),
        ], $this->chain(['apply', 'a1'], ['a1', 'a2'])]);
        [$case] = $this->applyOnRoute([]);
        $this->transferOn($case, 'a1', 'u0002', $this->idOf('u0008'));
        $this->approve($case, 'a1', 'u0008');

        $this->sendBackOn($case, 'a2', 'u0003', 'a1');
        $returned = $this->shape($case)['a1'];
        $this->approve($case, 'a1', 'u0008');
        $this->sendBackOn($case, 'a2', 'u0003', 'apply');
        $this->reapply($this->seededUser('u0001'), $case);

        $this->assertSame(['waiting[u0008]', 'waiting[u0002]'], [$returned, $this->shape($case)['a1']]);
    }

    /**
     * 申請 → pair(u0002・u0003)→ final(u0010)の版。
     */
    private function addPairRoute(): void
    {
        $pair = new RouteNodeInput(
            'pair',
            'pair',
            WorkflowNodeKind::Approve,
            WorkflowTargetKind::Fixed,
            [$this->userCondition('u0002'), new SelectionCondition(SelectionConditionKind::User, $this->idOf('u0003'))],
            ['ja' => 'pair'],
            sendBackCodes: ['apply'],
        );
        $final = $this->approveNode('final', 'u0010', ['apply', 'pair']);
        $this->addVersion([[$this->applyNode(), $pair, $final], $this->chain(['apply', 'pair'], ['pair', 'final'])]);
    }

    private function idOf(string $code): int
    {
        return $this->seededUser($code)->id;
    }

    private function nodeOf(WorkflowCase $case, string $label): WorkflowCaseNode
    {
        return WorkflowCaseNode::query()->findOrFail($this->labelIds($case)[$label]);
    }

    /**
     * @return array{array<string, string>, array<string, int>, int}
     */
    private function snapshot(WorkflowCase $case): array
    {
        return [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)];
    }
}
