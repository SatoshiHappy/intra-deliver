<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Workflow\CaseAttachmentRules;
use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Records\RecordAttachment;
use App\Models\Workflow\WorkflowCase;
use Illuminate\Http\UploadedFile;
use Tests\Support\AppliesFromRecords;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\OperatesCaseAttachments;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 既存のデータからの申請の添付(docs の 設計/ワークフロー/申請の周辺/添付/README.md の 2 節、設計/ワークフロー/申請の周辺/添付/書く順と検査.md の 6 節):
 * この送信のファイルを更新の口が値と同じ版 1 つで付け、外す指定は捨てて付いている添付は残る。フローが添付を使わなければ 422 attachments。
 * 上げた人は代理のとき代理先。一時保存のルートに record_id を送っても捨てる(一時保存の入力のまま)。
 */
final class RecordApplicationAttachmentsTest extends TransactionalTestCase
{
    use AppliesFromRecords;
    use CatchesValidationErrors;
    use OperatesCaseAttachments;
    use RegistersProxies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAttachments();
    }

    public function test_the_files_are_attached_in_one_revision_and_the_removals_are_ignored(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->recordWith($u0001, [$this->uploadedFile('前.pdf')]);
        $attached = RecordAttachment::query()->where('record_id', $record)->sole();

        $input = $this->attachmentInput([$this->uploadedFile('後.pdf')], [$attached->id]);
        $case = $this->applyFromRecord($u0001, $record, $input);

        $rows = $this->recordRows($case);
        $this->assertSame(
            [['前.pdf', $u0001->id, $attached->upload_id], ['後.pdf', $u0001->id]],
            [$rows[0], array_slice($rows[1], 0, 2)],
        );
        $this->assertSame([[$u0001->id, null, 1], [$u0001->id, null, 2]], $this->revisions($case));
    }

    public function test_a_flow_without_attachments_rejects_the_files_and_writes_nothing(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->recordWith($u0001);
        $this->switchAttachments(true, false);
        $counts = $this->workflowCounts();

        $input = $this->attachmentInput([$this->uploadedFile('不可.pdf')]);
        $errors = $this->validationErrors(fn () => $this->applyFromRecord($u0001, $record, $input));

        $this->assertSame(['attachments' => CaseAttachmentRules::NOT_ATTACHABLE], $errors);
        $this->assertSame($counts, $this->workflowCounts());
        $this->assertSame(0, $this->storedFileCount());
    }

    public function test_the_proxy_uploads_as_the_executor(): void
    {
        [$principal, $proxy] = [$this->seededUser('u0009'), $this->seededUser('u0001')];
        $this->addProxy($principal, $proxy, WorkflowProxyKind::Apply, $this->flowIdOf(self::FLOW_CODE));
        $record = $this->recordWith($principal);

        $input = $this->attachmentInput([$this->uploadedFile('代理先.pdf')]);
        $case = $this->applyFromRecord($proxy, $record, $input, $principal);

        $rows = array_map(fn (array $row) => [$row[0], $row[1]], $this->recordRows($case));
        $this->assertSame([['代理先.pdf', $proxy->id]], $rows);
        $this->assertSame([[$principal->id, null, 0], [$proxy->id, $principal->id, 1]], $this->revisions($case));
    }

    public function test_a_draft_route_drops_the_record_id(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->recordWith($u0001);
        $this->actingAs($u0001);

        $response = $this->post('/workflow/flows/'.self::FLOW_CODE.'/drafts', [
            'name' => '下書き', 'comment' => null, 'values' => ['name' => '下書き'],
            'record_id' => $record, 'record_version' => '',
        ], ['X-Inertia' => 'true']);

        $response->assertStatus(302)->assertSessionHasNoErrors();
        $drafts = WorkflowCase::query()->where('user_id', $u0001->id)->where('state', WorkflowCaseState::Draft);
        $draft = $drafts->latest('id')->firstOrFail();
        $this->assertNull($draft->record_id);
        $this->assertArrayNotHasKey('record_id', $draft->draft_input ?? []);
        $this->assertSame([], WorkflowCase::query()->where('record_id', $record)->pluck('id')->all());
    }

    /**
     * 業務の画面で登録したレコード(添付つき)。
     *
     * @param  list<UploadedFile>  $files
     */
    private function recordWith(User $user, array $files = []): int
    {
        $values = $this->projectInput()->record->values;

        $input = new RecordInput($values, attachFiles: $files);

        return app(CreateRecord::class)->execute($this->contextOf($user), self::PROJECT, $input);
    }
}
