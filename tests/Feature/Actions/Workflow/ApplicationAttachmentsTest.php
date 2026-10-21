<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\CaseAttachmentRules;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Records\RecordAttachment;
use App\Models\Upload\Upload;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseDraftAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\OperatesCaseAttachments;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 申請(新規・一時保存から)の添付(docs の 設計/ワークフロー/申請の周辺/添付/書く順と検査.md の 6 節・7 節・9.1 節・9.2 節):
 * 可否は業務モジュールとフローの今の値の AND(ステップを見ない)。一時保存の添付は外す指定を消した残りを CreateRecord に渡して移し、
 * 移した行を同じトランザクションで消す。値と添付は版 1 つ。付けた人・版の操作した人 = 実行者、処理者 = 代理元。
 */
final class ApplicationAttachmentsTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use OperatesCaseAttachments;
    use RegistersProxies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAttachments();
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
     * 新規の申請と一時保存からの申請を、持ち主と持ち主の申請の代理先で。どちらかの設定が偽なら 422 attachments で何も書かない。
     */
    #[DataProvider('settings')]
    public function test_applications_take_attachments_only_when_both_allow(bool $module, bool $flow): void
    {
        [$owner, $proxy] = [$this->seededUser('u0009'), $this->seededUser('u0004')];
        $this->addProxy($owner, $proxy, WorkflowProxyKind::Apply, $this->flowIdOf(self::FLOW_CODE));
        $ownDraft = $this->draftWith($owner);
        $proxyDraft = $this->draftWith($proxy, applicant: $owner);
        $this->switchAttachments($module, $flow);
        $applications = [
            'new by the owner' => [$owner, fn () => $this->applyWith($owner, [$this->uploadedFile('a.pdf')])],
            'new by the proxy' => [$proxy, fn () => $this->applyFor($proxy, $owner, [$this->uploadedFile('b.pdf')])],
            'draft by the owner' => [
                $owner, fn () => $this->applyFromDraft($owner, $ownDraft, [$this->uploadedFile('c.pdf')]),
            ],
            'draft by the proxy' => [
                $proxy, fn () => $this->applyFromDraft($proxy, $proxyDraft, [$this->uploadedFile('d.pdf')]),
            ],
        ];

        foreach ($applications as $label => [$executor, $apply]) {
            if (! ($module && $flow)) {
                $counts = $this->workflowCounts();
                $errors = $this->validationErrors($apply);
                $this->assertSame(['attachments' => CaseAttachmentRules::NOT_ATTACHABLE], $errors, $label);
                $this->assertSame($counts, $this->workflowCounts(), $label);

                continue;
            }

            $case = $apply();
            $principal = $executor->is($owner) ? null : $owner->id;
            $this->assertSame([$executor->id], array_column($this->recordRows($case), 1), $label);
            $this->assertSame([[$executor->id, $principal, 1]], $this->revisions($case), $label);
        }
    }

    /**
     * 外す指定と足すファイルを混ぜた申請: 外した行の実体は消え、残りの行の実体はレコードの添付に付き(行の順の後に足したファイル)、一時保存の添付の行は 0。
     */
    public function test_an_application_from_a_draft_moves_the_remaining_rows_to_the_record(): void
    {
        $u0001 = $this->seededUser('u0001');
        $draft = $this->draftWith($u0001, [$this->uploadedFile('a.pdf'), $this->uploadedFile('b.pdf')]);
        $this->overwrite($u0001, $draft, [$this->uploadedFile('c.pdf')]);
        [, $bRow] = $this->draftRowIds($draft);
        [$aUpload, $bUpload, $cUpload] = $this->draftUploadIds($draft);
        $bPath = Upload::query()->findOrFail($bUpload)->path;

        $case = $this->applyFromDraft($u0001, $draft, [$this->uploadedFile('d.pdf')], [$bRow]);

        $this->assertSame(0, WorkflowCaseDraftAttachment::query()->where('workflow_case_id', $draft->id)->count());
        $rows = $this->recordRows($case);
        $this->assertSame([['a.pdf', $u0001->id, $aUpload], ['c.pdf', $u0001->id, $cUpload]], array_slice($rows, 0, 2));
        $this->assertSame([3, 'd.pdf', $u0001->id], [count($rows), $rows[2][0], $rows[2][1]]);
        $this->assertSame([[$u0001->id, null, 3]], $this->revisions($case));
        $this->assertSame([0, 2], [$this->uploadCount([$bUpload]), $this->uploadCount([$aUpload, $cUpload])]);
        $this->assertSame(3, $this->storedFileCount());
        Storage::disk('local')->assertMissing($bPath);
        $this->assertUploadsAreOwnedOnce();
    }

    /**
     * 代理先が一時保存から申請すると、移した添付(持ち主が上げたものを含む)の付けた人も代理先、上げた人は上げた時のまま。
     */
    public function test_the_attached_user_of_the_moved_rows_is_the_executor(): void
    {
        [$owner, $proxy] = [$this->seededUser('u0009'), $this->seededUser('u0004')];
        $this->addProxy($owner, $proxy, WorkflowProxyKind::Apply, $this->flowIdOf(self::FLOW_CODE));
        $draft = $this->draftWith($owner, [$this->uploadedFile('持ち主.pdf')]);
        $uploadIds = $this->draftUploadIds($draft);

        $case = $this->applyFromDraft($proxy, $draft);

        $this->assertSame([['持ち主.pdf', $proxy->id, $uploadIds[0]]], $this->recordRows($case));
        $this->assertSame($owner->id, Upload::query()->findOrFail($uploadIds[0])->user_id);
        $this->assertSame([[$proxy->id, $owner->id, 1]], $this->revisions($case));
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function applyFor(User $proxy, User $principal, array $files): WorkflowCase
    {
        $context = $this->contextOf($proxy);
        $input = $this->attachmentInput($files);
        $date = $context->date->toDateString();

        return WorkflowCase::query()->findOrFail(
            app(ApplyCase::class)->execute($context, self::FLOW_CODE, $input, $date, $principal->id)->caseId,
        );
    }

    /**
     * 9.2 節: 1 つの実体を指すのは、レコードの添付と一時保存の添付を合わせて 1 行だけ。
     */
    private function assertUploadsAreOwnedOnce(): void
    {
        $owned = [
            ...RecordAttachment::query()->pluck('upload_id')->all(),
            ...WorkflowCaseDraftAttachment::query()->pluck('upload_id')->all(),
        ];

        $this->assertSame(count($owned), count(array_unique($owned)));
    }
}
