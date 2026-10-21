<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\CaseAttachmentRules;
use App\Actions\Workflow\DeleteDraft;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Records\RecordRevision;
use App\Models\Upload\Upload;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseDraftAttachment;
use App\Queries\Upload\Api\UploadLimits;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\OperatesCaseAttachments;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 一時保存(新規・上書き・削除)の添付(docs の 設計/ワークフロー/申請の周辺/添付/書く順と検査.md の 6 節・7 節・9.1 節、
 * 外せる人 = 設計/ワークフロー/申請の周辺/添付/README.md の 2 節): 足した添付は StoreUploads の返した順に一時保存の添付の行になり(上げた人 = 実行者)、
 * 外せるのは実行者が上げたその一時保存の行だけ。一時保存の削除で行と実体が消える。一時保存は版を作らない。
 */
final class DraftAttachmentsTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use OperatesCaseAttachments;
    use RegistersProxies;

    private const NOT_ATTACHABLE = ['attachments' => CaseAttachmentRules::NOT_ATTACHABLE];

    private const REMOVAL_NOT_FOUND = ['remove_attachment_ids' => CaseAttachmentRules::REMOVAL_NOT_FOUND];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAttachments();
    }

    public function test_new_and_overwritten_drafts_add_rows_in_the_order_of_the_uploads(): void
    {
        $u0001 = $this->seededUser('u0001');
        $revisions = RecordRevision::query()->count();
        $files = [$this->uploadedFile('報告.pdf'), $this->uploadedFile('C:\\資料\\図.png', 'png')];

        $draft = $this->draftWith($u0001, $files);
        $this->overwrite($u0001, $draft, [$this->uploadedFile('メモ.txt', 'txt')]);

        $limits = app(UploadLimits::class);
        $names = array_map(fn (UploadedFile $file) => $limits->fileName($file->getClientOriginalName()), $files);
        $this->assertSame([...$names, 'メモ.txt'], ['報告.pdf', '図.png', 'メモ.txt']);
        $expected = [['報告.pdf', $u0001->id], ['図.png', $u0001->id], ['メモ.txt', $u0001->id]];
        $this->assertSame($expected, $this->draftRows($draft));
        $uploadIds = $this->draftUploadIds($draft);
        $sorted = $uploadIds;
        sort($sorted);
        $this->assertSame([3, $sorted], [Upload::query()->whereKey($uploadIds)->count(), $uploadIds]);
        $this->assertSame(3, $this->storedFileCount());
        $draft->refresh();
        $this->assertSame([$revisions, null, 1], [
            RecordRevision::query()->count(), $draft->record_id, $draft->revision,
        ]);
    }

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function settings(): array
    {
        return [
            '使用 × 使う' => [true, true],
            '使用 × 使わない' => [true, false],
            '不使用 × 使う' => [false, true],
            '不使用 × 使わない' => [false, false],
        ];
    }

    /**
     * 持ち主と、持ち主の申請の代理先(上げた人 = 代理先)。どちらかの設定が偽なら 422 attachments で何も書かない。
     */
    #[DataProvider('settings')]
    public function test_drafts_take_attachments_only_when_the_module_and_the_flow_allow(bool $module, bool $flow): void
    {
        [$owner, $proxy] = [$this->seededUser('u0009'), $this->seededUser('u0002')];
        $this->addProxy($owner, $proxy, WorkflowProxyKind::Apply, $this->flowIdOf(self::FLOW_CODE));
        $ownDraft = $this->draftWith($owner);
        $proxyDraft = $this->draftWith($proxy, applicant: $owner);
        $this->switchAttachments($module, $flow);
        $before = [WorkflowCase::query()->count(), WorkflowCaseDraftAttachment::query()->count()];
        $allowed = $module && $flow;
        $operations = [
            'new by the owner' => fn () => $this->draftWith($owner, [$this->uploadedFile('a.pdf')]),
            'new by the proxy' => fn () => $this->draftWith($proxy, [$this->uploadedFile('b.pdf')], $owner),
            'overwrite by the owner' => fn () => $this->overwrite($owner, $ownDraft, [$this->uploadedFile('c.pdf')]),
            'overwrite by the proxy' => fn () => $this->overwrite($proxy, $proxyDraft, [$this->uploadedFile('d.pdf')]),
        ];

        foreach ($operations as $label => $operation) {
            if ($allowed) {
                $operation();
            } else {
                $this->assertSame(self::NOT_ATTACHABLE, $this->validationErrors($operation), $label);
            }
        }

        $added = $allowed ? [2, 4] : [0, 0];
        $after = [WorkflowCase::query()->count(), WorkflowCaseDraftAttachment::query()->count()];
        $this->assertSame($added, [$after[0] - $before[0], $after[1] - $before[1]]);
        $rows = $allowed ? [[['c.pdf', $owner->id]], [['d.pdf', $proxy->id]]] : [[], []];
        $this->assertSame($rows, [$this->draftRows($ownDraft), $this->draftRows($proxyDraft)]);
        $this->assertSame($added[1], $this->storedFileCount());
    }

    public function test_an_error_of_the_upload_leaves_no_draft(): void
    {
        $cases = WorkflowCase::query()->count();
        $empty = UploadedFile::fake()->createWithContent('空.pdf', '');

        $errors = $this->validationErrors(fn () => $this->draftWith($this->seededUser('u0001'), [$empty]));

        $this->assertSame(['attachments.0' => UploadLimits::EMPTY_FILE], $errors);
        $this->assertSame([$cases, 0], [WorkflowCase::query()->count(), $this->storedFileCount()]);
    }

    /**
     * 自分が上げた行だけを外せる(重複は 1 つ)。ほかの人(持ち主か代理先)が上げた行・ほかの案件の行・消えた行は 422 で何も変えない。
     */
    public function test_only_the_rows_uploaded_by_the_executor_can_be_removed(): void
    {
        [$owner, $proxy] = [$this->seededUser('u0009'), $this->seededUser('u0002')];
        $this->addProxy($owner, $proxy, WorkflowProxyKind::Apply, $this->flowIdOf(self::FLOW_CODE));
        $draft = $this->draftWith($owner, [$this->uploadedFile('持ち主.pdf')]);
        $this->overwrite($proxy, $draft, [$this->uploadedFile('代理先.pdf')]);
        $other = $this->draftWith($owner, [$this->uploadedFile('ほか.pdf')]);
        [$ownerRow, $proxyRow] = $this->draftRowIds($draft);
        [$ownerUpload] = $this->draftUploadIds($draft);
        $path = Upload::query()->findOrFail($ownerUpload)->path;
        $rejected = [
            'the proxy row by the owner' => fn () => $this->overwrite($owner, $draft, [], [$proxyRow]),
            'the owner row by the proxy' => fn () => $this->overwrite($proxy, $draft, [], [$ownerRow]),
            'a row of another draft' => fn () => $this->overwrite($owner, $draft, [], $this->draftRowIds($other)),
        ];

        foreach ($rejected as $label => $operation) {
            $this->assertSame(self::REMOVAL_NOT_FOUND, $this->validationErrors($operation), $label);
        }

        $this->assertSame(1, $this->revisionOf($draft));
        $this->overwrite($owner, $draft, [], [$ownerRow, $ownerRow]);

        $this->assertSame([['代理先.pdf', $proxy->id]], $this->draftRows($draft));
        $this->assertSame([0, 2], [$this->uploadCount([$ownerUpload]), $this->storedFileCount()]);
        Storage::disk('local')->assertMissing($path);
        $errors = $this->validationErrors(fn () => $this->overwrite($owner, $draft, [], [$ownerRow]));
        $this->assertSame(self::REMOVAL_NOT_FOUND, $errors);
        $this->assertSame([['ほか.pdf', $owner->id]], $this->draftRows($other));
    }

    public function test_deleting_a_draft_deletes_the_rows_and_the_uploads_after_the_commit(): void
    {
        $u0001 = $this->seededUser('u0001');
        $draft = $this->draftWith($u0001, [$this->uploadedFile('a.pdf'), $this->uploadedFile('b.pdf')]);
        $uploadIds = $this->draftUploadIds($draft);
        $paths = Upload::query()->whereKey($uploadIds)->pluck('path')->all();

        DB::transaction(function () use ($u0001, $draft) {
            app(DeleteDraft::class)->execute($this->contextOf($u0001), $draft->id, 0);
            $this->assertSame(2, $this->storedFileCount(), 'コミットの前は実体が残る');
        });

        $this->assertFalse(WorkflowCase::query()->whereKey($draft->id)->exists());
        $this->assertSame([0, 0], [WorkflowCaseDraftAttachment::query()->count(), $this->uploadCount($uploadIds)]);
        Storage::disk('local')->assertMissing($paths);
    }
}
