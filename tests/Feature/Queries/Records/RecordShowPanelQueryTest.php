<?php

namespace Tests\Feature\Queries\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\RecordRevisionOrigin;
use App\Actions\Records\Api\UpdateRecord;
use App\Actions\Records\DetachRecordAttachments;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordModule;
use App\Models\Upload\Upload;
use App\Queries\Records\Api\RecordShowPanelQuery;
use App\Queries\Upload\Api\UploadLimits;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\BuildsUploads;
use Tests\Support\RegistersRecordHolds;
use Tests\Support\TransactionalTestCase;

/**
 * サイドパネルの Records の欄(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 2 節・6.2 節): 添付ファイルの欄を出す条件と
 * 中身(新しい順の 5 件と続き・付けた人・上の 1 行・案内)、更新履歴の欄の版の行(更新履歴.md の 5 節 = 頭の代理の形・ゲスト・契機、
 * 変化の項目 → 明細 → 添付の順、前の版の無い update・登録は頭だけ、添付の削除の行の実体)。業務モジュールとレコードはテストで作る。
 */
final class RecordShowPanelQueryTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;
    use BuildsUploads;
    use RegistersRecordHolds;

    private RecordModule $module;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->module = $this->recordModule('projects');
        $this->field($this->module, 'title');
        $tasks = $this->detailsField($this->module, 'tasks');
        $this->field($this->childOf($tasks), 'task_name');
    }

    public function test_the_attachments_pane_reads_five_newest_rows_and_the_rest_after_before(): void
    {
        $user = $this->user();
        $record = Record::factory()->create(['record_module_id' => $this->module->id]);
        $attachments = RecordAttachment::factory()->count(6)->sequence(fn ($sequence) => [
            'name' => "file{$sequence->index}.pdf",
            'user_id' => $sequence->index === 5 ? $user->id : null,
        ])->create(['record_module_id' => $this->module->id, 'record_id' => $record->id]);
        $ids = array_reverse(array_values($attachments->pluck('id')->all()));

        $first = $this->content($record->id, 'attachments', null);
        $rest = $this->content($record->id, 'attachments', $ids[4]);

        $this->assertNotNull($first);
        $this->assertSame('attachments', $first['kind']);
        $this->assertSame(array_slice($ids, 0, 5), array_column($first['rows'], 'id'));
        $this->assertTrue($first['hasMore']);
        $this->assertSame([$user->id, null, null, null, null], array_map(
            fn (array $row) => $row['attachedBy']['id'] ?? null,
            $first['rows'],
        ));
        $this->assertArrayNotHasKey('userId', $first['rows'][0]);
        $this->assertNull($first['notice']);
        $this->assertSame(app(UploadLimits::class)->guide(), $first['guide']);
        $this->assertSame([[$ids[5]], false], [array_column($rest['rows'] ?? [], 'id'), $rest['hasMore'] ?? null]);
    }

    public function test_the_attachments_pane_is_shown_when_the_module_uses_attachments_or_a_record_has_one(): void
    {
        $unused = $this->recordModule('memos');
        $unused->update(['is_attachment_enabled' => false]);
        $used = Record::factory()->create(['record_module_id' => $this->module->id]);
        $bare = Record::factory()->create(['record_module_id' => $unused->id]);
        $attached = Record::factory()->create(['record_module_id' => $unused->id]);
        RecordAttachment::factory()->create(['record_module_id' => $unused->id, 'record_id' => $attached->id]);

        $this->assertSame([['attachments', 0], ['revisions', null]], $this->items('projects', $used->id));
        $this->assertSame([['revisions', null]], $this->items('memos', $bare->id));
        $this->assertSame([['attachments', 1], ['revisions', null]], $this->items('memos', $attached->id));
        $this->assertNull($this->content($bare->id, 'attachments', null, 'memos'));
        $this->assertSame(
            RecordShowPanelQuery::UNUSED_NOTICE,
            $this->content($attached->id, 'attachments', null, 'memos')['notice'] ?? null,
        );
    }

    public function test_the_attachments_pane_of_a_held_record_carries_the_held_notice(): void
    {
        $record = Record::factory()->create(['record_module_id' => $this->module->id]);
        $this->registerRecordHolds([$record->id => 'workflow-case:1']);

        $notice = $this->content($record->id, 'attachments', null)['notice'] ?? null;

        $this->assertSame(RecordShowPanelQuery::HELD_NOTICE, $notice);
    }

    public function test_the_head_of_a_revision_has_the_actor_the_principal_of_a_proxy_and_the_origin(): void
    {
        [$applicant, $proxy] = [$this->user(), $this->user()];
        $recordId = $this->create(['title' => '企画'], $applicant);
        $this->update($recordId, ['title' => '改訂'], null);
        $this->update($recordId, ['title' => '承認版'], $applicant, new RecordRevisionOrigin('承認', '課長', $proxy->id));

        $rows = $this->content($recordId, 'revisions', null)['rows'] ?? [];

        $this->assertSame([
            [3, 'update', $proxy->id, $applicant->id, ['operation' => '承認', 'nodeName' => '課長']],
            [2, 'update', null, null, null],
            [1, 'create', $applicant->id, null, null],
        ], array_map(fn (array $row) => [
            $row['revision'], $row['action'],
            $row['actor']['id'] ?? null, $row['principal']['id'] ?? null, $row['origin'],
        ], $rows));
        $this->assertSame('2026-10-11T03:00:00+00:00', $rows[0]['at']);
        $this->assertSame([false, false, false], array_column($rows, 'noPreviousRecord'));
        $this->assertNull($rows[2]['changes']);
        $this->assertSame([['code' => 'title', 'before' => '改訂', 'after' => '承認版']], array_map(
            fn (array $change) => [
                'code' => $change['code'], 'before' => $change['before'], 'after' => $change['after'],
            ],
            $rows[0]['changes']['fields'] ?? [],
        ));
    }

    public function test_changes_list_fields_details_and_attachments_and_a_removed_file_can_be_opened(): void
    {
        $this->fakeUploadDisk();
        $this->limitUploads();
        $recordId = $this->create(['title' => '企画'], null, [['task_name' => '設計']]);
        [$row] = Record::query()->where('parent_record_id', $recordId)->pluck('id')->all();
        $rows = [new RecordDetailRowInput($row, ['task_name' => '基本設計'])];
        $files = [$this->uploadedFile('a.png', 'png'), $this->uploadedFile('b.txt', 'txt')];
        $this->update($recordId, ['title' => '改訂'], null, null, $rows, $files);
        [$png, $txt] = RecordAttachment::query()->where('record_id', $recordId)->orderBy('id')->get()->all();
        app(DetachRecordAttachments::class)->execute($this->contextFor(), 'projects', $recordId, $png->id);
        app(DetachRecordAttachments::class)->execute($this->contextFor(), 'projects', $recordId, $txt->id);
        Upload::query()->whereKey($txt->upload_id)->update(['purged_at' => now(), 'purge_due_on' => '2026-10-01']);

        [$purged, $removed, $mixed] = $this->content($recordId, 'revisions', null)['rows'] ?? [];

        $changes = $mixed['changes'] ?? [];
        $title = [
            'code' => 'title', 'name' => 'title', 'type' => 'string',
            'isMultiline' => false, 'referencedModule' => null,
        ];
        $this->assertSame([['title', $title]], array_map(
            fn (array $change) => [$change['code'], $change['field']],
            $changes['fields'] ?? [],
        ));
        $this->assertSame([['tasks', ['task_name'], [['changed', $row, ['task_name']]]]], array_map(fn ($detail) => [
            $detail['code'],
            array_column($detail['fields'], 'code'),
            array_map(
                fn (array $change) => [$change['change'], $change['rowId'], $change['changedCodes']],
                $detail['rows'],
            ),
        ], $changes['details'] ?? []));
        $this->assertSame([['added', 'a.png', null], ['added', 'b.txt', null]], $this->attachmentChanges($mixed));
        $this->assertSame([['removed', 'a.png', $png->upload_id]], $this->attachmentChanges($removed));
        $file = $removed['changes']['attachments'][0];
        $this->assertSame(['image/png', true, null], [$file['mime'], $file['previewable'], $file['purgeDueOn']]);
        $this->assertSame([['removed', 'b.txt', null]], $this->attachmentChanges($purged));
        $this->assertSame('2026-10-01', $purged['changes']['attachments'][0]['purgeDueOn'] ?? null);
    }

    public function test_the_first_update_revision_of_a_record_without_revisions_has_no_previous_record(): void
    {
        $record = Record::factory()->create(['record_module_id' => $this->module->id]);
        $this->update($record->id, ['title' => '初め'], null);

        [$row] = $this->content($record->id, 'revisions', null)['rows'] ?? [];

        $this->assertSame(['update', true, null], [$row['action'], $row['noPreviousRecord'], $row['changes']]);
    }

    public function test_revisions_are_read_five_at_a_time_and_the_last_row_of_a_page_still_has_its_changes(): void
    {
        $recordId = $this->create(['title' => 'v1'], null);

        foreach (range(2, 7) as $version) {
            $this->update($recordId, ['title' => "v{$version}"], null);
        }

        $first = $this->content($recordId, 'revisions', null);
        $rest = $this->content($recordId, 'revisions', $first['rows'][4]['id'] ?? null);

        $this->assertSame([7, 6, 5, 4, 3], array_column($first['rows'] ?? [], 'revision'));
        $this->assertTrue($first['hasMore'] ?? null);
        $this->assertSame('v2', $first['rows'][4]['changes']['fields'][0]['before'] ?? null);
        $this->assertSame([[2, 1], false], [array_column($rest['rows'] ?? [], 'revision'), $rest['hasMore'] ?? null]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function content(int $recordId, string $key, ?int $beforeId, string $moduleCode = 'projects'): ?array
    {
        return app(RecordShowPanelQuery::class)->content($this->contextFor(), $moduleCode, $recordId, $key, $beforeId);
    }

    /**
     * @return list<array{string, int|null}>
     */
    private function items(string $moduleCode, int $recordId): array
    {
        $panel = app(RecordShowPanelQuery::class)->panel($this->contextFor(), $moduleCode, $recordId);

        return array_map(fn (array $item) => [$item['key'], $item['count']], $panel['panel']['items'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array{mixed, mixed, mixed}>
     */
    private function attachmentChanges(array $row): array
    {
        return array_values(array_map(
            fn (array $change) => [$change['change'], $change['name'], $change['uploadId']],
            $row['changes']['attachments'] ?? [],
        ));
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<array<string, mixed>>  $details
     */
    private function create(array $values, ?User $user, array $details = []): int
    {
        $rows = array_map(fn (array $row) => new RecordDetailRowInput(null, $row), $details);

        $input = new RecordInput($values, ['tasks' => $rows]);

        return app(CreateRecord::class)->execute($this->contextFor($user?->id), 'projects', $input);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<RecordDetailRowInput>|null  $rows
     * @param  list<UploadedFile>  $files
     */
    private function update(
        int $recordId,
        array $values,
        ?User $user,
        ?RecordRevisionOrigin $origin = null,
        ?array $rows = null,
        array $files = [],
    ): void {
        $input = new RecordInput($values, $rows === null ? [] : ['tasks' => $rows], $files);
        $version = Record::query()->findOrFail($recordId)->version();
        $context = $this->contextFor($user?->id);
        app(UpdateRecord::class)->execute($context, 'projects', $recordId, $input, $version, origin: $origin);
    }
}
