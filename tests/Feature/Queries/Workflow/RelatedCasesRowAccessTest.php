<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Records\CreateRecordModule;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use Tests\Support\BuildsRelatedCases;
use Tests\Support\RowAccessRestriction;
use Tests\Support\TransactionalTestCase;

/**
 * つながる申請の出すかの表(docs の 設計/ワークフロー/業務データとの接続/写しとつながる申請.md 6 節)の「T の閲覧 偽」「X の閲覧 偽」の行を、
 * 業務モジュールの read は持たせたまま行の判定(レコードの参照制限)で見えなくして通す。起点 R = 見る案件の申請書のレコード(m_plan)、
 * T = その申請対象(projects)、X = R が参照する issues のレコード。作成者はいつも行の判定を通るので、見える状態は作成者を見る人にして作る。
 */
final class RelatedCasesRowAccessTest extends TransactionalTestCase
{
    use BuildsRelatedCases;

    private User $applicant;

    private WorkflowFlow $plan;

    private WorkflowFlow $issue;

    private Record $project;

    private Record $viewedIssue;

    private WorkflowCase $viewed;

    /** @var array<string, WorkflowCase> */
    private array $cases = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->applicant = $this->seededUsers('u0001')[0];
        $this->buildModules();
        $this->buildRecordsAndCases();
    }

    public function test_every_group_shows_when_the_viewer_passes_the_row_check_of_the_records(): void
    {
        $this->assertSame([
            ['同じprojectsを申請対象にする申請', ['sameProject']],
            ['projectsの「main_issue」を申請対象にする申請', ['mainIssue']],
            ['m_planの「issue」を申請対象にする申請', ['viewedIssue']],
            ['m_planの「issue_form」が指す申請', ['issueForm']],
        ], $this->related());
    }

    /**
     * T が行の判定で見えない(業務モジュールの read はある): T を起点にしないので T からの群は出ず、R からの群は出る。
     * R が参照する T 自身も X として見えないので、X = T の群(「target」)も出ない。
     */
    public function test_a_row_hidden_application_target_is_not_an_origin(): void
    {
        $this->hideFromViewer($this->project);

        $this->assertSame([
            ['m_planの「issue」を申請対象にする申請', ['viewedIssue']],
            ['m_planの「issue_form」が指す申請', ['issueForm']],
        ], $this->related());
    }

    /**
     * X が行の判定で見えない(業務モジュールの read はある): X を申請対象にする案件の群だけが出ず、X を申請書のレコードに持つ群と
     * T からの群は出る。
     */
    public function test_a_row_hidden_record_reached_hides_only_the_groups_matching_it_as_an_application_target(): void
    {
        $this->hideFromViewer($this->viewedIssue);

        $this->assertSame([
            ['同じprojectsを申請対象にする申請', ['sameProject']],
            ['projectsの「main_issue」を申請対象にする申請', ['mainIssue']],
            ['m_planの「issue_form」が指す申請', ['issueForm']],
        ], $this->related());
    }

    private function buildModules(): void
    {
        $projects = $this->recordModule('projects');
        $issues = $this->recordModule('issues');
        RowAccessRestriction::restrict([$projects->id, $issues->id]);
        $this->plan = $this->targetFlow('plan', $projects, CreateRecordModule::EVERYONE_ACTIONS, [$this->applicant]);
        $this->issue = $this->targetFlow('issue', $issues, CreateRecordModule::EVERYONE_ACTIONS, [$this->applicant]);
        $this->referenceField($projects, 'main_issue', $issues);
        $this->referenceField($this->moduleNamed('m_plan'), 'issue', $issues);
        $this->referenceField($this->moduleNamed('m_plan'), 'issue_form', $this->moduleNamed('m_issue'));
    }

    private function buildRecordsAndCases(): void
    {
        $this->project = $this->ownedRecord('projects');
        $mainIssue = $this->ownedRecord('issues');
        $this->viewedIssue = $this->ownedRecord('issues');
        $issueFormRecord = $this->recordIn('m_issue');
        $this->refer($this->project, 'main_issue', $mainIssue);
        $form = $this->planForm($this->project);
        $this->refer($form, 'issue', $this->viewedIssue);
        $this->refer($form, 'issue_form', $issueFormRecord);
        $this->viewed = $this->appliedCase($this->plan, $form, $this->applicant);
        $this->cases = [
            'sameProject' => $this->appliedCase($this->plan, $this->planForm($this->project), $this->applicant, true),
            'mainIssue' => $this->issueCase(['target' => $mainIssue]),
            'viewedIssue' => $this->issueCase(['target' => $this->viewedIssue]),
            'issueForm' => $this->appliedCase($this->issue, $issueFormRecord, $this->applicant),
        ];
    }

    /**
     * 見る人が作成者のレコード(行の判定を通る)。
     */
    private function ownedRecord(string $moduleCode): Record
    {
        $record = $this->recordIn($moduleCode);
        Record::query()->whereKey($record->id)->update(['created_by_user_id' => $this->applicant->id]);

        return $record;
    }

    /**
     * 作成者を外して、見る人が行の判定を通らないレコードにする(参照制限があり、関係者の印の項目は無い)。
     */
    private function hideFromViewer(Record $record): void
    {
        Record::query()->whereKey($record->id)->update(['created_by_user_id' => null]);
    }

    private function planForm(Record $project): Record
    {
        $form = $this->recordIn('m_plan');
        $this->refer($form, 'target', $project);

        return $form;
    }

    /**
     * 見る人が申請した、m_issue の申請書の未完了の案件。
     *
     * @param  array<string, Record>  $references  参照の項目のコード → 指すレコード
     */
    private function issueCase(array $references): WorkflowCase
    {
        $form = $this->recordIn('m_issue');

        foreach ($references as $field => $to) {
            $this->refer($form, $field, $to);
        }

        return $this->appliedCase($this->issue, $form, $this->applicant);
    }

    /**
     * 群の見出しと、案件の名前(このテストで付けた名前)の並び。
     *
     * @return list<array{string, list<string>}>
     */
    private function related(): array
    {
        $byId = array_flip(array_map(fn (WorkflowCase $case) => $case->id, $this->cases));

        return array_map(
            fn (array $group) => [
                $group['heading'],
                array_map(fn (array $row) => $byId[$row['id']] ?? "#{$row['id']}", $group['cases']),
            ],
            $this->relatedGroupsOf($this->applicant, $this->viewed),
        );
    }
}
