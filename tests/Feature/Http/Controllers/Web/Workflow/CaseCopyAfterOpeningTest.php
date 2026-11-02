<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Records\Api\RecordInput;
use App\Actions\Workflow\ApplicationInput;
use App\Actions\Workflow\ApplyCase;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Records\Api\RecordFormQuery;
use Carbon\CarbonImmutable;
use Tests\Support\AppliesToTargets;
use Tests\Support\BuildsApplicationTargetCopies;
use Tests\Support\BuildsUploads;
use Tests\Support\CopiesCases;
use Tests\Support\InterposesAfterRevisionCopy;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 案件からの複製の画面を開いた後の要求と申請(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/検査.md の 14 節の
 * 12.3 の「開いた後」・13.1・13.2): 開くときは写しの初期値の口を 1 回呼び、今の値の読み取りを呼ばない。copy の無い GET は写しの口を
 * 呼ばない。開いた後に閲覧を失っても申請者の変更・申請・一時保存が通る。申請しても元の申請書の値・版・添付・履歴・状態が変わらない。
 */
final class CaseCopyAfterOpeningTest extends TransactionalTestCase
{
    use AppliesToTargets;
    use BuildsApplicationTargetCopies;
    use BuildsUploads;
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
     * 開く GET は版から写す口を 1 回呼び、申請書のレコードの今の値を読む口を呼ばない。部分の再読み込み(申請者・申請基準日)と
     * 入力の誤りで戻る GET には copy が無く、写しの口を呼ばず、props の copy は null(開いた時の値は画面が保つ)。
     */
    public function test_requests_without_copy_do_not_copy_again(): void
    {
        [$u0001] = $this->usersOf('u0001');
        $this->copyFlow([$u0001]);
        $source = $this->sourceCase($u0001);
        $form = new InterposesAfterRevisionCopy(app(RecordFormQuery::class), fn () => null);
        app()->instance(RecordFormQuery::class, $form);

        $opened = $this->copyPropsAs($u0001, $source->id);

        $this->assertSame([1, 0], $this->countsOf($form->calls, ['copyFromRevision', 'editValues']));
        $form->calls = [];
        $query = ['applicant' => (string) $u0001->id, 'base_date' => (string) $opened['baseDate']];
        $this->actingAs($u0001);
        $later = $this->pagePropsOf($this->get(self::APPLY_URL.'?'.http_build_query($query))->assertOk());
        $this->assertSame([0, 0], $this->countsOf($form->calls, ['copyFromRevision', 'editValues']));
        $this->assertNull($later['copy']);
        $this->assertNull($later['initial']);
    }

    /**
     * 開いた後に本人が元の申請を閲覧できなくなっても、申請者を変える GET・申請・一時保存が 404・422 にならず、参照制限を引き継ぐ。
     */
    public function test_losing_the_source_after_opening_still_applies_and_saves(): void
    {
        [$u0001, $u0005] = $this->usersOf('u0001', 'u0005');
        $this->copyFlow([$u0001, $u0005]);
        $viewer = ['type' => 'user', 'id' => $u0001->id];
        $source = $this->sourceCase($u0001, [], ['restricted' => true, 'viewers' => [$viewer]]);
        $this->referTo($u0005);
        $props = $this->copyPropsAs($u0005, $source->id);

        $this->referTo(null);

        $this->assertSame(404, $this->copyStatusAs($u0005, $source->id));
        $this->get(self::APPLY_URL.'?applicant='.$u0005->id)->assertOk();
        $applied = $this->postApply($u0005, $this->sentFrom($props));
        $this->assertSame([true, [$viewer]], $this->viewersOf((int) $applied->record_id));
        $draft = $this->postDraft($u0005, $this->sentFrom($props));
        $kept = ['restricted' => true, 'viewers' => [['id' => $u0001->id, 'type' => 'user']]];
        $this->assertSame($kept, $this->draftCopied($draft));
    }

    /**
     * 添付のある申請を写して申請すると、新しい申請書のレコードに添付が無く、元の申請書のレコードの値・版の数・添付と、元の案件の履歴・状態が
     * 変わらない。
     */
    public function test_applying_the_copy_leaves_the_source_unchanged(): void
    {
        [$u0001] = $this->usersOf('u0001');
        $this->copyFlow([$u0001]);
        $this->fakeUploadDisk();
        $this->limitUploads();
        RecordModule::query()->where('code', self::COPY_FORM)->update(['is_attachment_enabled' => true]);
        WorkflowFlow::query()->where('code', self::COPY_FLOW)->update(['is_attachment_enabled' => true]);
        $context = $this->contextOf($u0001);
        $files = [$this->uploadedFile('元.pdf')];
        $input = new ApplicationInput('元の申請', null, new RecordInput(['title' => '元の題']), $files);
        $caseId = app(ApplyCase::class)
            ->execute($context, self::COPY_FLOW, $input, $context->date->toDateString())->caseId;
        $source = WorkflowCase::query()->findOrFail($caseId);
        $before = $this->stateOf($source);
        $this->assertCount(1, $before['attachments']);

        $applied = $this->postApply($u0001, $this->sentFrom($this->copyPropsAs($u0001, $caseId)));

        $this->assertSame($before, $this->stateOf($source));
        $this->assertSame(0, RecordAttachment::query()->where('record_id', $applied->record_id)->count());
    }

    /**
     * @param  list<string>  $calls
     * @param  list<string>  $names
     * @return list<int> 名前ごとの呼ばれた回数
     */
    private function countsOf(array $calls, array $names): array
    {
        return array_map(fn (string $name) => count(array_keys($calls, $name, true)), $names);
    }

    /**
     * 元の申請の、申請書のレコードの値・版の数・添付と、案件の履歴・状態。
     *
     * @return array<string, mixed>
     */
    private function stateOf(WorkflowCase $case): array
    {
        $case = WorkflowCase::query()->findOrFail($case->id);
        $recordId = (int) $case->record_id;

        return [
            'values' => Record::query()->findOrFail($recordId)->field_values,
            'revisions' => RecordRevision::query()->where('record_id', $recordId)->count(),
            'attachments' => RecordAttachment::query()->where('record_id', $recordId)->orderBy('id')
                ->get(['id', 'upload_id', 'name'])->toArray(),
            'histories' => WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->orderBy('id')
                ->get()->toArray(),
            'case' => [$case->state, $case->revision],
        ];
    }
}
