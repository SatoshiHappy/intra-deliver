<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\CaseAttachmentRules;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseDraftAttachment;
use App\Queries\Upload\Api\UploadLimits;
use Carbon\CarbonImmutable;
use Database\Seeders\SeedingDates;
use Illuminate\Http\UploadedFile;
use Tests\Support\BuildsUploads;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 添付の入口の形の検証(docs の 設計/ワークフロー/申請の周辺/添付/README.md の 3.1 節・3.3 節): PHP の上限で落ちたファイルは
 * 422 attachments.<添字>(元の画面の誤り)、attachments・remove_attachment_ids の形の誤りと外す指定の数の上限超えは 422。
 * 外す指定を受けない口は捨て、添付を受けない口(一時保存の削除)は attachments を 422 にする。
 */
final class WorkflowAttachmentInputRulesTest extends TransactionalTestCase
{
    use BuildsUploads;
    use SendsAttachmentEnvelopes;
    use UsesSeededCases;
    use UsesSeededRecords;
    use WritesWorkflowInputs;

    private const REMOVALS_INVALID = '削除する添付ファイルの指定が正しくありません';

    /** 外す指定の数の上限(入口の決め) */
    private const MAX_REMOVALS = 100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakeUploadDisk();
        $this->limitUploads();
        CarbonImmutable::setTestNow(SeedingDates::BASE.' 03:00:00 UTC');
    }

    public function test_a_file_dropped_by_php_returns_to_the_form_with_the_upload_rule_message(): void
    {
        $this->actingAsUser('u0001');
        $page = '/workflow/flows/project_plan/apply';
        $source = $this->uploadedFile('a.pdf');
        $failed = fn (int $error) => new UploadedFile($source->getPathname(), 'a.pdf', null, $error, true);
        $files = [$source, $failed(UPLOAD_ERR_INI_SIZE), $failed(UPLOAD_ERR_FORM_SIZE), $failed(UPLOAD_ERR_PARTIAL)];
        $tooLarge = app(UploadLimits::class)->fileTooLargeMessage();
        $count = WorkflowCase::query()->count();
        $input = ['values' => $this->planValues('計画'), 'base_date' => $this->baseDate()];

        $this->multipart('POST', $page, $input, $files, $page)
            ->assertRedirect($page)
            ->assertSessionHasErrors([
                'attachments.1' => $tooLarge,
                'attachments.2' => $tooLarge,
                'attachments.3' => UploadLimits::NOT_RECEIVED,
            ]);

        $this->assertSame(['attachments.1', 'attachments.2', 'attachments.3'], $this->sessionErrorKeys());
        $this->assertSame('ファイルの大きさは 1000B までです', $tooLarge);
        $this->assertSame($count, WorkflowCase::query()->count());
    }

    public function test_the_shape_of_attachments_is_checked(): void
    {
        $this->actingAsUser('u0001');
        $uri = '/workflow/flows/project_plan/drafts';
        $file = $this->uploadedFile('a.pdf');
        $whole = ['attachments' => UploadLimits::NOT_RECEIVED];
        $first = ['attachments.0' => UploadLimits::NOT_RECEIVED];

        $this->postJson($uri, ['attachments' => 'x'])->assertJsonValidationErrors($whole);
        $this->postJson($uri, ['attachments' => ['x']])->assertJsonValidationErrors($first);
        $this->multipart('POST', $uri, [], $file)->assertSessionHasErrors($whole);
        $this->multipart('POST', $uri, [], ['a' => $file])->assertSessionHasErrors($whole);
        $this->multipart('POST', $uri, [], [['x' => $file]])->assertSessionHasErrors($first);
        $this->multipart('POST', $uri, ['attachments' => ['x']])->assertSessionHasErrors($first);

        $this->assertSame(0, WorkflowCaseDraftAttachment::query()->count());
    }

    public function test_the_shape_and_the_count_of_removals_are_checked_on_the_draft_overwrite(): void
    {
        $this->actingAsUser('u0001');
        $draft = $this->seededDraft();
        $uri = "/workflow/drafts/{$draft}";
        $this->multipart('PUT', $uri, ['revision' => $this->revisionOf($draft)], [$this->uploadedFile('a.pdf')])
            ->assertSessionHasNoErrors();
        $rowId = WorkflowCaseDraftAttachment::query()->where('workflow_case_id', $draft)->sole()->id;
        $put = fn (mixed $ids) => $this->putJson($uri, [
            'revision' => $this->revisionOf($draft), 'remove_attachment_ids' => $ids,
        ]);
        $whole = ['remove_attachment_ids' => self::REMOVALS_INVALID];

        $put('x')->assertJsonValidationErrors($whole);
        $put(['a' => $rowId])->assertJsonValidationErrors($whole);
        $put([$rowId, 'x'])->assertJsonValidationErrors(['remove_attachment_ids.1' => self::REMOVALS_INVALID]);
        $put(array_fill(0, self::MAX_REMOVALS + 1, $rowId))->assertJsonValidationErrors($whole);
        $this->assertSame(['a.pdf'], $this->draftAttachmentNames($draft));

        // 上限ちょうどは入口を通る(同じ id の重なりは業務処理が 1 つにまとめる)
        $put(array_fill(0, self::MAX_REMOVALS, $rowId))->assertRedirect("{$uri}/edit")->assertSessionHasNoErrors();
        $this->assertSame([], $this->draftAttachmentNames($draft));
    }

    public function test_the_removals_are_dropped_where_they_are_not_received(): void
    {
        $this->actingAsUser('u0001');
        $draft = $this->seededDraft();
        $broken = ['remove_attachment_ids' => 'x'];
        $input = ['values' => $this->planValues('計画'), 'base_date' => $this->baseDate(), ...$broken];

        $this->multipart('POST', '/workflow/flows/project_plan/apply', $input)
            ->assertStatus(302)->assertSessionHasNoErrors();
        $this->multipart('POST', '/workflow/flows/project_plan/drafts', $broken)
            ->assertStatus(302)->assertSessionHasNoErrors();
        $case = $this->caseNumbered(4);
        $reapply = ['revision' => $this->revisionOf($case), 'values' => $this->planValuesOfCase4(), ...$broken];
        $this->multipart('POST', "/workflow/cases/{$case}/reapply", $reapply)
            ->assertStatus(302)->assertSessionHasNoErrors();

        $this->actingAsUser('u0002');
        $case = $this->caseNumbered(2);
        $approve = ['revision' => $this->revisionOf($case), 'node_id' => $this->currentNodeIdOf($case), ...$broken];
        $this->multipart('POST', "/workflow/cases/{$case}/approve", $approve)
            ->assertStatus(302)->assertSessionHasNoErrors();

        $this->actingAsUser('u0001');
        $this->deleteJson("/workflow/drafts/{$draft}", ['revision' => $this->revisionOf($draft), ...$broken])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(WorkflowCase::query()->find($draft));
    }

    public function test_the_draft_delete_rejects_attachments_instead_of_dropping_them(): void
    {
        $this->actingAsUser('u0001');
        $draft = $this->seededDraft();
        $revision = $this->revisionOf($draft);

        $uri = "/workflow/drafts/{$draft}";
        $error = ['attachments' => CaseAttachmentRules::NOT_ATTACHABLE];

        $this->deleteJson($uri, ['revision' => $revision, 'attachments' => ['x']])->assertJsonValidationErrors($error);
        $this->multipartFields('DELETE', $uri, ['revision' => $revision], [$this->uploadedFile('a.pdf')])
            ->assertSessionHasErrors($error);
        $this->assertSame(['attachments'], $this->sessionErrorKeys());
        $this->assertNotNull(WorkflowCase::query()->find($draft));

        // 空の配列は「送っていない」と同じ(3.2 節の「attachments が空でない」)
        $this->deleteJson($uri, ['revision' => $revision, 'attachments' => []])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(WorkflowCase::query()->find($draft));
    }
}
