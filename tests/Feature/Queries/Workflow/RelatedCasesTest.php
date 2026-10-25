<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsRelatedCases;
use Tests\Support\TransactionalTestCase;

/**
 * つながる申請(docs の 設計/ワークフロー/業務データとの接続/写しとつながる申請.md 6 節の出すかの表・群・群の見出し、9 節の「申請どうしのつながり」、
 * 設計/ワークフロー/申請の周辺/申請の内容の閲覧.md の 2・3 節)。起点 R = 見る案件の申請書のレコード(m_plan)、T = その申請対象(projects)。
 * 業務データ: projects(plan の申請対象の参照先。main_issue → issues、plan_form → m_plan)・issues(issue の申請対象の参照先。
 * project → projects、plan → m_plan)、申請書: m_plan(target → projects、issue → issues、issue_form → m_issue)・
 * m_issue(target → issues、project_ref → projects、plan_ref → m_plan)。認可の条件(案件の閲覧・起点とたどった先の閲覧・recordAccess)は
 * 業務モジュールの read を外して偽にする。
 * 「起きない」行の止め方のテスト = WorkflowCaseTest の同じレコードの 2 つ目の未完了の案件・RecordApplicationRevisionsTest の完了の後の申請
 * (同じ申請書のレコードに一時保存でない案件は 1 つまで)と、初期データの止め方(申請対象の参照先は申請書の業務モジュールでない)。
 */
final class RelatedCasesTest extends TransactionalTestCase
{
    use BuildsRelatedCases;

    private User $applicant;

    private User $stranger;

    private WorkflowFlow $plan;

    private WorkflowFlow $issue;

    private WorkflowCase $strangerCase;

    /** @var array<string, Record> */
    private array $records = [];

