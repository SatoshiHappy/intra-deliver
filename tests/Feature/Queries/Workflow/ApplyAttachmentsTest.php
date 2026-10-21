<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Records\RowAccessScope;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordModule;
use App\Models\Upload\Upload;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseDraftAttachment;
use App\Queries\Upload\Api\UploadLimits;
use App\Queries\Workflow\ApplicationFormQuery;
use App\Queries\Workflow\ApplyAttachments;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OperatesCaseAttachments;
use Tests\Support\TransactionalTestCase;

/**
 * 申請の画面の attachment(docs の 設計/ワークフロー/申請の周辺/添付/README.md の 5 節): enabled = 業務モジュールとフローの AND
 * (申請の前はフローの今の値、再申請は案件に写した値)、drafts = 一時保存の添付の行(enabled によらず入る。removable = 自分が上げた行)、
 * limits = 設定から作った上限と案内(4 節の送る前の確かめの文を含む)。attached・attachedCount = 再申請のレコードに付いている添付
 * (上限まで新しい順と全部の件数。レコードを閲覧できない人には出さない)。
 */
final class ApplyAttachmentsTest extends TransactionalTestCase
{
    use OperatesCaseAttachments;

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

    #[DataProvider('settings')]
    public function test_enabled_needs_both_settings_and_drafts_are_listed_either_way(bool $module, bool $flow): void
    {
        $u0001 = $this->seededUser('u0001');
        $draft = $this->draftWith($u0001, [$this->uploadedFile('見積.pdf')]);
        $this->switchAttachments($module, $flow);

        $new = $this->forms()->create($this->contextOf($u0001), self::FLOW_CODE)['attachment'];
        $form = $this->forms()->draft($this->contextOf($u0001), $draft->id) ?? $this->fail('一時保存の画面が無い');
        $edit = $form['attachment'];

        $newShape = [$new['enabled'], $new['drafts'], $new['attached'], $new['attachedCount']];
        $this->assertSame([$module && $flow, [], [], 0], $newShape);
        $this->assertSame([[], 0], [$edit['attached'], $edit['attachedCount']]);
        $this->assertSame($module && $flow, $edit['enabled']);
        $drafts = array_map(fn (array $row) => [$row['name'], $row['removable']], $edit['drafts']);
        $this->assertSame([['見積.pdf', true]], $drafts);
    }

    /**
     * 外せるのは自分が上げた行だけ(ほかの人 = 代理先などが上げた行は偽)。行は付けた順で、大きさは台帳の値。
     */
    public function test_only_the_rows_the_user_uploaded_are_removable(): void
    {
        $u0001 = $this->seededUser('u0001');
        $draft = $this->draftWith($u0001, [$this->uploadedFile('自分.pdf')]);
        $other = WorkflowCaseDraftAttachment::factory()->create([
            'workflow_case_id' => $draft->id,
            'upload_id' => Upload::factory()->create(['user_id' => $this->seededUser('u0002')->id, 'size' => 1234])->id,
            'name' => 'ほかの人.pdf',
        ]);
        $own = WorkflowCaseDraftAttachment::query()->where('workflow_case_id', $draft->id)
            ->orderBy('id')->firstOrFail();
        $ownSize = Upload::query()->findOrFail($own->upload_id)->size;

        $drafts = $this->forms()->draft($this->contextOf($u0001), $draft->id)['attachment']['drafts'] ?? [];

        $this->assertSame([
            ['id' => $own->id, 'name' => '自分.pdf', 'size' => $ownSize, 'removable' => true],
            ['id' => $other->id, 'name' => 'ほかの人.pdf', 'size' => 1234, 'removable' => false],
        ], $drafts);
    }

    /**
     * 再申請は案件に写した値を読み(フローの今の値を読まない)、drafts は空。
     */
    public function test_the_reapplication_reads_the_copy_and_has_no_drafts(): void
    {
        $u0001 = $this->seededUser('u0001');
        $case = $this->applyAs($u0001);
        $this->sendBack($this->seededUser('u0002'), $case, 'apply');
        $read = fn () => $this->forms()->reapply($this->contextOf($u0001), $case->id)['attachment'] ?? [];

        $this->setCopy($case, false, flow: true);
        $this->assertSame([false, []], [$read()['enabled'] ?? null, $read()['drafts'] ?? null]);
        $this->setCopy($case, true, flow: false);
        $this->assertSame([true, []], [$read()['enabled'] ?? null, $read()['drafts'] ?? null]);
    }

