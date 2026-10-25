<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Workflow\WorkflowCase;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsApplicationTargetCopies;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 写しの値の入口 workflow.target-values(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 10.1 節・10.3 節・
 * 11 節): 写しの規則のとおりの { values, details }、判定の表の各行(フォームのフロー・申請対象の項目の 404 → フォームを開けない・元の read が無い
 * 403 → record の段・申請対象の 404)を自分と代理元で、record の有無で通す。書き込まず、閲覧の判定より前に値を読まない。
 */
final class TargetValueControllerTest extends TransactionalTestCase
{
    use BuildsApplicationTargetCopies;
    use RegistersProxies;
    use RunsWorkflowOperations;

    /** レコードの値を読む SQL(行の判定の exists・業務モジュールの引き当ては含まない) */
    private const RECORD_ROW_SQL = '/^select \* from "records" /';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow($this->operationDay()->toDateString().' 03:00:00 UTC');
    }

    /**
     * 同じコード・型・参照先・複数の指定の項目と、明細の行(id なし・並びのまま)が写り、申請対象の項目には元のレコードが入る。
     * 型・参照先・複数の指定が違う項目、申請書だけの項目、添付は写らない。空の値は空、読めない参照先は値のまま(表示名なし)で写る。
     */
    public function test_the_values_follow_the_copy_rule(): void
    {
        [$u0001, $u0003] = $this->usersOf('u0001', 'u0003');
        $this->copyFlow([$u0001]);
        $ids = $this->hubRecord($u0001, $u0003);
        $this->onlyRelated('m_ref_a');
        $this->actingAs($u0001);

        $response = $this->getJson($this->valuesUrl($ids['hub'], ['flow' => self::COPY_FLOW]))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame($this->expectedCopy($ids), self::sorted((array) $response->json()));
    }

    /**
     * フォームのフローが無い(クエリが無い・フローが無い・案件が無い・閲覧できない案件)と、申請対象の項目の無いフローは 404(権限より先)。
     */
    public function test_a_missing_form_or_a_content_without_the_target_is_404(): void
    {
        [$u0001, $u0003] = $this->usersOf('u0001', 'u0003');
        $flowId = $this->copyFlow([$u0001]);
        $this->createFlow();
        $hub = $this->hubRecord($u0001)['hub'];
        $othersDraft = WorkflowCase::factory()->draft()
            ->create(['workflow_flow_id' => $flowId, 'user_id' => $u0003->id]);

        foreach ([$u0001, $u0003] as $user) {
            $this->actingAs($user);
            $queries = [
                [], ['flow' => 'wf_missing'], ['case' => '999999999'], ['case' => 'abc'], ['flow' => self::FLOW_CODE],
            ];
            $queries[] = $user->is($u0001) ? ['case' => (string) $othersDraft->id] : ['case' => '0'];

            foreach ($queries as $query) {
                $this->getJson($this->valuesUrl($hub, $query))->assertNotFound();
            }
        }
    }

    /**
     * 新規のフォーム: 申請のノードの対象者・申請書の create・元の read のどれかが無ければ 403。代理元(クエリ applicant)がそろえば代理元で通る
     * (代理が無い・承認の代理なら自分のまま 403)。
     */
    public function test_the_new_form_is_judged_on_the_requester_or_the_principal(): void
    {
        [$u0001, $principal, $u0003] = $this->usersOf('u0001', 'u0009', 'u0003');
        $flowId = $this->copyFlow([$u0001, $principal]);
        $hub = $this->hubRecord($u0001)['hub'];
        $this->addProxy($principal, $u0003, WorkflowProxyKind::Approve, $flowId);
        $flow = ['flow' => self::COPY_FLOW];
        $asPrincipal = [...$flow, 'applicant' => (string) $principal->id];

        $this->assertSame(200, $this->statusAs($u0001, $hub, $flow));
        $this->assertSame([403, 403], $this->statuses($u0003, $hub, $flow, $asPrincipal));

        $this->addProxy($principal, $u0001, WorkflowProxyKind::Apply, $flowId);
        $this->allowOnly(self::COPY_FORM, RecordModuleAction::Create, $principal);
        $this->assertSame([403, 200], $this->statuses($u0001, $hub, $flow, $asPrincipal));

        $this->allowOnly(self::COPY_FORM, RecordModuleAction::Create, $u0001, $principal);
        $this->allowOnly(self::HUB, RecordModuleAction::Read, $principal);
        $this->assertSame([403, 200], $this->statuses($u0001, $hub, $flow, $asPrincipal));
    }

    /**
     * そのものを申請するフォーム(クエリ record): allows(read と update。create は要らない)を判定を求める者で見て 403、続けて record の段
     * (作成者・業務モジュール・行の判定)で 404。代理元が作成者なら代理元で通る。権限の無い人には record の段に当たるレコードでも 403。
     */
    public function test_the_record_form_is_judged_on_allows_and_then_on_the_record(): void
    {
        [$u0001, $principal, $u0003] = $this->usersOf('u0001', 'u0009', 'u0003');
        $flowId = $this->copyFlow([$u0001, $principal, $u0003]);
        $hub = $this->hubRecord($u0001)['hub'];
        [$own, $principals, $others] = array_map(
            fn (User $creator) => $this->copyFormRecord($creator, null), [$u0001, $principal, $u0003],
        );
        $this->addProxy($principal, $u0001, WorkflowProxyKind::Apply, $flowId);
        $this->allowOnly(self::COPY_FORM, RecordModuleAction::Create);
        $query = fn (int $record, ?User $applicant = null) => ['flow' => self::COPY_FLOW, 'record' => (string) $record]
            + ($applicant === null ? [] : ['applicant' => (string) $applicant->id]);
        $missing = (int) Record::query()->max('id') + 1000;

        $this->assertSame([200, 404, 200, 404, 404, 404], $this->statuses(
            $u0001, $hub, $query($own), $query($principals), $query($principals, $principal),
            $query($others), $query($missing), $query($hub),
        ));

        $this->onlyRelated(self::COPY_FORM);
        $this->assertSame([200, 404], $this->statuses($u0001, $hub, $query($own), $query($others)));

        $this->allowOnly(self::COPY_FORM, RecordModuleAction::Update, $principal);
        $this->assertSame([403, 403, 403, 200], $this->statuses(
            $u0001, $hub, $query($own), $query($others), $query($missing), $query($principals, $principal),
        ));
    }

    /**
     * 申請対象: 無い・明細の行・申請対象の参照先でない業務モジュールのもの・行の判定で見えないものは 404(record の有無の両方)。
     * 元の read の無い人には、どれも 403。行の判定は判定を求める者(代理元)で見る。
     */
    public function test_the_target_is_404_unless_the_requester_can_view_it(): void
    {
        [$u0001, $principal, $u0003] = $this->usersOf('u0001', 'u0009', 'u0003');
        $flowId = $this->copyFlow([$u0001, $principal]);
        $this->addProxy($principal, $u0001, WorkflowProxyKind::Apply, $flowId);
        [$own, $principals, $others] = array_map(
            fn (User $creator) => $this->hubRecord($creator)['hub'], [$u0001, $principal, $u0003],
        );
        $form = $this->copyFormRecord($u0001, $own);
        $row = Record::query()->where('parent_record_id', $own)->value('id');
        $missing = (int) Record::query()->max('id') + 1000;
        $this->onlyRelated(self::HUB);
        $queries = [['flow' => self::COPY_FLOW], ['flow' => self::COPY_FLOW, 'record' => (string) $form]];
        $asPrincipal = ['flow' => self::COPY_FLOW, 'applicant' => (string) $principal->id];

        foreach ($queries as $query) {
            $targets = [$own, $missing, (int) $row, $form, $others, $principals];
            $this->assertSame([200, 404, 404, 404, 404, 404], $this->statusesOf($u0001, $targets, $query));
        }

        $this->assertSame([200, 404], $this->statusesOf($u0001, [$principals, $own], $asPrincipal));

        $this->allowOnly(self::HUB, RecordModuleAction::Read);
        $statuses = $this->statusesOf($u0001, [$own, $missing, (int) $row, $form, $others], $queries[1]);
        $this->assertSame([403, 403, 403, 403, 403], $statuses);
    }

    /**
     * 案件のフォーム(クエリ case。flow と record は読まない): 一時保存はこのフローで申請できる人、再申請は update を許される人で、
     * どちらも案件の申請者だけが開ける。
     */
    public function test_the_case_form_reads_the_draft_and_the_reapplication(): void
    {
        [$u0001, $u0003] = $this->usersOf('u0001', 'u0003');
        $flowId = $this->copyFlow([$u0001]);
        $hub = $this->hubRecord($u0001)['hub'];
        $draft = WorkflowCase::factory()->draft()->create(['workflow_flow_id' => $flowId, 'user_id' => $u0001->id]);
        $active = $this->applyCopy($u0001);
        $others = $this->copyFormRecord($u0003, null);
        $case = fn (WorkflowCase $case) => [
            'case' => (string) $case->id, 'flow' => 'wf_missing', 'record' => (string) $others,
        ];

        $this->assertSame([200, 200], $this->statuses($u0001, $hub, $case($draft), $case($active)));

        $this->allowOnly(self::COPY_FORM, RecordModuleAction::Create);
        $this->assertSame([403, 200], $this->statuses($u0001, $hub, $case($draft), $case($active)));

        $this->allowOnly(self::COPY_FORM, RecordModuleAction::Update);
        $this->assertSame(403, $this->statusAs($u0001, $hub, $case($active)));
    }

    /**
     * どの要求でもレコード・案件を作らない。閲覧できない申請対象では値を読む SQL を出さない(閲覧できるものでは出る)。
     */
    public function test_nothing_is_written_and_the_values_are_read_only_after_the_view_check(): void
    {
        [$u0001, $u0003] = $this->usersOf('u0001', 'u0003');
        $this->copyFlow([$u0001]);
        [$own, $others] = [$this->hubRecord($u0001)['hub'], $this->hubRecord($u0003)['hub']];
        $this->onlyRelated(self::HUB);
        $counts = $this->workflowCounts();
        $this->actingAs($u0001);
        $flow = ['flow' => self::COPY_FLOW];

        $hidden = $this->recordedSql(fn () => $this->getJson($this->valuesUrl($others, $flow))->assertNotFound());
        $visible = $this->recordedSql(fn () => $this->getJson($this->valuesUrl($own, $flow))->assertOk());

        $this->assertSame([[], true], [
            preg_grep(self::RECORD_ROW_SQL, $hidden), preg_grep(self::RECORD_ROW_SQL, $visible) !== [],
        ]);
        $this->assertSame($counts, $this->workflowCounts());
    }

    /**
     * {target} は 1〜18 桁の数字だけ(ほかはルートに当たらず 404)。
     */
    public function test_the_route_takes_only_up_to_18_digits(): void
    {
        $this->actingAs($this->seededUser('u0001'));

        foreach (['abc', '-1', str_repeat('1', 19)] as $target) {
            $this->getJson("/workflow/target-values/{$target}?flow=".self::COPY_FLOW)->assertNotFound();
        }

        $this->assertSame('workflow.target-values', app('router')->getRoutes()->match(
            request()->create('/workflow/target-values/'.str_repeat('1', 18)),
        )->getName());
    }

    /**
     * @param  array<string, string>  $query
     */
    private function valuesUrl(int $target, array $query): string
    {
        return "/workflow/target-values/{$target}?".http_build_query($query);
    }

    /**
     * @param  array<string, string>  ...$queries
     * @return list<int>
     */
    private function statuses(User $user, int $target, array ...$queries): array
    {
        return array_values(array_map(fn (array $query) => $this->statusAs($user, $target, $query), $queries));
    }

    /**
     * @param  list<int>  $targets
     * @param  array<string, string>  $query
     * @return list<int>
     */
    private function statusesOf(User $user, array $targets, array $query): array
    {
        return array_map(fn (int $target) => $this->statusAs($user, $target, $query), $targets);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function statusAs(User $user, int $target, array $query): int
    {
        $this->actingAs($user);

        return $this->getJson($this->valuesUrl($target, $query))->status();
    }
}
