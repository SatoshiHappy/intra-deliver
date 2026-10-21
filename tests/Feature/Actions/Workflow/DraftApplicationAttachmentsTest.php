<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\CaseAttachmentRules;
use App\Actions\Workflow\DeleteDraft;
use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Records\RecordAttachment;
use App\Models\Upload\Upload;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowProxy;
use App\Queries\Upload\Api\UploadLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\OperatesCaseAttachments;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 一時保存からの申請の、可否が偽になった後と巻き戻り(docs の 設計/ワークフロー/申請の周辺/添付/書く順と検査.md の 9.1 節・9.2 節、
 * 可否と外せる人 = 設計/ワークフロー/申請の周辺/添付/README.md の 1.1 節・2 節): 一時保存の添付を黙って外さず黙って移さない。
 * 申請が口の中で失敗すると、一時保存の添付の行と実体は申請の前のまま残る。
 */
final class DraftApplicationAttachmentsTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use OperatesCaseAttachments;
    use RegistersProxies;

    private const STILL_ATTACHED = ['attachments' => CaseAttachmentRules::DRAFT_NOT_ATTACHABLE];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAttachments();
    }

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function disabledSettings(): array
    {
        return ['業務モジュールが不使用' => [false, true], 'フローが使わない' => [true, false]];
    }

    #[DataProvider('disabledSettings')]
    public function test_draft_attachments_must_be_removed_after_turning_off(bool $module, bool $flow): void
    {
        $u0001 = $this->seededUser('u0001');
        $draft = $this->draftWith($u0001, [$this->uploadedFile('a.pdf')]);
        $uploadIds = $this->draftUploadIds($draft);
        $this->switchAttachments($module, $flow);
        $counts = $this->workflowCounts();

        $errors = $this->validationErrors(fn () => $this->applyFromDraft($u0001, $draft));
        $this->assertSame(self::STILL_ATTACHED, $errors);
        $this->assertSame([$counts, [['a.pdf', $u0001->id]], 1], [
            $this->workflowCounts(), $this->draftRows($draft), $this->uploadCount($uploadIds),
        ]);

        $case = $this->applyFromDraft($u0001, $draft, [], $this->draftRowIds($draft));

        $this->assertSame([WorkflowCaseState::Active, [], [], 0], [
            $case->state, $this->draftRows($draft), $this->recordRows($case), $this->uploadCount($uploadIds),
        ]);
        $this->assertSame([[$u0001->id, null, 0]], $this->revisions($case));
    }

    /**
     * ほかの人(代理先)が上げた添付だけが残ると、持ち主は外せず申請できない(代理が終わった後も)。一時保存を削除すれば作り直して申請できる。
     */
    public function test_rows_uploaded_by_another_user_block_the_application_until_the_draft_is_deleted(): void
    {
        [$owner, $proxy] = [$this->seededUser('u0009'), $this->seededUser('u0004')];
        $grant = $this->addProxy($owner, $proxy, WorkflowProxyKind::Apply, $this->flowIdOf(self::FLOW_CODE));
        $draft = $this->draftWith($owner, [$this->uploadedFile('持ち主.pdf')]);
        $this->overwrite($proxy, $draft, [$this->uploadedFile('代理先.pdf')]);
        [$ownerRow, $proxyRow] = $this->draftRowIds($draft);
        $this->switchAttachments(true, false);

        foreach (['代理の期間の中' => null, '代理が終わった後' => $this->operationDay()->toDateString()] as $label => $end) {
            WorkflowProxy::query()->whereKey($grant->id)->update(['end_date' => $end]);
            $removeOwn = fn () => $this->applyFromDraft($owner, $draft, [], [$ownerRow]);
            $removeOther = fn () => $this->applyFromDraft($owner, $draft, [], [$proxyRow]);
            $this->assertSame(self::STILL_ATTACHED, $this->validationErrors($removeOwn), $label);
            $errors = $this->validationErrors($removeOther);
            $this->assertSame(['remove_attachment_ids' => CaseAttachmentRules::REMOVAL_NOT_FOUND], $errors, $label);
            $this->assertCount(2, $this->draftRows($draft), $label);
        }

        $uploadIds = $this->draftUploadIds($draft);
        app(DeleteDraft::class)->execute($this->contextOf($owner), $draft->id, $this->revisionOf($draft));
        $case = $this->applyFromDraft($owner, $this->draftWith($owner));

        $this->assertSame([0, WorkflowCaseState::Active], [$this->uploadCount($uploadIds), $case->state]);
    }

    /**
     * 口の中の失敗(合計の上限・値の誤り)は外側ごと巻き戻る: 外す指定も足したファイルも書かれず、一時保存の添付の行と実体は前のまま。
     */
    public function test_a_failure_inside_the_record_port_keeps_the_draft_attachments(): void
    {
        $u0001 = $this->seededUser('u0001');
        $draft = $this->draftWith($u0001, [$this->uploadedFile('a.pdf'), $this->uploadedFile('b.pdf')]);
        [$aRow] = $this->draftRowIds($draft);
        $uploadIds = $this->draftUploadIds($draft);
        $before = [$this->workflowCounts(), $this->draftRows($draft), 2, 2, RecordAttachment::query()->count()];
        // 外した実体の分が空いても、足すファイルで 1 バイト超える
        $this->limitUploads(total: (int) Upload::query()->whereNull('purged_at')->sum('size') - 1);
        $failures = [
            '合計の上限' => [
                fn () => $this->applyFromDraft($u0001, $draft, [$this->uploadedFile('c.pdf')], [$aRow]),
                ['attachments'],
            ],
            '値の誤り' => [fn () => $this->applyFromDraft($u0001, $draft, [], [$aRow], ['name' => null]), ['values.name']],
        ];

        $this->assertSame(UploadLimits::TOTAL_EXCEEDED, $this->validationErrors($failures['合計の上限'][0])['attachments']);

        foreach ($failures as $label => [$apply, $keys]) {
            $errors = $this->validationErrors($apply);
            $this->assertSame($keys, array_keys($errors), $label);
            $this->assertSame($before, [
                $this->workflowCounts(), $this->draftRows($draft), $this->uploadCount($uploadIds),
                $this->storedFileCount(), RecordAttachment::query()->count(),
            ], $label);
        }

        $this->assertSame(WorkflowCaseState::Draft, WorkflowCase::query()->findOrFail($draft->id)->state);
    }
}
