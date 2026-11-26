<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\CaseAttachmentRules;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseConfirmation;
use App\Models\Workflow\WorkflowCaseHistory;
use Carbon\CarbonImmutable;
use Database\Seeders\SeedingDates;
use Illuminate\Http\UploadedFile;
use Tests\Support\BuildsUploads;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 添付を受けない口(確認・一括承認・一括確認・案件操作)は、送られた attachments を黙って捨てず 422 にし、何も書かない
 * (docs の 設計/ワークフロー/申請の周辺/添付/README.md の 3.1 節、書く順と検査.md の 9.1 節)。空の配列は送っていないのと同じ。
 */
final class WorkflowAttachmentRejectionTest extends TransactionalTestCase
{
    use BuildsUploads;
    use SendsAttachmentEnvelopes;
    use UsesSeededCases;
    use UsesSeededRecords;
    use WritesWorkflowInputs;

    private const ERROR = ['attachments' => CaseAttachmentRules::NOT_ATTACHABLE];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakeUploadDisk();
        CarbonImmutable::setTestNow(SeedingDates::BASE.' 03:00:00 UTC');
    }

    public function test_the_confirmation_rejects_attachments(): void
    {
        $case = $this->caseNumbered(3);
        $count = fn () => WorkflowCaseConfirmation::query()->where('workflow_case_id', $case)->count();
        $before = $count();
        $this->actingAsUser('u0010');

        $this->postJson("/workflow/cases/{$case}/confirm", ['attachments' => ['x']])
            ->assertJsonValidationErrors(self::ERROR);
        $this->multipartFields('POST', "/workflow/cases/{$case}/confirm", [], [$this->uploadedFile('a.pdf')])
            ->assertSessionHasErrors(self::ERROR);
        // PHP の上限で受け取りに失敗したファイルも黙って捨てない
        $source = $this->uploadedFile('a.pdf');
        $failed = new UploadedFile($source->getPathname(), 'a.pdf', null, UPLOAD_ERR_INI_SIZE, true);
        $this->multipartFields('POST', "/workflow/cases/{$case}/confirm", [], [$failed])
            ->assertSessionHasErrors(self::ERROR);
        $this->assertSame($before, $count());

        $this->postJson("/workflow/cases/{$case}/confirm", ['attachments' => []])->assertRedirect();
        $this->assertSame($before + 1, $count());
    }

    public function test_a_case_that_cannot_be_seen_is_not_found_before_the_attachments(): void
    {
        $this->actingAsUser('u0001');

        $this->postJson('/workflow/cases/1234567890/confirm', ['attachments' => ['x']])->assertNotFound();
    }

    public function test_the_bulk_operations_reject_attachments(): void
    {
        $histories = WorkflowCaseHistory::query()->count();
        $this->actingAsUser('u0005');
        $cases = [$this->caseNumbered(2)];

        $this->postJson('/workflow/cases/bulk-approve', ['cases' => [], 'attachments' => ['x']])
            ->assertJsonValidationErrors(self::ERROR);
        $this->postJson('/workflow/cases/bulk-confirm', ['case_ids' => $cases, 'attachments' => ['x']])
            ->assertJsonValidationErrors(self::ERROR);
        $this->assertSame($histories, WorkflowCaseHistory::query()->count());
    }

    public function test_the_case_operations_reject_attachments(): void
    {
        $case = $this->caseNumbered(2);
        $this->actingAsUser('u0004');

        $input = ['revision' => $this->revisionOf($case), 'attachments' => ['x']];
        $this->deleteJson("/workflow/admin/cases/{$case}", $input)->assertJsonValidationErrors(self::ERROR);
        $this->assertTrue(WorkflowCase::query()->whereKey($case)->exists());
    }
}
