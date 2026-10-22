<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\CaseContact;
use App\Actions\Workflow\CaseOperationResult;
use App\Actions\Workflow\SendCaseNotices;
use App\Enums\Workflow\WorkflowNoticeType;
use App\Models\Workflow\WorkflowCase;
use Carbon\CarbonImmutable;
use Closure;
use RuntimeException;
use Tests\Support\OperatesAsCaseManager;
use Tests\Support\ReadsCaseNotices;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 案件の管理者の操作の通知(docs の 設計/ワークフロー/案件の運用/通知.md の 3 節、案件の操作/案件操作.md の 15.2 節の通知の列、
 * 同時実行と検査.md の 14.4 節): 処理対象者の変更・再展開 = 新しく入った人とその代理先へ「処理担当者の変更」(残した人・管理者・今日に有効でない人には
 * 届かない。params = 処理依頼と同じ値とコメント、ステップ名は展開の行なら親の名前)。保留解除・指定の変更・案件の削除と、422 で失敗した操作は通知が無い。
 * 通知の 1 か所で例外が起きると操作ごと巻き戻る。ルートの見本 R1(予算 100)。管理者 = u0004。
 */
final class CaseManagerNoticesTest extends TransactionalTestCase
{
    use OperatesAsCaseManager;
    use ReadsCaseNotices;
    use RegistersProxies;

    private const RETARGET = WorkflowNoticeType::Retarget;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
        $this->addVersion($this->r1());
    }

    /**
     * 課長(u0002)を u0002・u0006 に変える: 新しく入った u0006 と、その承認の代理先 u0017 に届く。残した u0002、u0006 の代理先でも管理者 u0004、
     * 今日に有効でない代理先 u0014 には届かない。
     */
    public function test_retargeting_notifies_the_new_people_and_their_proxies(): void
    {
        $newcomer = $this->seededUser('u0006');
        $this->addProxy($newcomer, $this->seededUser('u0017'));
        $this->addProxy($newcomer, $this->seededUser(self::MANAGER));
        $this->addProxy($newcomer, $this->seededUser('u0014'));
        [$case] = $this->applyOnRoute(['budget' => 100]);

        $retarget = fn () => $this->retargetOn($case, 'section_chief', ['u0002', 'u0006'], comment: '増員');
        $notices = $this->noticesOf($retarget);

        $params = ['step_name' => '課長', 'step_kind' => 'approve', 'comment' => '増員'];
        $this->assertSame([
            $this->caseNotice(self::RETARGET, 'u0006', self::MANAGER, $case, $params),
            $this->caseNotice(self::RETARGET, 'u0017', self::MANAGER, $case, $params),
        ], $notices);
    }

    /**
     * 合議の子 1 を u0006 に変えた後の再展開で、写した指定の u0005 に戻ると u0005 に届く(ステップ名は親の名前、コメントなし)。
     */
    public function test_reexpanding_a_council_child_notifies_with_the_parent_name(): void
    {
        $case = $this->designatedCase();
        $this->retargetOn($case, 'council.1', ['u0006']);

        $notices = $this->noticesOf(fn () => $this->reexpandOn($case, 'council.1'));

        $params = ['step_name' => '関係部署の合議', 'step_kind' => 'approve', 'comment' => null];
        $this->assertSame([$this->caseNotice(self::RETARGET, 'u0005', self::MANAGER, $case, $params)], $notices);
    }

    /**
     * 保留解除(管理者)・指定の変更・案件の削除と、422 で失敗した処理対象者の変更は、通知を作らない。
     */
    public function test_other_manager_operations_and_failures_send_nothing(): void
    {
        $case = $this->designatedCase();
        $this->holdOn($case, 'council.1', 'u0005');

        $notices = [
            $this->noticesOf(fn () => $this->releaseOn($case, 'council.1', self::MANAGER)),
            $this->noticesOf(fn () => $this->reassignOn($case, 'owner', ['u0006'])),
            $this->noticesOf(fn () => $this->attemptOf(fn () => $this->retargetOn($case, 'council.1', ['u0014']))),
            $this->noticesOf(fn () => $this->deleteCaseAs($case)),
        ];

        $this->assertSame([[], [], [], []], $notices);
    }

    /**
     * 通知の 1 か所で例外が起きると、処理対象者の変更ごと巻き戻る(束縛を例外を投げるものに差し替える)。
     */
    public function test_an_exception_while_sending_notices_rolls_back_the_retargeting(): void
    {
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $this->app->instance(SendCaseNotices::class, new class extends SendCaseNotices
        {
            public function __construct() {}

            public function execute(CaseOperationResult $result, ?CaseContact $contact, CarbonImmutable $date): void
            {
                throw new RuntimeException('通知を書けなかった');
            }
        });
        $before = [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)];

        $this->assertThrows(fn () => $this->retargetOn($case, 'section_chief', ['u0006']), RuntimeException::class);

        $this->assertSame($before, [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)]);
    }

    /**
     * 課長(u0002)が合議 u0005・u0009・u0011 と責任者 u0007 を指定して承認した案件。
     */
    private function designatedCase(): WorkflowCase
    {
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $designations = ['council' => ['u0005', 'u0009', 'u0011'], 'owner' => ['u0007']];
        $this->approve($case, 'section_chief', 'u0002', $designations);

        return $case;
    }

    /**
     * @param  Closure(): mixed  $operate
     * @return list<array{string, string, string|null, string|null, array<string, mixed>}> 操作でできた通知の行
     */
    private function noticesOf(Closure $operate): array
    {
        $lastId = $this->lastNoticeId();
        $operate();

        return $this->noticesSince($lastId);
    }
}
