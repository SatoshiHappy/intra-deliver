<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Records\AddRecordField;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Workflow\WorkflowCase;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AppliesFromRecords;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 申請の画面の参照項目の候補 workflow.record-candidates(docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.1 節、案件の運用/案件の操作/README.md の
 * 4.5 節、クエリ record = 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 1 節): 判定の順(case → flow → 参照先 → 判定を求める者 →
 * read の 403)の各枝を、フォームの別(flow・case・record)ごとに自分と代理元の read で通す。応答は業務データの入れ物の画面の候補と同じ。
 * フォーム = 初期データの「プロジェクト計画の申請」(参照の項目 project → 業務モジュール project)。代理元 A = u0009、申請の代理先 B = u0002。
 */
final class RecordCandidateControllerTest extends TransactionalTestCase
{
    use AppliesFromRecords;
    use RegistersProxies;

    /** 参照先の業務モジュール */
    private const TARGET = 'project';

    private int $flowId;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow($this->operationDay()->toDateString().' 03:00:00 UTC');
        $this->flowId = $this->createFlow(approvals: [[$this->userCondition('u0003')]]);
    }

    /**
     * 同じ入力(q・exclude)で業務データの入れ物の画面の候補と同じ応答。
     */
    public function test_the_response_is_the_same_as_the_record_candidates_of_the_records_screens(): void
    {
        $this->actingAs($this->seededUser('u0001'));
        $exclude = (int) Record::query()->whereRelation('recordModule', 'code', self::TARGET)->max('id');

        foreach ([[], ['q' => 'プロジェクト'], ['exclude' => (string) $exclude]] as $query) {
            $records = '/records/data/'.self::TARGET.'/candidates?'.http_build_query($query);
            $expected = $this->getJson($records)->assertOk();
            $actual = $this->getJson($this->url(self::TARGET, ['flow' => self::FLOW_CODE, ...$query]))->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private');
            $this->assertSame($expected->json(), $actual->json(), http_build_query($query));
        }

        $this->getJson($this->url(self::TARGET, ['flow' => self::FLOW_CODE, 'q' => str_repeat('a', 101)]))
            ->assertUnprocessable()->assertJsonValidationErrors(['q' => '検索する語は 100 文字以内にしてください']);
    }

    /**
     * case があれば、無い・閲覧できない案件は 404(flow は読まない)。case が無く flow のフローが無ければ 404。
     */
    public function test_a_missing_case_or_a_missing_flow_is_not_found(): void
    {
        $case = $this->sentBackOf($this->seededUser('u0009'));
        $this->actingAs($this->seededUser('u0011'));
        $queries = [
            ['case' => '999999999', 'flow' => self::FLOW_CODE], ['case' => 'abc', 'flow' => self::FLOW_CODE],
            ['case' => (string) $case->id, 'flow' => self::FLOW_CODE], [], ['flow' => 'wf_missing'],
        ];

        foreach ($queries as $query) {
            $this->getJson($this->url(self::TARGET, $query))->assertNotFound();
        }

        $this->getJson($this->url(self::TARGET, ['flow' => self::FLOW_CODE]))->assertOk();
    }

    /**
     * フォームの参照の項目の参照先でない業務モジュールは、read を持っていても 404。明細の行の参照の項目の参照先は引ける。
     */
    public function test_only_the_reference_targets_of_the_form_are_candidates(): void
    {
        $this->actingAs($this->seededUser('u0001'));
        $query = ['flow' => self::FLOW_CODE];
        $this->getJson('/records/data/project_issue/candidates')->assertOk();

        $this->getJson($this->url('project_issue', $query))->assertNotFound();
        app(AddRecordField::class)->execute(self::PROJECT.'_milestones', [
            'type' => 'record', 'code' => 'related_issue', 'name' => '関連する課題', 'referenced_module' => 'project_issue',
        ]);
        $this->getJson($this->url('project_issue', $query))->assertOk();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function forms(): array
    {
        return ['new (flow)' => ['flow'], 'reapply (case)' => ['case'], 'record' => ['record']];
    }

    /**
     * 代理元としてフォームを開けるなら代理元の read で判定し(自分の read は見ない)、開けないなら自分で判定する。
     */
    #[DataProvider('forms')]
    public function test_the_requester_is_the_principal_only_when_the_principal_opens_the_form(string $form): void
    {
        [$a, $b] = [$this->seededUser('u0009'), $this->seededUser('u0002')];
        $this->addProxy($a, $b, WorkflowProxyKind::Apply, $this->flowId);
        $query = $this->formQueryOf($form, $a);
        $this->actingAs($b);
        $statuses = [];

        foreach ([true, false] as $opens) {
            $this->setOpens($form, $a, $b, $opens);

            foreach ([[$a], [$b]] as $readers) {
                $this->allowOnly(self::TARGET, RecordModuleAction::Read, ...$readers);
                $statuses[] = $this->getJson($this->url(self::TARGET, $query))->status();
            }
        }

        $this->assertSame([200, 403, 403, 200], $statuses);
    }

    /**
     * applicant が解釈できない・代理元でない値なら自分で判定する。判定を求める者が read を持たなければ 403。ゲストも同じ(代理元を持たない)。
     */
    public function test_an_applicant_who_is_not_a_principal_leaves_the_requester_as_the_user(): void
    {
        [$a, $b] = [$this->seededUser('u0009'), $this->seededUser('u0002')];
        $this->allowOnly(self::TARGET, RecordModuleAction::Read, $a);
        $this->actingAs($b);

        $urlAs = fn (string $applicant) => $this
            ->url(self::TARGET, ['flow' => self::FLOW_CODE, 'applicant' => $applicant]);

        foreach (['abc', '0', (string) $a->id, (string) $b->id] as $applicant) {
            $this->getJson($urlAs($applicant))->assertForbidden();
        }

        $this->allowOnly(self::TARGET, RecordModuleAction::Read, $b);
        $this->getJson($urlAs((string) $a->id))->assertOk();
        auth()->logout();
        $this->getJson($this->url(self::TARGET, ['flow' => self::FLOW_CODE]))->assertForbidden();
    }

    /**
     * フォームの別ごとのクエリ(代理元を選ぶ)。再申請は代理元の再申請待ちの案件、既存のデータからは代理元が作成者のレコード。
     *
     * @return array<string, string>
     */
    private function formQueryOf(string $form, User $principal): array
    {
        $applicant = ['applicant' => (string) $principal->id];

        return match ($form) {
            'case' => ['case' => (string) $this->sentBackOf($principal)->id, ...$applicant],
            'record' => [
                'flow' => self::FLOW_CODE, 'record' => (string) $this->projectRecord($principal)->id, ...$applicant,
            ],
            default => ['flow' => self::FLOW_CODE, ...$applicant],
        };
    }

    /**
     * 代理元としてフォームを開ける・開けないを作る(新規 = 代理元が申請できるか、再申請 = 代理元の update、既存のデータから = 代理元の update)。
     * 代理先の権限は開けるかに効かないので、どちらでも代理先にも許す。
     */
    private function setOpens(string $form, User $principal, User $proxy, bool $opens): void
    {
        $action = $form === 'flow' ? RecordModuleAction::Create : RecordModuleAction::Update;
        $this->allowOnly(self::PROJECT, $action, ...($opens ? [$principal, $proxy] : [$proxy]));
    }

    private function sentBackOf(User $applicant): WorkflowCase
    {
        $case = $this->applyAs($applicant);
        $this->sendBack($this->seededUser('u0003'), $case, 'apply');

        return $case->refresh();
    }

    /**
     * @param  array<string, string>  $query
     */
    private function url(string $module, array $query): string
    {
        return "/workflow/record-candidates/{$module}".($query === [] ? '' : '?'.http_build_query($query));
    }
}
