<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Notice\Api\NoticeMessage;
use App\Actions\Notice\Api\SendNotices;
use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\CaseContact;
use App\Actions\Workflow\TransferCase;
use App\Enums\Workflow\WorkflowNoticeType;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Notice\NoticeList;
use App\Queries\Workflow\WorkflowNoticeTargets;
use Tests\Support\ReadsCaseNotices;
use Tests\Support\RegistersProxies;
use Tests\Support\StartsWithoutSeededNotices;
use Tests\Support\TransactionalTestCase;

/**
 * ワークフローの通知の行の移り先(docs の 設計/ワークフロー/案件の運用/通知.md の 5 節): 見える案件は詳細へ(対象の表示名なし)、
 * 見えない案件(連絡を受けた閲覧できない人・期間の切れた代理先・無い案件)は移り先なしと「この申請は閲覧できません。」。
 * 振替元は振替の行の処理者として処理済に出るので移り先を持つ。フロー = 申請(u0001)→ 承認 1(u0002)→ 承認 2(u0003)。
 */
final class WorkflowNoticeTargetsTest extends TransactionalTestCase
{
    use ReadsCaseNotices;
    use RegistersProxies;
    use StartsWithoutSeededNotices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlow();
    }

    public function test_a_visible_case_links_to_its_detail_and_an_invisible_one_shows_the_line(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $contact = new CaseContact([$this->seededUser('u0006')->id], null);
        $nodeId = $this->caseNode($case, 'approval1')->id;
        $context = $this->contextOf($this->seededUser('u0002'));
        $revision = $this->revisionOf($case);
        app(ApproveCase::class)->execute($context, $case->id, $revision, $nodeId, null, [], null, $contact);

        $this->assertSame([[$this->detailUrl($case), null, null]], $this->destinations('u0002'));
        $this->assertSame([[null, null, WorkflowNoticeTargets::CASE_UNAVAILABLE_TEXT]], $this->destinations('u0006'));
    }

    public function test_an_expired_proxy_and_a_missing_case_have_no_destination(): void
    {
        $this->addProxy($this->seededUser('u0002'), $this->seededUser('u0016'), end: '2026-10-20');
        $case = $this->applyAs($this->seededUser('u0001'));
        $missingId = (int) WorkflowCase::query()->max('id') + 1000;
        $type = WorkflowNoticeType::ProcessRequest->value;
        $message = new NoticeMessage($type, WorkflowNoticeTargets::CASE_TARGET_TYPE, $missingId, null, []);
        app(SendNotices::class)->execute($message, [$this->seededUser('u0003')->id]);
        $unavailable = [[null, null, WorkflowNoticeTargets::CASE_UNAVAILABLE_TEXT]];

        $this->assertSame([[$this->detailUrl($case), null, null]], $this->destinations('u0016'));
        $this->assertSame($unavailable, $this->destinations('u0016', '2026-10-25'));
        $this->assertSame($unavailable, $this->destinations('u0003'));
    }

    public function test_the_transfer_source_keeps_the_destination(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $nodeId = $this->caseNode($case, 'approval1')->id;
        $to = $this->seededUser('u0006')->id;
        $context = $this->contextOf($this->seededUser('u0002'));
        app(TransferCase::class)->execute($context, $case->id, $this->revisionOf($case), $nodeId, $to, null);

        $this->assertSame([[$this->detailUrl($case), null, null]], $this->destinations('u0002'));
    }

    /**
     * 鈴の 10 件の移り先は、案件の閲覧の判定の問い合わせ 1 回で決まる(行ごとに問い合わせない)。
     */
    public function test_the_latest_ten_rows_are_judged_with_one_case_query(): void
    {
        foreach (range(1, 10) as $ignored) {
            $this->applyAs($this->seededUser('u0001'));
        }

        $context = $this->contextOf($this->seededUser('u0002'));
        $rows = [];
        $sql = $this->recordedSql(function () use ($context, &$rows): void {
            $rows = app(NoticeList::class)->latest($context);
        });

        $this->assertCount(10, $rows);
        $this->assertCount(10, array_filter(array_column($rows, 'url')));
        $caseQueries = array_filter($sql, fn (string $statement) => str_contains($statement, '"workflow_cases"'));
        $this->assertCount(1, $caseQueries);
    }

    private function detailUrl(WorkflowCase $case): string
    {
        return route('workflow.cases.show', ['case' => $case->id]);
    }

    /**
     * @return list<array{string|null, string|null, string|null}> その人の鈴の行の [url, targetLabel, unavailableText]
     */
    private function destinations(string $userCode, ?string $date = null): array
    {
        $rows = app(NoticeList::class)->latest($this->contextOf($this->seededUser($userCode), date: $date));

        return array_map(fn (array $row) => [$row['url'], $row['targetLabel'], $row['unavailableText']], $rows);
    }
}
