<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Models\Records\Record;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsApplicationTargetCopies;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * そのものを申請する画面の写しの値と、読めない参照先の値の申請(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の
 * 10.1 節・10.2 節・11 節): 申請対象が入っていて申請者が閲覧できれば写しの値を渡し、閲覧できない・空なら渡さない。読めない参照先の値は、
 * 登録の経路(新規・申請対象から)の申請で 422、そのものを申請する経路では値を変えなければ通る。
 */
final class RecordApplicationTargetCopyTest extends TransactionalTestCase
{
    use BuildsApplicationTargetCopies;
    use RegistersProxies;
    use RunsWorkflowOperations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow($this->operationDay()->toDateString().' 03:00:00 UTC');
    }

    /**
     * 閲覧できる申請対象 = 写しの値(開いた業務レコードは無い)。閲覧できない申請対象 = 写さず、申請対象の欄は表示名の無い値。空 = 写さない。
     */
    public function test_the_record_form_copies_the_target_only_when_the_applicant_can_view_it(): void
    {
        [$u0001, $u0003] = $this->usersOf('u0001', 'u0003');
        $this->copyFlow([$u0001]);
        $ids = $this->hubRecord($u0001);
        $hidden = $this->hubRecord($u0003)['hub'];
        [$viewable, $unviewable, $empty] = array_map(
            fn (?int $hub) => $this->copyFormRecord($u0001, $hub), [$ids['hub'], $hidden, null],
        );
        $this->onlyRelated(self::HUB);
        $this->actingAs($u0001);

        $props = $this->propsOf($viewable);
        $this->assertSame(
            ['record', self::TARGET_FIELD, null],
            [$props['mode'], $props['targetField'], $props['targetEntry']],
        );
        $this->assertSame($this->expectedCopy($ids, refLabel: '参照先'), self::sorted((array) $props['targetValues']));

        $props = $this->propsOf($unviewable);
        $target = $props['initial']['values'][self::TARGET_FIELD];
        $this->assertSame([null, $hidden, null], [$props['targetValues'], $target['id'], $target['label']]);
        $this->assertNull($this->propsOf($empty)['targetValues']);
    }

    /**
     * 読めない参照先(写しで入る値)を、新規・申請対象からの申請(登録の口)で送ると 422 で、何も作らない。そのものを申請するときに
     * 値を変えずに送ると確かめ直されず申請できる。
     */
    public function test_an_unreadable_reference_is_refused_on_create_and_kept_on_update(): void
    {
        [$u0001, $u0003] = $this->usersOf('u0001', 'u0003');
        $this->copyFlow([$u0001]);
        $ids = $this->hubRecord($u0001, $u0003);
        $form = $this->copyFormRecord($u0001, $ids['hub'], $ids['ref']);
        $this->onlyRelated('m_ref_a');
        $this->actingAs($u0001);
        $counts = $this->workflowCounts();
        $values = ['title' => '申請書の題', self::TARGET_FIELD => $ids['hub'], 'ref' => $ids['ref']];

        foreach ([$values, [...$values, self::TARGET_FIELD => null]] as $input) {
            $this->postJson($this->applyUrl(), $this->payload($input))
                ->assertUnprocessable()->assertJsonValidationErrors(['values.ref']);
        }

        $this->assertSame($counts, $this->workflowCounts());
        $version = Record::query()->findOrFail($form)->version();
        $this->post($this->applyUrl(), $this->payload($values, ['record_id' => $form, 'record_version' => $version]))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($counts['cases'] + 1, $this->workflowCounts()['cases']);
    }

    /**
     * @return array<string, mixed>
     */
    private function propsOf(int $formRecordId): array
    {
        $response = $this->get('/workflow/flows/'.self::COPY_FLOW."/apply?record={$formRecordId}")->assertOk();

        return $response->viewData('page')['props'];
    }

    private function applyUrl(): string
    {
        return '/workflow/flows/'.self::COPY_FLOW.'/apply';
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $more
     * @return array<string, mixed>
     */
    private function payload(array $values, array $more = []): array
    {
        return ['values' => $values, 'details' => [], 'base_date' => $this->operationDay()->toDateString(), ...$more];
    }
}
