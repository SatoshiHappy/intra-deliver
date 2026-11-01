<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Records\RecordRevisionWriter;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Records\RecordRevisionAction;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Workflow\CaseNumbers;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Inertia\Support\Header;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\BuildsRelatedCases;
use Tests\Support\TransactionalTestCase;

/**
 * 申請の詳細の組み立ての後の判定と「申請の後の変更」の 1 行(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 2 節・4.2 節、
 * 検査 = 8 節の「組み立ての途中の完了」「申請の後の変更」): 見本の WF-000005(未完了。課長 u0002 が承認待ち。申請書のレコードは参照制限があり、u0002 は
 * 申請の経路だけで見る)。組み立ての途中(最初のレコードの閲覧の判定の後)に申請を完了させ、持ち主の規則を通らずに値を変えると、全体の応答は写しの形、
 * 部分の再読み込みは表の偽のときの値になる(同時実行は作れないので、組み立ての SQL を合図に書き換える)。
 */
final class CaseShowAssemblyTest extends TransactionalTestCase
{
    use BuildsRelatedCases;
    use UsesSeededCases;

    private const CHANGED = 876_543_210;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_case_completed_during_the_full_response_is_returned_in_the_snapshot_form(): void
    {
        $case = $this->caseOf(5);
        $this->completeOn($case, '"revision" > ?');
        $this->actingAsUser('u0002');

        $response = $this->get("/workflow/cases/{$case->id}");
        $props = $this->props($response);

        $this->assertSame([false, null, null], [$props['recordAccess'], $props['content'], $props['panel']]);
        $this->assertNotNull($props['submittedRecord']);
        $this->assertStringNotContainsString((string) self::CHANGED, (string) $response->getContent());
    }

    public function test_a_case_completed_during_a_partial_reload_of_the_content_returns_the_masked_value(): void
    {
        $case = $this->caseOf(5);
        $this->completeOn($case, '"revision" > ?');
        $this->actingAsUser('u0002');

        $props = $this->props($this->partial($case, 'content', []));

        $this->assertSame(['content' => null, 'recordAccess' => false], $props);
    }

    public function test_a_case_completed_during_a_partial_reload_of_the_panel_returns_the_masked_value(): void
    {
        $case = $this->caseOf(5);
        $this->completeOn($case, '"record_comments"');
        $this->actingAsUser('u0002');

        $props = $this->props($this->partial($case, 'panelContent', ['panel' => 'comments']));

        $this->assertSame(['panelContent' => null, 'recordAccess' => false], $props);
    }

    /**
     * 組み立ての途中に完了すると、つながる申請も偽のときの形で組み直す(申請書のレコード R からたどる群が消え、ほかの群は残る)。
     * R を指す別の申請(u0002 が申請者)の群は、真のまま開くと出る。部分の再読み込みの合図は、つながる申請の参照先・参照元の読み取り。
     */
    #[DataProvider('relatedRequests')]
    public function test_a_case_completed_during_the_response_does_not_trace_the_related_cases_from_the_record(
        ?string $only,
        string $signal,
    ): void {
        $case = $this->caseOf(5);
        $referring = $this->caseReferringTo($case);
        $this->actingAsUser('u0002');
        $open = $this->props($this->partial($case, 'related', []))['related'];
        $isTraced = fn (array $group) => in_array($referring, array_column($group['cases'], 'number'), true);
        $traced = array_filter($open, $isTraced);
        $heading = array_values($traced)[0]['heading'] ?? null;
        $this->completeOn($case, $signal);

        $response = $only === null ? $this->get("/workflow/cases/{$case->id}") : $this->partial($case, $only, []);
        $props = $this->props($response);

        $this->assertNotNull($heading);
        $this->assertFalse($props['recordAccess']);
        $this->assertSame(
            array_values(array_filter($open, fn (array $group) => $group['heading'] !== $heading)),
            $props['related'],
        );
    }

    /**
     * @return array<string, array{string|null, string}>
     */
    public static function relatedRequests(): array
    {
        return [
            '全体の応答' => [null, '"revision" > ?'],
            '部分の再読み込み' => ['related', 'coalesce(records.parent_record_id, records.id)'],
        ];
    }

