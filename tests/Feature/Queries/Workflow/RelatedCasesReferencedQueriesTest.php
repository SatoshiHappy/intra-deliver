<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CaseDetailQuery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsRelatedCases;
use Tests\Support\TransactionalTestCase;

/**
 * つながる申請の参照先は、起点によらず参照先を項目で引く読み取りで引く(docs の 設計/ワークフロー/業務データとの接続/写しとつながる申請.md
 * 6 節の「引き方」)。申請対象の起点の参照の項目と明細の行を増やしても、たどった先が同じなら問い合わせの数は同じ。たどった先は申請書の
 * レコード(m_visit)で、その業務モジュールはどのコンテンツの申請対象の参照先でもないので、群ごとのたどった先の閲覧の判定は出ない。
 * 申請対象(projects)は、はじめから明細の項目(contacts)と行を 1 つ持つ(明細の項目の有る無しで入口の読みが 1 つ変わるため)。
 */
final class RelatedCasesReferencedQueriesTest extends TransactionalTestCase
{
    use BuildsRelatedCases;

    private User $applicant;

    private RecordModule $projects;

    private RecordModule $customers;

    private Record $project;

    private Record $customer;

    private WorkflowCase $viewed;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->applicant] = $this->seededUsers('u0001');
        $everything = RecordModuleAction::cases();
        $this->projects = $this->recordModule('projects', $everything);
        $plan = $this->targetFlow('plan', $this->projects, $everything, [$this->applicant]);
        $visit = $this->targetFlow('visit', null, $everything, [$this->applicant]);
        $this->customers = $this->moduleNamed('m_visit');
        $this->referenceField($this->projects, 'customer', $this->customers);
        $this->project = $this->recordIn($this->projects);
        $this->customer = $this->recordIn($this->customers);
        $this->appliedCase($visit, $this->customer, $this->applicant);
        $this->refer($this->project, 'customer', $this->customer);
        $contacts = $this->childOf($this->detailsField($this->projects, 'contacts'));
        $this->referenceField($contacts, 'contact', $this->customers);
        $this->refer($this->contactRow(), 'contact', $this->customer);
        $form = $this->recordIn('m_plan');
        $this->refer($form, 'target', $this->project);
        $this->viewed = $this->appliedCase($plan, $form, $this->applicant);
    }

    public function test_more_reference_fields_and_detail_rows_to_the_same_record_do_not_add_queries(): void
    {
        $base = $this->queryCount();
        $baseHeadings = $this->headings();
        $this->referenceField($this->projects, 'sub_customer', $this->customers);
        $this->referenceField($this->moduleNamed('contacts_rows'), 'sub_contact', $this->customers);
        $this->refer($this->project, 'sub_customer', $this->customer);

        for ($i = 0; $i < 3; $i++) {
            $row = $this->contactRow();
            $this->refer($row, 'contact', $this->customer);
            $this->refer($row, 'sub_contact', $this->customer);
        }

        $this->assertSame(['projectsの「customer」が指す申請'], $baseHeadings);
        $this->assertSame($baseHeadings, $this->headings());
        $this->assertSame($base, $this->queryCount());
    }

    /**
     * 案件は見られるが申請対象を閲覧できない人の詳細の要求では、申請対象を起点にした参照先・参照元の読み取りの SQL が出ない。たどった先 X
     * (申請対象の参照先の業務モジュール issues のレコード)を閲覧できない人では、X で当てる読み取り(参照元を項目で引く読み取り)の SQL が
     * 出ない(写しとつながる申請.md 9 節。閲覧できるときに出る = 記録の仕方の確かめ)。読み取りは、関連レコードを親の無いレコードに直す読みで見分ける。
     */
    public function test_the_records_not_viewable_are_not_read_from(): void
    {
        $issues = $this->recordModule('issues', RecordModuleAction::cases());
        $this->targetFlow('issue', $issues, RecordModuleAction::cases(), [$this->applicant]);
        $this->referenceField($this->projects, 'issue', $issues);
        $issue = $this->recordIn($issues);
        $this->refer($this->project, 'issue', $issue);
        $viewable = [$this->relationReadsOf($this->project->id), $this->relationReadsOf($issue->id)];
        $this->denyRead('issues');
        $issueHidden = [$this->relationReadsOf($this->project->id), $this->relationReadsOf($issue->id)];
        $this->denyRead('projects');
        $projectHidden = $this->relationReadsOf($this->project->id);

        $this->assertNotContains([], $viewable);
        $this->assertNotSame([], $issueHidden[0]);
        $this->assertSame([], $issueHidden[1]);
        $this->assertSame([], $projectHidden);
    }

    /**
     * 案件の詳細の要求の間に出た、そのレコードを起点にした参照先・参照元の読み取りの SQL(最初の値がそのレコードの id)。
     *
     * @return list<string>
     */
    private function relationReadsOf(int $recordId): array
    {
        $log = [];
        DB::listen(function (QueryExecuted $query) use (&$log) {
            $log[] = $query;
        });
        app(CaseDetailQuery::class)->show($this->contextFor($this->applicant->id), $this->viewed->id);
        $queries = $log;
        $log = [];

        $reads = array_filter($queries, fn (QueryExecuted $query) => ($query->bindings[0] ?? null) === $recordId
            && str_contains(strtolower($query->sql), 'coalesce(records.parent_record_id, records.id)'));

        return array_values(array_map(fn (QueryExecuted $query) => $query->sql, $reads));
    }

    private function contactRow(): Record
    {
        return Record::factory()->create([
            'record_module_id' => $this->moduleNamed('contacts_rows')->id,
            'parent_record_module_id' => $this->projects->id,
            'parent_record_id' => $this->project->id,
        ]);
    }

    /**
     * @return list<string>
     */
    private function headings(): array
    {
        return array_column($this->relatedGroupsOf($this->applicant, $this->viewed), 'heading');
    }

    /**
     * 1 回の要求として数える(前の呼び出しの判定を求める者の事実を使い回さない)。
     */
    private function queryCount(): int
    {
        $this->resetRequestScope();
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $this->relatedGroupsOf($this->applicant, $this->viewed);
            $count = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        return $count;
    }
}
