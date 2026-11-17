<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Records\RecordRevisionWriter;
use App\Enums\Records\RecordRevisionAction;
use App\Enums\Workflow\WorkflowAction;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TransactionalTestCase;

/**
 * 申請の詳細の「申請の後の変更」の 1 行(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 2 節、検査 = 8 節の「申請の後の変更」):
 * 見本の WF-000005(未完了)。組み立ての途中のロールの取り消しは CaseShowReuseTest。
 */
final class CaseShowAssemblyTest extends TransactionalTestCase
{
    use UsesSeededCases;

    private const CHANGED = 876_543_210;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
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