    /**
     * 規則の外から版が書かれたときだけ出る(持ち主の規則を通らずに版を書く)。この申請の履歴の行が指す版(ステップの添付の版)なら出ない。
     */
    public function test_the_change_after_the_submission_is_a_revision_after_the_latest_one_of_the_case(): void
    {
        $case = $this->caseOf(5);
        $this->actingAsUser('u0001');
        $changed = fn () => $this->props($this->partial($case, 'content', []))['content']['updatedAfterSubmission']
            ?? null;
        $before = $changed();
        $this->rewrite($case);
        $revisionId = app(RecordRevisionWriter::class)
            ->write((int) $case->record_id, RecordRevisionAction::Update, null, null);
        $after = $changed();
        WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->where('action', WorkflowAction::SendBack)
            ->update(['record_revision_id' => $revisionId]);

        $this->assertNotNull($revisionId);
        $this->assertSame([false, true, false], [$before, $after, $changed()]);
    }

    private function caseOf(int $number): WorkflowCase
    {
        return WorkflowCase::query()->findOrFail($this->caseNumbered($number));
    }

    /**
     * 渡した SQL が初めて出た直後に、申請を完了させ、持ち主の規則を通らずにレコードの値を変える(1 回だけ)。
     */
    private function completeOn(WorkflowCase $case, string $signal): void
    {
        $done = false;
        DB::listen(function (QueryExecuted $query) use (&$done, $case, $signal): void {
            if (! $done && str_contains(strtolower($query->sql), strtolower($signal))) {
                $done = true;
                WorkflowCase::query()->whereKey($case->id)->update([
                    'state' => WorkflowCaseState::Completed,
                    'completion_reason' => WorkflowCompletionReason::Approved,
                    'completed_at' => now(),
                ]);
                $this->rewrite($case);
            }
        });
    }

    /**
     * 申請書のレコードを他モジュール参照で指すレコードを申請書にした、u0002 の未完了の申請を作り、その申請番号を返す。
     */
    private function caseReferringTo(WorkflowCase $case): string
    {
        [$u0002] = $this->seededUsers('u0002');
        $record = Record::query()->findOrFail($case->record_id);
        $flow = $this->targetFlow('plan_note', null, RecordModuleAction::cases(), [$u0002]);
        $notes = $this->moduleNamed('m_plan_note');
        $this->referenceField($notes, 'plan_ref', RecordModule::query()->findOrFail($record->record_module_id));
        $form = $this->recordIn($notes);
        $this->refer($form, 'plan_ref', $record);
        $referring = $this->appliedCase($flow, $form, $u0002);

        return CaseNumbers::format((int) $referring->refresh()->case_number);
    }

    /**
     * 持ち主の規則を通らずに、申請書のレコードの予算を書き換える(版を作らない)。
     */
    private function rewrite(WorkflowCase $case): void
    {
        $record = Record::query()->findOrFail($case->record_id);
        $budget = RecordField::query()->where('record_module_id', $record->record_module_id)
            ->where('code', 'budget')->sole();
        $values = $record->field_values ?? [];
        $values[$budget->id] = self::CHANGED;
        Record::query()->whereKey($record->id)->toBase()->update(['field_values' => json_encode($values)]);
    }

    /**
     * @param  array<string, string>  $query
     * @return TestResponse<Response>
     */
    private function partial(WorkflowCase $case, string $only, array $query): TestResponse
    {
        $url = "/workflow/cases/{$case->id}".($query === [] ? '' : '?'.http_build_query($query));

        return $this->get($url, [
            Header::PARTIAL_COMPONENT => 'Workflow/CaseShow',
            Header::PARTIAL_ONLY => $only,
        ]);
    }

    /**
     * ページの props から、全部の画面に共有の props を除いたもの。
     *
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        $response->assertOk();
        $page = $response->viewData('page');

        return array_diff_key($page['props'], Inertia::getShared());
    }
}
