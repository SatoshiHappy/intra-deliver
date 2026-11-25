<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Enums\Workflow\WorkflowCompletionReason;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordRevision;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Workflow\CaseNumbers;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TransactionalTestCase;

/**
 * 業務データの入れ物の画面に出る持ち主の案内を、起動に登録したワークフローの実装で通す(docs の
 * 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 5.1 の表の各行を詳細と編集の両方で、更新履歴.md の 5.1 の表の各行、
 * 業務モジュールの管理.md の 2 節の表の案内の文)。見本の申請(WF-000005 = 未完了、WF-000001 = 完了)と、システム管理者 u0004(管理の述語で申請を閲覧できる)・
 * 吉田 達也 u0009(申請に関わらない。参照制限を外したレコードは業務データの画面で見られる)で確かめる。
 */
final class RecordHoldNoticeScreensTest extends TransactionalTestCase
{
    use UsesSeededCases;

    private const REASON = '申請済みのため変更できません';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-13 03:00:00 UTC');
        $this->withoutVite();
    }

    /**
     * 5.1 の表の「閲覧できる × 未完了」: 理由 + 申請番号のリンク + 札「<状態>」+ suffix = 現在のステップ。詳細と編集で同じ。
     */
    public function test_a_viewer_of_an_active_case_sees_the_number_the_badge_and_the_current_nodes(): void
    {
        $case = $this->seededCaseNumbered(5);
        $this->actingAs($this->admin());

        $this->assertHold($case, ['reason' => self::REASON, 'state' => [
            'link' => $this->linkOf($case),
            'status' => '承認待ち', 'tone' => 'pending', 'suffix' => '課長の承認',
        ]]);
    }

    /**
     * 5.1 の表の「閲覧できる × 完了」: 札「完了(<完了の理由>)」で suffix は無い。
     */
    public function test_a_viewer_of_a_completed_case_sees_the_completed_badge(): void
    {
        $case = $this->seededCaseNumbered(1);
        $case->update(['completion_reason' => WorkflowCompletionReason::Approved]);
        $this->actingAs($this->admin());

        $this->assertHold($case, ['reason' => self::REASON, 'state' => [
            'link' => $this->linkOf($case),
            'status' => '完了(承認)', 'tone' => 'settled', 'suffix' => null,
        ]]);
    }

    /**
     * 5.1 の表の「閲覧できない × どれでも」(未完了で通す): 理由の 1 行だけ。リンクを開いても(描いた後に閲覧を失った人と同じ)申請の詳細の規則のまま 404。
     */
    public function test_a_person_who_cannot_view_the_case_sees_only_the_reason(): void
    {
        $case = $this->seededCaseNumbered(5);
        $this->recordOf($case)->update(['row_access_restricted' => false]);
        $this->actingAs($this->outsider());

        $this->assertHold($case, ['reason' => self::REASON, 'state' => null]);
        $this->get(route('workflow.cases.show', ['case' => $case->id]))->assertNotFound();
    }

    /**
     * 5.1 の表の「持ち主なし」: 申請していない申請書のレコードには持ち主の 1 行が出ない(案内を引かない)。
     */
    public function test_a_record_without_a_case_has_no_hold(): void
    {
        $case = $this->seededCaseNumbered(5);
        $record = Record::factory()->create(['record_module_id' => $this->recordOf($case)->record_module_id]);
        $this->actingAs($this->admin());

        foreach (['', '/edit'] as $page) {
            $this->get("{$this->recordUrl($record)}{$page}")->assertOk()
                ->assertInertia(fn (Assert $inertia) => $inertia->where('hold', null));
        }
    }

    /**
     * 更新履歴.md の 5.1 の表: 申請・再申請・添付を付けた処理の版(履歴の行が指す版)に申請番号のリンクが、版を見られる人の全員に出る。
     * AI の書き込みの版(契機あり・履歴の行が指さない)はリンクなし、業務の画面の版は契機が無い。
     */
    public function test_the_revisions_pointed_at_by_the_history_rows_carry_the_link_for_everyone(): void
    {
        $case = $this->seededCaseNumbered(5);
        $record = $this->recordOf($case);
        $pointed = WorkflowCaseHistory::query()
            ->where('workflow_case_id', $case->id)
            ->whereNotNull('record_revision_id')
            ->pluck('record_revision_id')->map(fn (mixed $id) => (int) $id)->all();
        $next = (int) RecordRevision::query()->where('record_id', $record->id)->max('revision');
        $of = ['record_module_id' => $record->record_module_id, 'record_id' => $record->id];
        $ai = RecordRevision::factory()->update()->fromOperation('AI(議事録の作成)', null)
            ->create([...$of, 'revision' => $next + 1]);
        $plain = RecordRevision::factory()->update()->create([...$of, 'revision' => $next + 2]);
        $record->update(['row_access_restricted' => false]);
        $this->assertNotSame([], $pointed);

        foreach ([$this->outsider(), $this->admin()] as $user) {
            $this->actingAs($user);

            $page = $this->get("{$this->recordUrl($record)}?panel=revisions")->assertOk()->viewData('page');
            $rows = $page['props']['panelContent']['rows'];
            $links = array_column(array_map(fn (array $row) => [
                'id' => $row['id'],
                'link' => $row['origin'] === null ? 'no origin' : $row['origin']['link'],
            ], $rows), 'link', 'id');

            $this->assertSame('no origin', $links[$plain->id]);
            $this->assertNull($links[$ai->id]);

            foreach (array_intersect($pointed, array_keys($links)) as $revisionId) {
                $this->assertSame($this->linkOf($case), $links[$revisionId], (string) $revisionId);
            }
        }
    }

    /**
     * 業務モジュールの管理.md の 2 節: 添付を使う進行中の申請がある申請書の業務モジュールの編集に、数つきの確かめの文が出る。
     */
    public function test_the_module_edit_carries_the_count_of_the_active_cases_that_use_attachments(): void
    {
        $case = $this->seededCaseNumbered(5);
        $moduleId = $this->recordOf($case)->record_module_id;
        $count = WorkflowCase::query()->join('records', 'records.id', '=', 'workflow_cases.record_id')
            ->where('records.record_module_id', $moduleId)->where('workflow_cases.state', 'active')
            ->where('workflow_cases.is_attachment_enabled', true)->count();
        $module = $this->recordOf($case)->recordModule()->sole();
        $module->update(['is_attachment_enabled' => true]);
        $this->assertGreaterThan(0, $count);
        $this->actingAs($this->admin());

        $this->get("/records/admin/modules/{$module->code}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('attachmentDisableNotices', [
                "添付ファイルを追加・削除できる進行中の申請が {$count} 件あります。解除すると、これらの申請の再申請と処理でも添付ファイルを追加・削除できなくなります。",
            ]));
    }

    /**
     * 詳細と編集の hold が同じ。
     *
     * @param  array<string, mixed>  $hold
     */
    private function assertHold(WorkflowCase $case, array $hold): void
    {
        foreach (['', '/edit'] as $page) {
            $this->get("{$this->recordUrl($this->recordOf($case))}{$page}")->assertOk()
                ->assertInertia(fn (Assert $inertia) => $inertia->where('hold', $hold));
        }
    }

    private function seededCaseNumbered(int $number): WorkflowCase
    {
        return WorkflowCase::query()->findOrFail($this->caseNumbered($number));
    }

    private function recordOf(WorkflowCase $case): Record
    {
        return Record::query()->findOrFail($case->record_id);
    }

    private function recordUrl(Record $record): string
    {
        return "/records/data/{$record->recordModule()->sole()->code}/{$record->id}";
    }

    /**
     * @return array{label: string, url: string}
     */
    private function linkOf(WorkflowCase $case): array
    {
        return [
            'label' => CaseNumbers::format((int) $case->case_number),
            'url' => route('workflow.cases.show', ['case' => $case->id]),
        ];
    }

    private function admin(): User
    {
        return User::query()->where('code', 'u0004')->sole();
    }

    private function outsider(): User
    {
        return User::query()->where('code', 'u0009')->sole();
    }
}
