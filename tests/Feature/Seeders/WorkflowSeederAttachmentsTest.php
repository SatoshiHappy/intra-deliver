<?php

namespace Tests\Feature\Seeders;

use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordRevision;
use App\Models\Upload\Upload;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseDraftAttachment;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowRouteNode;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TransactionalTestCase;

/**
 * 申請の周辺の見本の添付(docs の 設計/ワークフロー/申請の周辺/初期データ.md の 1 節と 2 節の検査)。
 * 初期データは DatabaseSeeder が 1 回入れたもの(流し直さない)。
 */
final class WorkflowSeederAttachmentsTest extends TransactionalTestCase
{
    /** 見本のファイルの元 */
    private const SOURCE_FILES = [
        'sample-invoice-format.pdf', 'sample-promotion-estimate.pdf', 'sample-sales-plan.pdf',
    ];

    /**
     * 「添付を追加できない」は版 3 の部長のノードだけ(案件のノードに写されるのも版 3 の案件の部長だけ)。
     */
    public function test_only_the_manager_node_of_version_3_prevents_adding_attachments(): void
    {
        $nodes = WorkflowRouteNode::query()->with('workflowFlowVersion.workflowFlow')->orderBy('id')->get();
        $prevented = $nodes->filter(fn (WorkflowRouteNode $node) => $node->prevents_attachment_add);
        $managers = $nodes->filter(fn (WorkflowRouteNode $node) => $node->code === 'manager');
        $name = fn (WorkflowRouteNode $node) => [
            $node->workflowFlowVersion?->workflowFlow?->code, $node->workflowFlowVersion?->number, $node->code,
        ];

        $this->assertSame([['project_plan', 3, 'manager']], $prevented->map($name)->values()->all());
        $this->assertCount(4, $managers);

        $caseNodes = WorkflowCaseNode::query()->with('workflowCase.workflowFlowVersion')
            ->where('prevents_attachment_add', true)->get();

        $this->assertNotEmpty($caseNodes);
        $this->assertSame(['manager'], $caseNodes->pluck('code')->unique()->values()->all());
        $versions = $caseNodes->map(fn (WorkflowCaseNode $node) => $node->workflowCase?->workflowFlowVersion?->number);
        $this->assertSame([3], $versions->unique()->values()->all());
    }

    /**
     * 一時保存の見本に添付の行が 1 つあり、台帳(uploads)の行がある。
     */
    public function test_the_draft_sample_has_one_attachment_with_a_ledger_row(): void
    {
        $draft = WorkflowCase::query()->where('state', WorkflowCaseState::Draft)->sole();
        $rows = WorkflowCaseDraftAttachment::query()->with('upload.user')->get();

        $named = $rows->map(fn (WorkflowCaseDraftAttachment $row) => [$row->workflow_case_id, $row->name]);
        $this->assertSame([[$draft->id, '請求書の新様式案.pdf']], $named->all());
        $this->assertLedgerRow($rows->sole()->upload, 'sample-invoice-format.pdf', 'u0001');
    }

    /**
     * WF-000014 の添付が record_attachments に 2 つ。どの操作で付いたかは、版の契機と版の写しの添付の数で分かる。ほかの見本の申請書は添付を持たない。
     */
    public function test_the_fourteenth_case_has_the_attachments_of_the_application_and_the_approval(): void
    {
        $case = WorkflowCase::query()->where('case_number', 14)->sole();
        $attachments = RecordAttachment::query()->with(['upload.user', 'user'])
            ->where('record_id', $case->record_id)->orderBy('id')->get();
        $uploads = $attachments->map(fn (RecordAttachment $row) => $row->upload)->values();

        $this->assertSame([
            ['第二四半期の営業計画の概要.pdf', 'u0001'], ['販促費の見積り.pdf', 'u0002'],
        ], $attachments->map(fn (RecordAttachment $row) => [$row->name, $row->user?->code])->all());
        $this->assertLedgerRow($uploads[0], 'sample-sales-plan.pdf', 'u0001');
        $this->assertLedgerRow($uploads[1], 'sample-promotion-estimate.pdf', 'u0002');

        $revisions = RecordRevision::query()->where('record_id', $case->record_id)->orderBy('revision')->get()
            ->map(fn (RecordRevision $row) => [
                $row->origin_operation, $row->origin_node_name, count($row->snapshot['attachments'] ?? []),
            ])->all();
        $this->assertSame([['申請', '申請', 1], ['承認', '課長', 2]], $revisions);

        $caseRecords = WorkflowCase::query()->whereNotNull('record_id')->select('record_id');
        $withAttachments = RecordAttachment::query()->whereIn('record_id', $caseRecords)
            ->pluck('record_id')->unique()->values()->all();
        $this->assertSame([$case->record_id], $withAttachments);
    }

    /**
     * 見本のファイルの元の 3 つは `database/seeders/files/` に残る(正しい PDF。1 ページ・2KB 未満)。
     */
    public function test_the_source_files_stay_in_the_seeder_directory(): void
    {
        $directory = base_path('database/seeders/files');

        $this->assertSame(self::SOURCE_FILES, array_map('basename', glob($directory.'/*') ?: []));

        foreach (self::SOURCE_FILES as $file) {
            $content = (string) file_get_contents($directory.'/'.$file);
            $this->assertLessThan(2048, strlen($content), $file);
            $this->assertStringStartsWith('%PDF-', $content, $file);
            $this->assertSame(1, preg_match_all('/\/Type \/Page\b/', $content), $file);
            $this->assertMatchesRegularExpression('/startxref\n(\d+)\n%%EOF\n\z/', $content, $file);
        }
    }

    /**
     * 初期データの添付の実体はテストの置き場に書く(TransactionalTestCase)ので、テストの本体の置き場(local)に台帳の行が指す実体は無い。
     */
    public function test_the_files_of_the_ledger_rows_are_not_in_the_real_disk(): void
    {
        $paths = Upload::query()->orderBy('id')->pluck('path')->all();

        $this->assertCount(3, $paths);
        Storage::disk('local')->assertMissing($paths);
    }

    /**
     * 台帳の行が、元のファイルと同じ大きさ・型で、上げた人が期待どおり。
     */
    private function assertLedgerRow(?Upload $upload, string $source, string $userCode): void
    {
        $upload ??= $this->fail('台帳の行が無い');
        $size = strlen((string) file_get_contents(base_path('database/seeders/files/'.$source)));

        $this->assertNull($upload->purged_at);
        $this->assertSame(['application/pdf', $size, $userCode], [$upload->mime, $upload->size, $upload->user?->code]);
    }
}
