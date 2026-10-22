<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\RetargetCaseNode;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Workflow\CurrentNodes;
use Tests\Support\OperatesAsCaseManager;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 処理対象者の変更(docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.3 節、同時実行と検査.md の 13 節・14.2 節): 承認待ち・保留中の
 * ノードで成功し保留が解ける。到達日時(期限)・写した指定は変わらない。前の人と同じ人を残せる。規則 (1)〜(5) はどれも 422 user_ids で何も変わらない。
 * 前の人の未処理から消え新しい人の未処理に出て、管理者の処理済に出る。処理対象者の変更の後に差戻しで到達し直すと写しから展開し直す。
 * 管理者 = システム管理者 u0004。
 */
final class RetargetCaseNodeTest extends TransactionalTestCase
{
    use OperatesAsCaseManager;
    use RegistersProxies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
    }

    /**
     * 承認待ちの pair(u0002・u0003)を u0003・u0006 に変える: 行は全部書き直し(u0003 を残す)、到達日時・期限日・写した指定は同じ、履歴 = 処理対象者の変更
     * (処理者 = 管理者、実行者 = null、履歴の人 = 変更後の人)、改訂番号 +1。u0002 は未処理から消え、u0006 の未処理と管理者の処理済に出る。
     */
    public function test_retargeting_a_waiting_node_rewrites_its_targets(): void
    {
        $this->addPairRoute();
        [$case] = $this->applyOnRoute([]);
        $before = [$this->reachedAtOf($case, 'pair'), $this->copiedOf($case, 'pair'), $this->dueOnOf($case, 'pair')];
        $revision = $this->revisionOf($case);

        $result = $this->retargetOn($case, 'pair', ['u0003', 'u0006'], comment: '異動のため');

        $this->assertSame('waiting[u0003,u0006]', $this->shape($case)['pair']);
        $after = [$this->reachedAtOf($case, 'pair'), $this->copiedOf($case, 'pair'), $this->dueOnOf($case, 'pair')];
        $this->assertSame([$before, $revision + 1], [$after, $this->revisionOf($case)]);
        $this->assertSame(['retarget', self::MANAGER, null, [], ['u0003', 'u0006']], $this->latestRow($case));
        $added = [$this->labelIds($case)['pair'] => [$this->seededUser('u0006')->id]];
        $this->assertSame($added, $result->targetAdditions);
        $this->assertSame(
            ['u0002' => [], 'u0003' => ['pending'], 'u0006' => ['pending'], self::MANAGER => ['processed']],
            $this->appearances($case, 'u0002', 'u0003', 'u0006', self::MANAGER),
        );
    }

    /**
     * 保留中のノードの処理対象者の変更で保留が解ける(承認待ちに戻る)。同じ人だけを渡しても書き直す(履歴が増える)。
     */
    public function test_retargeting_a_held_node_releases_the_hold(): void
    {
        $this->addPairRoute();
        [$case] = $this->applyOnRoute([]);
        $this->holdOn($case, 'pair', 'u0002');

        $this->retargetOn($case, 'pair', ['u0002', 'u0003']);

        $this->assertSame('waiting[u0002,u0003]', $this->shape($case)['pair']);
        $this->assertSame(['retarget', self::MANAGER, null, [], ['u0002', 'u0003']], $this->latestRow($case));
    }

    /**
     * 規則 (1)〜(3)・(5) と人数: 0 人・21 人(人数を重複より先に見る)、存在しない・申請基準日に有効でない(退職 u0014)、重複、自分(管理者)、申請者承認防止のノードの申請者と今日の
     * 申請者の代理先。どれも 422 user_ids で何も変わらない。
     */
    public function test_the_rules_for_the_people(): void
    {
        $this->addVersion([[$this->applyNode(), $this->guardedNode()], $this->chain(['apply', 'guarded'])]);
        $this->addProxy($this->seededUser('u0001'), $this->seededUser('u0008'));
        [$case] = $this->applyOnRoute([]);
        $missing = (int) User::query()->max('id') + 1000;
        $twentyOne = array_fill(0, 21, 'u0006');
        $manager = $this->contextOf($this->seededUser(self::MANAGER));
        $nodeId = $this->labelIds($case)['guarded'];
        $byMissing = fn () => $this->attemptOf(fn () => app(RetargetCaseNode::class)
            ->execute($manager, $case->id, $this->revisionOf($case), $nodeId, [$missing], null));
        $before = $this->snapshot($case);

        $this->assertSame([
            ['user_ids' => '処理担当者を 1 人から 20 人まで指定してください'],
            ['user_ids' => '処理担当者を 1 人から 20 人まで指定してください'],
            ['user_ids' => '申請基準日に有効でないユーザーは指定できません'],
            ['user_ids' => '申請基準日に有効でないユーザーは指定できません'],
            ['user_ids' => '同じユーザーを重複して指定できません'],
            ['user_ids' => '自分自身は処理担当者に指定できません'],
            ['user_ids' => '申請者本人は、このステップの処理担当者に指定できません'],
            ['user_ids' => '申請者の代理先は、このステップの処理担当者に指定できません'],
        ], [
            $this->attemptRetarget($case, 'guarded', []),
            $this->attemptRetarget($case, 'guarded', $twentyOne),
            $byMissing(),
            $this->attemptRetarget($case, 'guarded', ['u0006', 'u0014']),
            $this->attemptRetarget($case, 'guarded', ['u0006', 'u0006']),
            $this->attemptRetarget($case, 'guarded', ['u0006', self::MANAGER]),
            $this->attemptRetarget($case, 'guarded', ['u0006', 'u0001']),
            $this->attemptRetarget($case, 'guarded', ['u0006', 'u0008']),
        ]);
        $this->assertSame($before, $this->snapshot($case));
    }

    /**
     * 規則 (4) と展開の行の人数: 縦配置の子は 1 人だけ(2 人は 422)、ほかの子の今の人(到達した子 u0009・処理済みの子の処理者 u0011)は 422。
     */
    public function test_a_council_child_takes_one_person_who_is_not_on_the_other_children(): void
    {
        $this->addVersion($this->placementRoute(WorkflowNodeKind::Parallel));
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', 'u0002', ['family' => ['u0005', 'u0009', 'u0011']]);
        $this->approve($case, 'family.3', 'u0011');
        $before = $this->snapshot($case);

        $errors = [
            $this->attemptRetarget($case, 'family.1', ['u0006', 'u0007']),
            $this->attemptRetarget($case, 'family.1', ['u0009']),
            $this->attemptRetarget($case, 'family.1', ['u0011']),
        ];

        $this->assertSame([
            ['user_ids' => 'このステップの処理担当者は 1 人を指定してください'],
            ['user_ids' => 'このステップのほかの承認者と同じユーザーは指定できません'],
            ['user_ids' => 'このステップのほかの承認者と同じユーザーは指定できません'],
        ], $errors);
        $this->assertSame($before, $this->snapshot($case));
        $this->retargetOn($case, 'family.1', ['u0006']);
        $this->assertSame('waiting[u0006]', $this->shape($case)['family.1']);
    }

    /**
     * 処理対象者の変更の後に差戻しで到達し直すと、写した指定から展開し直す(変更後の人に戻らない = 写しは変えない)。
     */
    public function test_a_send_back_after_retargeting_expands_the_copy_again(): void
    {
        $this->addVersion([[
            $this->applyNode(),
            $this->approveNode('a1', 'u0002', ['apply']),
            $this->approveNode('a2', 'u0003', ['apply', 'a1']),
        ], $this->chain(['apply', 'a1'], ['a1', 'a2'])]);
        [$case] = $this->applyOnRoute([]);
        $this->retargetOn($case, 'a1', ['u0006']);
        $this->approve($case, 'a1', 'u0006');
        $this->sendBackOn($case, 'a2', 'u0003', 'apply');

        $this->reapply($this->seededUser('u0001'), $case);

        $this->assertSame('waiting[u0002]', $this->shape($case)['a1']);
    }

    /**
     * 処理対象者の変更を savepoint の中で行う('success' か 422 のキー → 文言)。
     *
     * @param  list<string>  $codes
     * @return string|array<string, string>
     */
    private function attemptRetarget(WorkflowCase $case, string $label, array $codes): string|array
    {
        return $this->attemptOf(fn () => $this->retargetOn($case, $label, $codes));
    }

    /**
     * 申請 → pair(u0002・u0003)→ final(u0010)の版(pair の期限 3 日)。
     */
    private function addPairRoute(): void
    {
        $pair = new RouteNodeInput(
            'pair',
            'pair',
            WorkflowNodeKind::Approve,
            WorkflowTargetKind::Fixed,
            [$this->userCondition('u0002'), $this->userCondition('u0003')],
            ['ja' => 'pair'],
            sendBackCodes: ['apply'],
            deadlineDays: 3,
        );
        $final = $this->approveNode('final', 'u0010', ['apply', 'pair']);
        $this->addVersion([[$this->applyNode(), $pair, $final], $this->chain(['apply', 'pair'], ['pair', 'final'])]);
    }

    /**
     * 申請者承認防止がオンの承認のノード(u0003)。
     */
    private function guardedNode(): RouteNodeInput
    {
        return new RouteNodeInput(
            'guarded',
            'guarded',
            WorkflowNodeKind::Approve,
            WorkflowTargetKind::Fixed,
            [$this->userCondition('u0003')],
            ['ja' => 'guarded'],
            preventsApplicantApproval: true,
            sendBackCodes: ['apply'],
        );
    }

    /**
     * 写した指定の列(種類・対象者・起点・範囲・役職)。
     *
     * @return array<string, mixed>
     */
    private function copiedOf(WorkflowCase $case, string $label): array
    {
        $node = WorkflowCaseNode::query()->findOrFail($this->labelIds($case)[$label]);

        $columns = ['target_kind', 'authz_subject_group_id', 'relative_base', 'relative_scope', 'company_post_id'];

        return $node->only([...$columns, 'deadline_days']);
    }

    /**
     * 現在のノードの期限日({@see CurrentNodes} の dueOn)。
     */
    private function dueOnOf(WorkflowCase $case, string $label): ?string
    {
        $nodeId = $this->labelIds($case)[$label];

        foreach (app(CurrentNodes::class)->of([$case->id])[$case->id] as $node) {
            if ($node->id === $nodeId) {
                return $node->dueOn;
            }
        }

        return null;
    }

    /**
     * @return array{array<string, string>, array<string, int>, int}
     */
    private function snapshot(WorkflowCase $case): array
    {
        return [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)];
    }
}
