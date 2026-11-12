<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Workflow\ApplicationInput;
use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\CreateDraft;
use App\Actions\Workflow\DraftInput;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CaseNumbers;
use Carbon\CarbonImmutable;
use Tests\Support\AppliesToTargets;
use Tests\Support\BuildsApplicationTargetCopies;
use Tests\Support\CopiesCases;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 案件からの複製の画面の GET(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/検査.md の 14 節の 12.3・13.2・13.5):
 * 判定の順の表の各行(403 → 404。403 の人にはどの id も 403)、クエリ copy の読み方と優先順、開いた画面の props(初期値・copy)、
 * ほかの経路では copy が null、代理元が既定の申請者になる筋。
 */
final class CaseCopyPageTest extends TransactionalTestCase
{
    use AppliesToTargets;
    use BuildsApplicationTargetCopies;
    use CopiesCases;
    use RegistersProxies;
    use RunsWorkflowOperations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow($this->operationDay()->toDateString().' 03:00:00 UTC');
    }

    /**
     * 新規の申請の画面に、申請名・写した値・明細(id の無い新しい行、並びのまま)と copy が入る。コメント・添付・申請者・申請基準日は写らない。
     */
    public function test_the_form_opens_with_the_copied_values(): void
    {
        [$u0001] = $this->usersOf('u0001');
        $this->copyFlow([$u0001]);
        $hub = $this->hubRecord($u0001)['hub'];
        $rows = [
            new RecordDetailRowInput(values: ['item' => 'A', 'qty' => 1]),
            new RecordDetailRowInput(values: ['item' => 'B', 'qty' => 2]),
        ];
        $input = new ApplicationInput('元の申請', 'コメント', new RecordInput(
            ['title' => '元の題', 'amount' => 5, 'kind' => 'b', self::TARGET_FIELD => $hub], ['lines' => $rows],
        ));
        $context = $this->contextOf($u0001);
        $caseId = app(ApplyCase::class)->execute($context, self::COPY_FLOW, $input, '2026-10-01')->caseId;
        $source = WorkflowCase::query()->findOrFail($caseId);

        $props = $this->copyPropsAs($u0001, $caseId);

        $this->assertSame(
            ['new', null, null, null], [$props['mode'], $props['recordId'], $props['case'], $props['applicant']],
        );
        $this->assertSame(['元の申請', null], [$props['initial']['name'], $props['initial']['comment']]);
        $values = $props['initial']['values'];
        $this->assertSame(
            ['元の題', 5, 'b', $hub],
            [$values['title'], $values['amount'], $values['kind']['code'], $values[self::TARGET_FIELD]['id']],
        );
        $copiedRows = $props['initial']['details']['lines'];
        $lines = [['values' => ['item' => 'A', 'qty' => 1]], ['values' => ['item' => 'B', 'qty' => 2]]];
        $picked = fn (array $row) => ['values' => array_intersect_key($row['values'], ['item' => 0, 'qty' => 0])];
        $this->assertSame($lines, array_map($picked, $copiedRows));
        $this->assertSame([], array_filter($copiedRows, fn (array $row) => array_key_exists('id', $row)));
        $this->assertSame($this->operationDay()->toDateString(), $props['baseDate']);
        $copy = $props['copy'];
        $this->assertSame(
            [$caseId, CaseNumbers::format((int) $source->case_number), '元の申請', []],
            [$copy['caseId'], $copy['caseNumber'], $copy['name'], $copy['clearedFields']],
        );
        $this->assertIsString($copy['rowAccess']);
        $this->assertNull($props['targetEntry']);
        $this->assertSame($hub, $props['targetValues']['values'][self::TARGET_FIELD]['id'] ?? null);
    }

    /**
     * 判定の順: このフローで申請できる人がいなければ(申請のノードの対象者でない・申請書の業務モジュールの create が無い、自分だけ・
     * 自分と代理元の両方)、無い・一時保存・ほかのフロー・閲覧できない・見られる申請のどれも 403。いれば、前の 4 つが同じ 404 で、
     * 見られる申請は 200。404・403 の段の前に元の申請の版を読まない。
     */
    public function test_403_comes_before_404_and_404_does_not_leak(): void
    {
        [$u0001, $u0002, $u0003, $u0004] = $this->usersOf('u0001', 'u0002', 'u0003', 'u0004');
        $flowId = $this->copyFlow([$u0001, $u0004]);
        $ids = $this->sourceIds($u0001, $u0004, $flowId);

        $this->assertSame([404, 404, 404, 404, 200], $this->statusesAs($u0001, $ids));
        $this->assertSame([403, 403, 403, 403, 403], $this->statusesAs($u0002, $ids));

        $this->addProxy($u0001, $u0003, WorkflowProxyKind::Apply, $flowId);
        $this->allowOnly(self::COPY_FORM, RecordModuleAction::Create, $u0002);
        $this->assertSame([403, 403, 403, 403, 403], $this->statusesAs($u0001, $ids));
        $this->assertSame([403, 403, 403, 403, 403], $this->statusesAs($u0003, $ids));

        $this->allowOnly(self::COPY_FORM, RecordModuleAction::Create, $u0001, $u0004);
        $sql = $this->recordedSql(fn () => $this->statusesAs($u0001, array_slice($ids, 0, 4)));
        $revisionReads = array_filter($sql, fn (string $query) => str_contains($query, 'record_revisions'));
        $this->assertSame([], array_values($revisionReads));
    }

    /**
     * copy の読み方(正の整数を先頭の 0 なしの 10 進で 18 桁まで)とクエリの優先順(record → target → copy。読めない値は無いのと同じ)。
     */
    public function test_the_copy_query_is_read_as_a_positive_id_after_record_and_target(): void
    {
        [$u0001] = $this->usersOf('u0001');
        $this->copyFlow([$u0001]);
        $hub = $this->hubRecord($u0001)['hub'];
        $form = $this->copyFormRecord($u0001, null);
        $source = $this->sourceCase($u0001)->id;
        $this->actingAs($u0001);
        $modeOf = fn (array $query) => $this->modeOf(
            $this->pagePropsOf($this->get(self::APPLY_URL.'?'.http_build_query($query))->assertOk()),
        );

        foreach (['0', '01', '-1', 'abc', '1.5', '1234567890123456789'] as $unreadable) {
            $this->assertSame('new', $modeOf(['copy' => $unreadable]), $unreadable);
        }

        $this->assertSame(404, $this->copyStatusAs($u0001, '999999999999999999'));
        $this->assertSame(404, $this->copyStatusAs($u0001, '1'));
        $this->assertSame('record', $modeOf(['record' => (string) $form, 'copy' => (string) $source]));
        $this->assertSame('target', $modeOf(['target' => (string) $hub, 'copy' => (string) $source]));
        $this->assertSame('copy', $modeOf(['record' => 'abc', 'copy' => (string) $source]));
        $this->assertSame('copy', $modeOf(['target' => 'abc', 'copy' => (string) $source]));
    }

    /**
     * ほかの経路(新規・申請対象から・一時保存・再申請)では copy が null。写して一時保存した下書きを開き直しても null で、一時保存の入力と案件に
     * 元の案件の id が無い。
     */
    public function test_the_other_routes_have_no_copy(): void
    {
        [$u0001] = $this->usersOf('u0001');
        $this->copyFlow([$u0001]);
        $hub = $this->hubRecord($u0001)['hub'];
        $source = $this->sourceCase($u0001);
        $waiting = $this->waitingCase($u0001, null);
        $draftId = $this->draftOf($u0001);
        $copied = $this->postDraft($u0001, $this->sentFrom($this->copyPropsAs($u0001, $source->id)));
        $this->actingAs($u0001);
        $urls = [
            self::APPLY_URL, self::APPLY_URL.'?target='.$hub, "/workflow/drafts/{$draftId}/edit",
            "/workflow/drafts/{$copied->id}/edit", "/workflow/cases/{$waiting->id}/reapply",
        ];

        foreach ($urls as $url) {
            $this->assertNull($this->pagePropsOf($this->get($url)->assertOk())['copy'], $url);
        }

        $keys = array_keys($copied->draft_input ?? []);
        sort($keys);
        $this->assertSame(['base_date', 'comment', 'copied_row_access', 'details', 'name', 'values'], $keys);
        $this->assertSame(['restricted' => false, 'viewers' => []], $this->draftCopied($copied));
        $this->assertNull($copied->record_id);
    }

    /**
     * 版の後に足した項目は既定値を使わず空。字数などの入力の規則に合わない値も空にせず写り、申請の時の検証で 422。申請すると新しい案件ができ、
     * 元の申請の版の数と状態は変わらない。
     */
    public function test_values_are_copied_without_defaults_or_input_rules(): void
    {
        [$u0001] = $this->usersOf('u0001');
        $this->copyFlow([$u0001]);
        $source = $this->sourceCase($u0001, ['title' => '長い題の申請']);
        $form = RecordModule::query()->where('code', self::COPY_FORM)->sole();
        $this->field($form, 'added', RecordFieldType::String, ['default_value' => '既定の値']);
        RecordField::query()->where('record_module_id', $form->id)->where('code', 'title')->update(['max_length' => 3]);
        $revisions = RecordRevision::query()->where('record_id', $source->record_id)->count();

        $props = $this->copyPropsAs($u0001, $source->id);

        $values = $props['initial']['values'];
        $this->assertSame(['長い題の申請', null], [$values['title'], $values['added'] ?? null]);
        $this->post(self::APPLY_URL, $this->sentFrom($props))->assertSessionHasErrors('values.title');
        $applied = $this->postApply($u0001, $this->sentFrom($props, ['values' => ['title' => '題']]));
        $this->assertNotSame($source->record_id, $applied->record_id);
        $unchanged = WorkflowCase::query()->findOrFail($source->id);
        $this->assertSame([$source->state, $source->revision], [$unchanged->state, $unchanged->revision]);
        $this->assertSame($revisions, RecordRevision::query()->where('record_id', $source->record_id)->count());
    }

    /**
     * 自分はこのフローで申請できず代理元だけができるとき、クエリ applicant が無くても代理元が既定の申請者になり、確かめと表示名が代理元で作られる
     * (代理元だけが読める参照先の名前が写る)。
     */
    public function test_the_principal_is_the_default_applicant_and_the_judge(): void
    {
        [$u0002, $principal] = $this->usersOf('u0002', 'u0009');
        $flowId = $this->copyFlow([$principal]);
        $this->addProxy($principal, $u0002, WorkflowProxyKind::Apply, $flowId);
        $ref = $this->hubRecord($principal)['ref'];
        $this->onlyRelated('m_ref_a');
        $source = $this->sourceCase($principal, ['ref' => $ref]);

        $props = $this->copyPropsAs($u0002, $source->id);

        $applicants = [array_column($props['applicants'], 'id'), $props['applicant']];
        $this->assertSame([[$principal->id], $principal->id], $applicants);
        $label = $props['initial']['values']['ref']['label'] ?? null;
        $this->assertSame(['参照先', []], [$label, array_column($props['copy']['clearedFields'], 'name')]);
    }

    /**
     * 無い・一時保存(自分の)・ほかのフロー・閲覧できない・見られる申請の id。
     *
     * @return list<int>
     */
    private function sourceIds(User $own, User $other, int $flowId): array
    {
        $draft = $this->draftOf($own);
        $otherFlow = (int) WorkflowCase::query()->where('user_id', $own->id)->where('workflow_flow_id', '<>', $flowId)
            ->where('state', '<>', WorkflowCaseState::Draft)->orderBy('id')->value('id');
        $this->assertGreaterThan(0, $otherFlow);
        $hidden = $this->sourceCase($other)->id;
        $visible = $this->sourceCase($own)->id;

        return [(int) WorkflowCase::query()->max('id') + 1000, $draft, $otherFlow, $hidden, $visible];
    }

    /**
     * 写しのフローの一時保存(値なし)。
     */
    private function draftOf(User $owner): int
    {
        $input = new DraftInput('下書き', null, [], []);

        return app(CreateDraft::class)->execute($this->contextOf($owner), self::COPY_FLOW, $input);
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function statusesAs(User $user, array $ids): array
    {
        return array_map(fn (int $id) => $this->copyStatusAs($user, $id), $ids);
    }

    /**
     * 開いた画面の経路(copy = 案件からの複製、target = 申請対象から、ほかは mode)。
     *
     * @param  array<string, mixed>  $props
     */
    private function modeOf(array $props): string
    {
        return match (true) {
            $props['copy'] !== null => 'copy',
            $props['targetEntry'] !== null => 'target',
            default => $props['mode'],
        };
    }
}
