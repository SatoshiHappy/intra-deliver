<?php

namespace Tests\Feature\Actions\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Workflow\WorkflowCase;
use Tests\Support\OperatesAsCaseManager;
use Tests\Support\TransactionalTestCase;

/**
 * 配置の子の「同じ親のほかの子の人」= 今の人と今の指定のその番号の人の両方(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.3 節の (4)、
 * 案件操作.md の 15.3 節の (4)、同時実行と検査.md の 14.3 節の「並列 × 保留・振替」の (5))。振替・処理対象者の変更で人を替えるときに止めるので、
 * 配置の親へ戻しても同じ人が 2 つの子を持たない。ルート = 申請 → chief(u0002 が指定)→ family(chief が u0005・u0009・u0011 を指定)→ final(u0010)。
 */
final class OtherChildPeopleTest extends TransactionalTestCase
{
    use OperatesAsCaseManager;

    private const OTHER_CHILD = 'このステップのほかの承認者と同じユーザーは指定できません';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
    }

    /**
     * 合議の子 2 を u0006 へ振替えた後に、子 1 を子 2 の今の指定の人(u0009)へ振替える・処理対象者の変更で替える → 422。
     */
    public function test_the_designated_person_of_a_transferred_child_is_rejected(): void
    {
        $case = $this->placedCase(WorkflowNodeKind::Parallel);
        $this->transferOn($case, 'family.2', 'u0009', $this->seededUser('u0006')->id);
        $before = $this->snapshot($case);

        $errors = [
            $this->attemptOf(fn () => $this->transferOn($case, 'family.1', 'u0005', $this->seededUser('u0009')->id)),
            $this->attemptOf(fn () => $this->retargetOn($case, 'family.1', ['u0009'])),
        ];

        $this->assertSame([['to_user_id' => self::OTHER_CHILD], ['user_ids' => self::OTHER_CHILD]], $errors);
        $this->assertSame($before, $this->snapshot($case));
    }

    /**
     * 逆の順: 子 1 を処理対象者の変更で u0006 に替えた後に、子 2 を子 1 の今の指定の人(u0005)へ振替える・処理対象者の変更で替える → 422。
     */
    public function test_the_designated_person_of_a_retargeted_child_is_rejected(): void
    {
        $case = $this->placedCase(WorkflowNodeKind::Parallel);
        $this->retargetOn($case, 'family.1', ['u0006']);
        $before = $this->snapshot($case);

        $errors = [
            $this->attemptOf(fn () => $this->transferOn($case, 'family.2', 'u0009', $this->seededUser('u0005')->id)),
            $this->attemptOf(fn () => $this->retargetOn($case, 'family.2', ['u0005'])),
        ];

        $this->assertSame([['to_user_id' => self::OTHER_CHILD], ['user_ids' => self::OTHER_CHILD]], $errors);
        $this->assertSame($before, $this->snapshot($case));
    }

    /**
     * 横配置の到達していない子 3 の今の指定の人(u0011)も、今までどおり子 1 の振替先・変更後の人にできない。
     */
    public function test_the_designated_person_of_an_unreached_serial_child_is_rejected(): void
    {
        $case = $this->placedCase(WorkflowNodeKind::Serial);

        $errors = [
            $this->attemptOf(fn () => $this->transferOn($case, 'family.1', 'u0005', $this->seededUser('u0011')->id)),
            $this->attemptOf(fn () => $this->retargetOn($case, 'family.1', ['u0011'])),
        ];

        $this->assertSame([['to_user_id' => self::OTHER_CHILD], ['user_ids' => self::OTHER_CHILD]], $errors);
    }

    /**
     * 通った振替(子 2 → u0006)と処理対象者の変更(子 1 → u0008)の後に、子 1・子 3 の承認で可決して子 2 を省き、final から合議へ差戻すと、
     * 子は処理した人か今の指定の人で到達し直し、同じ人が 2 つの子を持たない。
     */
    public function test_a_send_back_to_the_council_after_the_changes_has_no_duplicates(): void
    {
        $case = $this->placedCase(WorkflowNodeKind::Parallel);
        $this->transferOn($case, 'family.2', 'u0009', $this->seededUser('u0006')->id);
        $this->retargetOn($case, 'family.1', ['u0008']);
        $this->approve($case, 'family.1', 'u0008');
        $this->approve($case, 'family.3', 'u0011');

        $this->sendBackOn($case, 'final', 'u0010', 'family');

        $children = $this->pick($case, 'family.1', 'family.2', 'family.3');
        $this->assertSame(['waiting[u0008]', 'waiting[u0009]', 'waiting[u0011]'], array_values($children));
    }

    /**
     * 進むで処理済みにした子 1(処理対象者の変更で u0006 にしてから管理者が進めた)の今の人は今の指定の人(u0005): 子 2 の振替先に u0005 を拒み、
     * 進めた管理者 u0004 は拒まない(案件操作の処理者を数えない = 案件操作.md の 15.1 節)。
     */
    public function test_a_child_moved_forward_counts_its_designated_person_not_the_manager(): void
    {
        $case = $this->placedCase(WorkflowNodeKind::Parallel);
        $this->retargetOn($case, 'family.1', ['u0006']);
        $this->moveOn($case, 'family.1', 'forward');

        $designatedId = $this->seededUser('u0005')->id;
        $toDesignated = $this->attemptOf(fn () => $this->transferOn($case, 'family.2', 'u0009', $designatedId));
        $this->transferOn($case, 'family.2', 'u0009', $this->seededUser(self::MANAGER)->id);

        $this->assertSame(['to_user_id' => self::OTHER_CHILD], $toDesignated);
        $this->assertSame('waiting[u0004]', $this->shape($case)['family.2']);
    }

    /**
     * 配置の版で、chief が family に u0005・u0009・u0011 を指定して承認した案件。
     */
    private function placedCase(WorkflowNodeKind $kind): WorkflowCase
    {
        $this->addVersion($this->placementRoute($kind));
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', 'u0002', ['family' => ['u0005', 'u0009', 'u0011']]);

        return $case;
    }

    /**
     * @return array{array<string, string>, array<string, int>, int}
     */
    private function snapshot(WorkflowCase $case): array
    {
        return [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)];
    }
}
