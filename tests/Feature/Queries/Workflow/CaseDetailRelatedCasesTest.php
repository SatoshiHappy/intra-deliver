<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CaseDetailQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsRelatedCases;
use Tests\Support\TransactionalTestCase;

/**
 * 案件の詳細の related(docs の 設計/ワークフロー/画面/申請と案件.md 5 節、群と見出しの形は RelatedCasesTest)と、主体の判定をしない口を閲覧の判定の
 * 後にしか呼ばないこと(設計/ワークフロー/業務データとの接続/写しとつながる申請.md 9 節): 申請対象(projects)を持つ申請書(m_plan)の案件と、
 * 参照元(notes)。
 */
final class CaseDetailRelatedCasesTest extends TransactionalTestCase
{
    use BuildsRelatedCases;

    private User $applicant;

    private User $stranger;

    private WorkflowCase $viewed;

    private WorkflowCase $sameApplicationTarget;

    private WorkflowCase $noted;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->applicant, $this->stranger] = $this->seededUsers('u0001', 'u0004');
        $everything = RecordModuleAction::cases();
        $projects = $this->recordModule('projects', $everything);
        $plan = $this->targetFlow('plan', $projects, $everything, [$this->applicant]);
        $note = $this->targetFlow('note', null, $everything, [$this->applicant]);
        $this->referenceField($this->moduleNamed('m_note'), 'plan', $this->moduleNamed('m_plan'));
        $project = $this->recordIn($projects);
        [$form, $other, $noteForm] = [$this->recordIn('m_plan'), $this->recordIn('m_plan'), $this->recordIn('m_note')];
        $this->refer($form, 'target', $project);
        $this->refer($other, 'target', $project);
        $this->refer($noteForm, 'plan', $form);
        $this->viewed = $this->appliedCase($plan, $form, $this->applicant);
        $this->sameApplicationTarget = $this->appliedCase($plan, $other, $this->applicant, true);
        $this->noted = $this->appliedCase($note, $noteForm, $this->applicant);
    }

    /**
     * 詳細の related = 見出しと、一覧と同じ形の行(can を含む)の群の並び。
     */
    public function test_the_detail_carries_the_groups_with_the_rows_of_the_case_list(): void
    {
        $related = $this->show($this->applicant)['related'];

        $this->assertSame(
            [
                ['同じprojectsを申請対象にする申請', [$this->sameApplicationTarget->id]],
                ['「plan」でm_planを指すm_note', [$this->noted->id]],
            ],
            array_map(fn (array $group) => [$group['heading'], array_column($group['cases'], 'id')], $related),
        );
        $cans = ['pullBack', 'reapply', 'withdraw', 'editDraft', 'deleteDraft', 'bulkApprove', 'confirm'];
        $this->assertSame($cans, array_keys($related[0]['cases'][0]['can']));
    }

    /**
     * 閲覧できない案件の詳細の要求では、申請対象と参照元を持つ案件でも、業務データの入れ物の表の SQL(表示・値・参照元の口)が出ない
     * (閲覧できる人の要求では参照元を読む = 記録の仕方の確かめ)。
     */
    public function test_the_records_tables_are_not_read_for_a_case_not_visible(): void
    {
        $hiding = fn () => $this->assertThrows(fn () => $this->show($this->stranger), ModelNotFoundException::class);
        $hidden = $this->recordQueries($hiding);
        $visible = $this->recordQueries(fn () => $this->show($this->applicant));
        $references = array_filter($visible, fn (string $statement) => str_contains($statement, '"record_references"'));

        $this->assertSame([], $hidden);
        $this->assertNotSame([], $references);
    }

    /**
     * @return array<string, mixed>
     */
    private function show(User $viewer): array
    {
        return app(CaseDetailQuery::class)->show($this->contextFor($viewer->id), $this->viewed->id);
    }

    /**
     * 処理の間に出た、業務データの入れ物の表(records・record_*)を読み書きする SQL。
     *
     * @return list<string>
     */
    private function recordQueries(callable $action): array
    {
        $log = [];
        DB::listen(function (QueryExecuted $query) use (&$log) {
            $log[] = $query->sql;
        });
        $action();
        $sql = $log;
        $log = [];

        $pattern = '/(from|join|update|into)\s+"record(s|_[a-z_]+)"/i';

        return array_values(array_filter($sql, fn (string $statement) => preg_match($pattern, $statement) === 1));
    }
}
