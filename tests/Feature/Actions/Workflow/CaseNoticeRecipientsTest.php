<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\CaseContact;
use App\Actions\Workflow\CaseOperationResult;
use App\Actions\Workflow\RouteNodeInput;
use App\Actions\Workflow\SendCaseNotices;
use App\Actions\Workflow\TransferCase;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNoticeType;
use App\Models\Workflow\WorkflowCase;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Support\OperatesOnRoutes;
use Tests\Support\ReadsCaseNotices;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 通知の宛先の規則と、通知を操作と同じトランザクションで書くこと(docs の 設計/ワークフロー/案件の運用/通知.md の 3 節と 4 節、
 * 設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 14.4 節、宛先から除く人と代理先を足す順 = (増えた人 ∪ 増えた人の代理先)− 処理者・実行者 − 今日に有効でない人)。
 */
final class CaseNoticeRecipientsTest extends TransactionalTestCase
{
    use OperatesOnRoutes;
    use ReadsCaseNotices;
    use RegistersProxies;

    private const PROCESS = WorkflowNoticeType::ProcessRequest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlow();
    }

    /**
     * 並列の 2 つのノードに同じ人が到達すると、処理依頼はノードごとに 2 行(呼ぶ単位 = (種類, ノード))。
     */
    public function test_the_same_person_reached_on_two_parallel_steps_gets_two_requests(): void
    {
        $this->addVersion([[
            $this->applyNode(),
            new RouteNodeInput('sync', '同期', WorkflowNodeKind::SyncStart, closingCode: 'sync_end'),
            $this->approveNode('a1', 'u0002', ['apply']),
            $this->approveNode('b1', 'u0002', ['apply']),
            new RouteNodeInput('sync_end', '同期終了', WorkflowNodeKind::SyncEnd),
            $this->approveNode('final', 'u0010', ['apply']),
        ], $this->chain(
            ['apply', 'sync'], ['sync', 'a1'], ['a1', 'sync_end'],
            ['sync', 'b1'], ['b1', 'sync_end'], ['sync_end', 'final'],
        )]);
        $lastId = $this->lastNoticeId();

        [$case] = $this->applyOnRoute([]);

        $this->assertSame([
            $this->caseNotice(self::PROCESS, 'u0002', 'u0001', $case, ['step_name' => 'a1', 'step_kind' => 'approve']),
            $this->caseNotice(self::PROCESS, 'u0002', 'u0001', $case, ['step_name' => 'b1', 'step_kind' => 'approve']),
        ], $this->noticesSince($lastId));
    }

    /**
     * 除いた人の代理先には届く: u0002 が承認して次のステップにも u0002 がいる → u0002 の代理先 u0016・u0017 に届く。
     * u0016 が u0002 の代理先として承認して次のステップに u0002 がいる → u0002・u0016 には届かず、u0002 のほかの代理先 u0017 に届く。
     */
    public function test_the_proxies_of_an_excluded_person_still_receive_requests(): void
    {
        $this->addVersion([[
            $this->applyNode(),
            $this->approveNode('first', 'u0002', ['apply']),
            $this->approveNode('second', 'u0002', ['apply', 'first']),
        ], $this->chain(['apply', 'first'], ['first', 'second'])]);
        $chief = $this->seededUser('u0002');
        $this->addProxy($chief, $this->seededUser('u0016'));
        $this->addProxy($chief, $this->seededUser('u0017'));
        $request = ['step_name' => 'second', 'step_kind' => 'approve'];

        [$byHimself] = $this->applyOnRoute([]);
        $lastId = $this->lastNoticeId();
        $this->approve($byHimself, 'first', 'u0002');
        $this->assertSame([
            $this->caseNotice(self::PROCESS, 'u0016', 'u0002', $byHimself, $request),
            $this->caseNotice(self::PROCESS, 'u0017', 'u0002', $byHimself, $request),
        ], $this->noticesSince($lastId));

        [$byProxy] = $this->applyOnRoute([]);
        $lastId = $this->lastNoticeId();
        $context = $this->contextOf($this->seededUser('u0016'));
        $firstId = $this->labelIds($byProxy)['first'];
        $revision = $this->revisionOf($byProxy);
        app(ApproveCase::class)->execute($context, $byProxy->id, $revision, $firstId, null, [], $chief->id);
        $expected = [$this->caseNotice(self::PROCESS, 'u0017', 'u0016', $byProxy, $request)];
        $this->assertSame($expected, $this->noticesSince($lastId));
    }

    /**
     * 操作が 422 で失敗すると通知の行は無い。
     */
    public function test_a_failed_operation_leaves_no_notice(): void
    {
        $this->addVersion($this->r1());
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $lastId = $this->lastNoticeId();
        $chiefId = $this->labelIds($case)['section_chief'];
        $context = $this->contextOf($this->seededUser('u0002'));
        $retired = $this->seededUser('u0014')->id;

        $this->assertThrows(
            fn () => app(TransferCase::class)
                ->execute($context, $case->id, $this->revisionOf($case), $chiefId, $retired, null),
            ValidationException::class,
        );

        $this->assertSame([], $this->noticesSince($lastId));
    }

    /**
     * 通知の 1 か所で例外が起きると、操作ごと巻き戻る(操作と同じトランザクションで書き、後回しにしない)。
     */
    public function test_an_exception_while_sending_notices_rolls_back_the_operation(): void
    {
        $this->addVersion($this->r1());
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
        $lastId = $this->lastNoticeId();

        $designation = ['council' => ['u0005', 'u0009'], 'owner' => ['u0007']];
        $approve = fn () => $this->approve($case, 'section_chief', 'u0002', $designation);
        $this->assertThrows($approve, RuntimeException::class);

        $this->assertSame($before, [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)]);
        $this->assertSame([], $this->noticesSince($lastId));
        $this->assertInstanceOf(WorkflowCase::class, WorkflowCase::query()->find($case->id));
    }
}