    /**
     * 付いている添付は新しい順に上限(20 件)まで、件数は全部(欄の「ほかに N 件」)。
     */
    public function test_the_reapplication_lists_the_attached_files_up_to_the_limit_with_the_count(): void
    {
        $case = $this->returnedCase();
        $record = Record::query()->findOrFail($case->record_id);

        foreach (range(1, ApplyAttachments::ATTACHED_LIMIT + 1) as $number) {
            RecordAttachment::factory()->create([
                'record_module_id' => $record->record_module_id,
                'record_id' => $record->id,
                'upload_id' => Upload::factory()->create(['size' => 100 + $number])->id,
                'name' => "添付{$number}.pdf",
            ]);
        }

        $attachment = $this->reapplyAttachment($case);

        $this->assertCount(ApplyAttachments::ATTACHED_LIMIT, $attachment['attached']);
        $this->assertSame(ApplyAttachments::ATTACHED_LIMIT + 1, $attachment['attachedCount']);
        $this->assertSame(['name' => '添付21.pdf', 'size' => 121], $attachment['attached'][0]);
        $this->assertSame(['name' => '添付2.pdf', 'size' => 102], $attachment['attached'][19]);
    }

    /**
     * 行の権限でレコードを見られない申請者には、付いている添付を出さない(件数も 0。サイドパネルと同じ見る人)。
     */
    public function test_an_applicant_who_cannot_view_the_record_gets_no_attached_files(): void
    {
        $case = $this->returnedCase();
        $record = Record::query()->findOrFail($case->record_id);
        RecordAttachment::factory()->create([
            'record_module_id' => $record->record_module_id, 'record_id' => $record->id, 'name' => '見えない.pdf',
        ]);
        $this->assertSame(1, $this->reapplyAttachment($case)['attachedCount']);

        RecordModule::query()->whereKey($record->record_module_id)
            ->update(['row_access_scope' => RowAccessScope::Related]);
        $record->update(['created_by_user_id' => $this->seededUser('u0003')->id]);

        $attachment = $this->reapplyAttachment($case);
        $this->assertSame([[], 0], [$attachment['attached'], $attachment['attachedCount']]);
    }

    /**
     * 上限の数は設定から、形式は対応表から、文は案内の 1 行と送る前の確かめ(数は設定と同じ書き方)。
     */
    public function test_the_limits_are_made_from_the_settings(): void
    {
        $this->limitUploads(2 * 1_048_576, 5 * 1_048_576);

        $context = $this->contextOf($this->seededUser('u0001'));
        $limits = $this->forms()->create($context, self::FLOW_CODE)['attachment']['limits'];

        $extensions = array_keys(app(UploadLimits::class)->formats());
        $this->assertSame([
            'maxFileBytes' => 2 * 1_048_576,
            'maxRequestBytes' => 5 * 1_048_576,
            'maxFiles' => 3,
            'accept' => implode(',', array_map(fn (string $extension) => ".{$extension}", $extensions)),
            'guide' => app(UploadLimits::class)->guide(),
            'tooLarge' => '送信する内容が大きすぎます。添付ファイルは 1 回 3 ファイル・合計 5MB、1 ファイル 2MB までです。',
        ], $limits);
        $this->assertStringStartsWith('.pdf,.png,.jpg,.jpeg,', $limits['accept']);
        $this->assertStringStartsWith('1 ファイル 2MB まで、1 回 3 ファイル・合計 5MB までです。', $limits['guide']);
    }

    /**
     * 割り切れない設定の数でも、送る前の確かめの文と案内の 1 行は同じ数の書き方になる。
     */
    public function test_the_too_large_message_writes_the_numbers_like_the_guide(): void
    {
        $this->limitUploads(1_536 * 1_024, 3 * 1_073_741_824 + 1_024);

        $context = $this->contextOf($this->seededUser('u0001'));
        $limits = $this->forms()->create($context, self::FLOW_CODE)['attachment']['limits'];

        $this->assertStringContainsString('1 ファイル 1536KB まで、1 回 3 ファイル・合計 3145729KB までです。', $limits['guide']);
        $this->assertStringContainsString('合計 3145729KB、1 ファイル 1536KB までです。', $limits['tooLarge']);
    }

    /**
     * 申請者 u0001 の、申請へ差戻した案件(再申請できる)。
     */
    private function returnedCase(): WorkflowCase
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $this->sendBack($this->seededUser('u0002'), $case, 'apply');

        return $case->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function reapplyAttachment(WorkflowCase $case): array
    {
        $form = $this->forms()->reapply($this->contextOf($this->seededUser('u0001')), $case->id);

        return ($form ?? $this->fail('再申請の画面が無い'))['attachment'];
    }

    private function setCopy(WorkflowCase $case, bool $copy, bool $flow): void
    {
        $case->update(['is_attachment_enabled' => $copy]);
        $this->switchAttachments(true, $flow);
    }

    private function forms(): ApplicationFormQuery
    {
        return app(ApplicationFormQuery::class);
    }
}
