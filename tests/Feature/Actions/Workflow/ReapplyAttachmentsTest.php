<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\CaseAttachmentRules;
use App\Actions\Workflow\ReapplyCase;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Records\RecordAttachment;
use App\Models\Workflow\WorkflowCase;
use Illuminate\Http\UploadedFile;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\OperatesCaseAttachments;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 再申請の添付(docs の 設計/ワークフロー/申請の周辺/添付/書く順と検査.md の 6 節・9.1 節、読む値 = 設計/ワークフロー/申請の周辺/添付/README.md の 1.1 節):
 * 足すファイルは UpdateRecord が値と同じ版 1 つで付け、外す指定は捨てて付いている添付は残る。フローの値は案件に写した値を読むので、
 * 申請の後にフローを使わないにしても再申請は付き、新しい申請は付かない。
 */
final class ReapplyAttachmentsTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use OperatesCaseAttachments;
    use RegistersProxies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAttachments();
    }

    public function test_a_reapplication_attaches_files_in_one_revision_and_ignores_the_removal(): void
    {
        $u0001 = $this->seededUser('u0001');
        $case = $this->sentBack($this->applyWith($u0001, [$this->uploadedFile('前.pdf')]));
        $attached = RecordAttachment::query()->where('record_id', $case->record_id)->sole();

        $this->reapplyWith($u0001, $case, [$this->uploadedFile('後.pdf')], [$attached->id]);

        $rows = $this->recordRows($case);
        $this->assertSame([['前.pdf', $u0001->id, $attached->upload_id], '後.pdf'], [$rows[0], $rows[1][0]]);
        $this->assertSame([[$u0001->id, null, 1], [$u0001->id, null, 2]], $this->revisions($case));
        $this->assertSame(2, $this->storedFileCount());
    }

    public function test_the_proxy_of_the_applicant_reapplies_with_attachments(): void
    {
        [$owner, $proxy] = [$this->seededUser('u0009'), $this->seededUser('u0004')];
        $this->addProxy($owner, $proxy, WorkflowProxyKind::Apply, $this->flowIdOf(self::FLOW_CODE));
        $case = $this->sentBack($this->applyAs($owner));

        $this->reapplyWith($proxy, $case, [$this->uploadedFile('代理先.pdf')], principal: $owner);

        $rows = $this->recordRows($case);
        $this->assertSame([['代理先.pdf', $proxy->id]], array_map(fn (array $row) => [$row[0], $row[1]], $rows));
        $this->assertSame([[$owner->id, null, 0], [$proxy->id, $owner->id, 1]], $this->revisions($case));
    }

    /**
     * 申請の後にフローを使わないにする: 再申請は写した値(真)で付き、新しい申請は今の値で 422。業務モジュールの不使用は再申請も 422。
     */
    public function test_a_reapplication_reads_the_copy_and_a_new_application_reads_the_flow(): void
    {
        $u0001 = $this->seededUser('u0001');
        $case = $this->sentBack($this->applyAs($u0001));
        $this->switchAttachments(true, false);
        $notAttachable = ['attachments' => CaseAttachmentRules::NOT_ATTACHABLE];

        $errors = $this->validationErrors(fn () => $this->applyWith($u0001, [$this->uploadedFile('新.pdf')]));
        $this->assertSame($notAttachable, $errors);
        $this->reapplyWith($u0001, $case, [$this->uploadedFile('再.pdf')]);
        $this->assertSame(['再.pdf'], array_column($this->recordRows($case), 0));

        $this->sentBack($case);
        $this->switchAttachments(false, true);
        $counts = $this->workflowCounts();
        $errors = $this->validationErrors(fn () => $this->reapplyWith($u0001, $case, [$this->uploadedFile('不使用.pdf')]));

        $this->assertSame($notAttachable, $errors);
        $this->assertSame([$counts, ['再.pdf']], [$this->workflowCounts(), array_column($this->recordRows($case), 0)]);
    }

    private function sentBack(WorkflowCase $case): WorkflowCase
    {
        $this->sendBack($this->seededUser('u0002'), $case, 'apply');

        return $case;
    }

    /**
     * @param  list<UploadedFile>  $files
     * @param  list<int>  $removeIds
     */
    private function reapplyWith(
        User $user,
        WorkflowCase $case,
        array $files,
        array $removeIds = [],
        ?User $principal = null,
    ): void {
        $input = $this->attachmentInput($files, $removeIds);

        app(ReapplyCase::class)
            ->execute($this->contextOf($user), $case->id, $this->revisionOf($case), $input, $principal?->id);
    }
}