    /** @var array<string, WorkflowCase> */
    private array $cases = [];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->applicant, $this->stranger] = $this->seededUsers('u0001', 'u0004');
        $this->buildModules();
        $this->buildRecordsAndCases();
    }

    /**
     * 全部を閲覧できる人: 群の並び(たどり方 → 起点 → 当てる側 → 項目)と 5 つの形の見出し。同じ案件は先の群だけに出て、閲覧できない案件・一時保存・
     * この案件自身は出ず、群の中は申請日時の新しい順。どの群も先の群に置かれて空になった群は出ない。
     */
    public function test_the_groups_follow_the_order_and_a_case_appears_only_in_the_first_group(): void
    {
        $this->assertSame([
            ['同じprojectsを申請対象にする申請', ['sameNewer', 'sameOlder']],
            ['projectsの「main_issue」を申請対象にする申請', ['mainIssue']],
            ['projectsの「plan_form」が指す申請', ['planForm']],
            ['m_planの「issue」を申請対象にする申請', ['issue']],
            ['m_planの「issue_form」が指す申請', ['issueForm']],
            ['「project_ref」でprojectsを指すm_issue', ['projectRef']],
            ['「plan」でm_planを指すissuesを申請対象にする申請', ['planTarget']],
            ['「plan_ref」でm_planを指すm_issue', ['planRef']],
        ], $this->related());
    }

    /**
     * recordAccess が偽(申請書の業務モジュールの read が無い): 申請書のレコードからたどる群は出ず、申請対象からたどる群は出る
     * (R からの群で出ていた issue の案件は、T の参照元の群に出る)。
     */
    public function test_without_the_record_access_only_the_groups_from_the_application_target_are_shown(): void
    {
        $this->denyRead('m_plan');

        $this->assertSame([
            ['同じprojectsを申請対象にする申請', ['sameNewer', 'sameOlder']],
            ['projectsの「main_issue」を申請対象にする申請', ['mainIssue']],
            ['projectsの「plan_form」が指す申請', ['planForm']],
            ['「project」でprojectsを指すissuesを申請対象にする申請', ['issue']],
            ['「project_ref」でprojectsを指すm_issue', ['projectRef']],
        ], $this->related());
    }

    /**
     * 申請対象を閲覧できない: 申請対象を起点にせず、申請書のレコードからたどる群だけが出る。申請対象を当て先にする群(「target」)も、
     * 当て先(projects)を閲覧できないので出ない。
     */
    public function test_without_viewing_the_application_target_only_the_groups_from_the_application_record_show(): void
    {
        $this->denyRead('projects');

        $this->assertSame([
            ['m_planの「issue」を申請対象にする申請', ['issue']],
            ['m_planの「issue_form」が指す申請', ['issueForm']],
            ['「plan」でm_planを指すissuesを申請対象にする申請', ['planTarget']],
            ['「plan_ref」でm_planを指すm_issue', ['planRef']],
        ], $this->related());
    }

    /**
     * たどった先を閲覧できない: 当てる側 = 申請対象の群は出ず(issues)、当てる側 = 申請書の群は閲覧に依らず出る(m_issue)。
     */
    public function test_an_unviewable_record_reached_hides_only_the_groups_matching_it_as_an_application_target(): void
    {
        $this->denyRead('issues');
        $this->denyRead('m_issue');

        $this->assertSame([
            ['同じprojectsを申請対象にする申請', ['sameNewer', 'sameOlder']],
            ['projectsの「plan_form」が指す申請', ['planForm']],
            ['m_planの「issue_form」が指す申請', ['issueForm']],
            ['「project_ref」でprojectsを指すm_issue', ['projectRef']],
            ['「plan_ref」でm_planを指すm_issue', ['planRef']],
        ], $this->related());
    }

    /**
     * 案件を閲覧できない人には、どの群にも名前も件数も出ない(閲覧できる案件だけが残る)。
     */
    public function test_cases_the_viewer_cannot_view_are_not_counted_in_any_group(): void
    {
        $own = $this->appliedCase($this->plan, $this->formRecord('m_plan', ['target' => 'project']), $this->stranger);
        $names = ['stranger' => $this->strangerCase];

        $this->assertSame([['同じprojectsを申請対象にする申請', ['stranger']]], $this->related($this->stranger, $names, $own));
    }

    /**
     * 申請対象が無い(コンテンツに申請対象の項目が無い・値が空)ときは、申請書のレコードからたどる群だけ。
     */
    public function test_without_an_application_target_only_the_groups_from_the_application_record_are_shown(): void
    {
        $plainFlow = $this->targetFlow('note', null, RecordModuleAction::cases(), [$this->applicant]);
        $this->referenceField($this->moduleNamed('m_note'), 'issue', $this->moduleNamed('issues'));
        $note = $this->appliedCase($plainFlow, $this->formRecord('m_note', ['issue' => 'issue']), $this->applicant);
        $empty = $this->appliedCase($this->plan, $this->formRecord('m_plan', ['issue' => 'issue']), $this->applicant);

        foreach (['m_note' => $note, 'm_plan' => $empty] as $moduleCode => $case) {
            $this->assertSame([["{$moduleCode}の「issue」を申請対象にする申請", ['issue']]], $this->related(of: $case));
        }
    }

    /**
     * 同じ業務レコードを申請対象にして 2 回申請すると、申請対象を閲覧できる人には互いが出て、閲覧できない人には出ない。recordAccess が偽でも、
     * 申請対象を閲覧できれば申請対象からたどる群に出る。
     */
    public function test_two_applications_for_the_same_record_show_each_other_to_who_can_view_the_record(): void
    {
        $project = $this->recordIn('projects');
        $first = $this->appliedCase($this->plan, $this->formRecord('m_plan', [], $project), $this->applicant, true);
        $second = $this->appliedCase($this->plan, $this->formRecord('m_plan', [], $project), $this->applicant);
        $pair = ['first' => $first, 'second' => $second];

        $this->assertSame([['同じprojectsを申請対象にする申請', ['first']]], $this->related(of: $second, names: $pair));
        $this->assertSame([['同じprojectsを申請対象にする申請', ['second']]], $this->related(of: $first, names: $pair));
        $this->denyRead('m_plan');
        $this->assertSame([['同じprojectsを申請対象にする申請', ['first']]], $this->related(of: $second, names: $pair));
        $this->denyRead('projects');
        $this->assertSame([], $this->related(of: $second, names: $pair));
        $this->assertSame([], $this->related(of: $first, names: $pair));
    }

    /**
     * 群の案件の数とたどった先のレコードの数で、問い合わせの数がどう変わるか(6 節の規模の前提: 群の件数には比例せず、たどった先のレコードごとに
     * 参照元を項目で引く読み取りと閲覧の判定の分だけ増える)。
     */
    public function test_the_queries_do_not_grow_with_the_cases_and_grow_by_a_constant_per_record_reached(): void
    {
        $base = $this->queryCount();
        $this->appliedCase($this->plan, $this->formRecord('m_plan', ['target' => 'project']), $this->applicant);
        $this->appliedCase($this->issue, $this->formRecord('m_issue', ['target' => 'issue']), $this->applicant);
        $moreCases = $this->queryCount();
        $this->refer($this->recordIn('issues'), 'plan', $this->records['form']);
        $oneMoreRecord = $this->queryCount();
        $this->refer($this->recordIn('issues'), 'plan', $this->records['form']);
        $twoMoreRecords = $this->queryCount();

        $this->assertSame($base, $moreCases);
        $this->assertGreaterThan($base, $oneMoreRecord);
        $this->assertSame($oneMoreRecord - $base, $twoMoreRecords - $oneMoreRecord);
    }

    private function buildModules(): void
    {
        $everything = RecordModuleAction::cases();
        $projects = $this->recordModule('projects', $everything);
        $issues = $this->recordModule('issues', $everything);
        $this->plan = $this->targetFlow('plan', $projects, $everything, [$this->applicant, $this->stranger]);
        $this->issue = $this->targetFlow('issue', $issues, $everything, [$this->applicant, $this->stranger]);
        [$plan, $issue] = [$this->moduleNamed('m_plan'), $this->moduleNamed('m_issue')];
        $this->referenceField($projects, 'main_issue', $issues);
        $this->referenceField($projects, 'plan_form', $plan);
        $this->referenceField($issues, 'project', $projects);
        $this->referenceField($issues, 'plan', $plan);
        $this->referenceField($plan, 'issue', $issues);
        $this->referenceField($plan, 'issue_form', $issue);
        $this->referenceField($issue, 'project_ref', $projects);
        $this->referenceField($issue, 'plan_ref', $plan);
    }

    /**
     * 見る案件(R = form、T = project)と、群ごとに当たる案件。
     */
    private function buildRecordsAndCases(): void
    {
        $r = &$this->records;
        $r['project'] = $this->recordIn('projects');
        $r['issue'] = $this->recordIn('issues');
        $r['mainIssue'] = $this->recordIn('issues');
        $r['planIssue'] = $this->recordIn('issues');
        $r['planForm'] = $this->recordIn('m_plan');
        $r['issueForm'] = $this->recordIn('m_issue');
        $this->refer($r['issue'], 'project', $r['project']);
        $this->refer($r['project'], 'main_issue', $r['mainIssue']);
        $this->refer($r['project'], 'plan_form', $r['planForm']);
        $r['form'] = $this->formRecord('m_plan', [
            'target' => 'project', 'issue' => 'issue', 'issue_form' => 'issueForm',
        ]);
        $this->refer($r['planIssue'], 'plan', $r['form']);
        $this->cases['viewed'] = $this->appliedCase($this->plan, $r['form'], $this->applicant);
        $this->planCase('sameOlder', ['target' => 'project'], '2026-10-01 09:00:00');
        $this->planCase('sameNewer', ['target' => 'project'], '2026-10-02 09:00:00');
        $strangerForm = $this->formRecord('m_plan', ['target' => 'project']);
        $this->strangerCase = $this->appliedCase($this->plan, $strangerForm, $this->stranger);
        WorkflowCase::factory()->draft()
            ->create(['workflow_flow_id' => $this->plan->id, 'user_id' => $this->applicant->id]);
        $this->cases['planForm'] = $this->appliedCase($this->plan, $r['planForm'], $this->applicant);
        $this->cases['issueForm'] = $this->appliedCase($this->issue, $r['issueForm'], $this->applicant);
        $this->issueCase('mainIssue', ['target' => 'mainIssue']);
        $this->issueCase('issue', ['target' => 'issue']);
        $this->issueCase('projectRef', ['project_ref' => 'project']);
        $this->issueCase('planTarget', ['target' => 'planIssue']);
        $this->issueCase('planRef', ['plan_ref' => 'form']);
    }

    /**
     * 見る人が申請した、m_plan の申請書の完了した案件。
     *
     * @param  array<string, string>  $references  参照の項目のコード → 指すレコードの名前
     */
    private function planCase(string $name, array $references, string $appliedAt): void
    {
        $form = $this->formRecord('m_plan', $references);
        $this->cases[$name] = $this->appliedCase($this->plan, $form, $this->applicant, true, $appliedAt);
    }

    /**
     * 見る人が申請した、m_issue の申請書の未完了の案件。
     *
     * @param  array<string, string>  $references  参照の項目のコード → 指すレコードの名前
     */
    private function issueCase(string $name, array $references): void
    {
        $form = $this->formRecord('m_issue', $references);
        $this->cases[$name] = $this->appliedCase($this->issue, $form, $this->applicant);
    }

    /**
     * 申請書のレコード(参照の項目のコード → 指すレコードの名前)。$applicationTarget を渡せば申請対象の項目 target の値。
     *
     * @param  array<string, string>  $references
     */
    private function formRecord(string $moduleCode, array $references, ?Record $applicationTarget = null): Record
    {
        $record = $this->recordIn($moduleCode);

        foreach ($references as $field => $name) {
            $this->refer($record, $field, $this->records[$name]);
        }

        if ($applicationTarget !== null) {
            $this->refer($record, 'target', $applicationTarget);
        }

        return $record;
    }

    /**
     * 群の見出しと、案件の名前(このテストで付けた名前)の並び。
     *
     * @param  array<string, WorkflowCase>  $names  名前 → 案件(省けばこのテストの案件)
     * @return list<array{string, list<string>}>
     */
    private function related(?User $viewer = null, array $names = [], ?WorkflowCase $of = null): array
    {
        $names = $names === [] ? $this->cases : $names;
        $byId = array_flip(array_map(fn (WorkflowCase $case) => $case->id, $names));

        $name = fn (array $row) => $byId[$row['id']] ?? "#{$row['id']}";

        return array_map(
            fn (array $group) => [$group['heading'], array_map($name, $group['cases'])],
            $this->relatedGroupsOf($viewer ?? $this->applicant, $of ?? $this->cases['viewed']),
        );
    }

    private function queryCount(): int
    {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $this->relatedGroupsOf($this->applicant, $this->cases['viewed']);

        return $queries;
    }
}
