<?php

namespace Tests\Feature\Ai;

use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordComment;
use App\Queries\Ai\Api\RecordMarkers;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsUploads;
use Tests\Support\TransactionalTestCase;

/**
 * 本物の読むが返す範囲(docs の 設計/AI/道具と権限/README.md 10.2 の読むが返す範囲・ページの頭打ち、表 1 の B4 =
 * 設計/AI/道具と権限/道具の判定と今の権限.md 10.3): 項目の値(コード・名前・値)・明細の行・作成と更新・参照制限・コメント(1 ページ 20 件)・
 * 更新履歴(1 ページ 10 件。差分の見えない参照先は #<id>)・添付(名前・形式・大きさだけ。100 件まで)。
 */
final class ReadRecordContentsTest extends TransactionalTestCase
{
    use BuildsToolRecords;
    use BuildsUploads;
    use RunsRecordTools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00', 'Asia/Tokyo'));
        $this->arrangeRequester();
        $this->arrangeTasks();
        $this->logRecordReads();
        $this->startRecordToolRun(['m_tasks']);
    }

    public function test_the_values_details_and_row_access_of_the_record(): void
    {
        $viewers = [['type' => 'user', 'id' => $this->requester->id]];
        $values = ['title' => '件名', 'status' => 'open', 'amount' => 7];
        $id = $this->task($values, ['品目 1', '品目 2'], true, $viewers);

        $content = $this->callTool('ai_read_record', ['module' => 'm_tasks', 'id' => $id]);

        $body = self::body($content);
        $this->assertSame([RecordMarkers::of('m_tasks', $id), $id], [$body['record'], $body['id']]);
        $named = array_map(
            fn (array $value): array => [$value['code'], $value['name'], $value['value']['code'] ?? $value['value']],
            array_slice($body['values'], 0, 3),
        );
        $this->assertSame([['title', '件名', '件名'], ['status', '状態', 'open'], ['amount', '金額', 7]], $named);
        $this->assertSame(['lines', '明細'], [$body['details'][0]['code'], $body['details'][0]['name']]);
        $items = array_map(fn (array $row) => $row['values'][0]['value'], $body['details'][0]['rows']);
        $this->assertSame(['品目 1', '品目 2'], $items);
        $rowAccess = $body['rowAccess'];
        $this->assertSame([true, $this->requester->id], [$rowAccess['restricted'], $rowAccess['viewers'][0]['id']]);
        $this->assertSame($this->writer->id, $body['createdBy']['id']);
        $this->assertEquals([['module' => 'm_tasks', 'id' => $id]], $this->toolRow()->records);
        $calls = ['canView', 'detail', 'comments', 'revisions', 'forRecord'];
        $operations = array_map(fn (string $call): string => explode('::', $call)[1] ?? '', $this->reads->calls);
        $this->assertSame($calls, $operations);
    }

    /**
     * コメント(21 件の 1 件は削除の印)の 1 ページ目と 2 ページ目。各行 = ページ・行の数・今のページ。
     *
     * @return array<string, array{int, int, int}>
     */
    public static function commentPages(): array
    {
        return ['1 ページ目は 20 件' => [1, 20, 1], '2 ページ目は 1 件' => [2, 1, 2]];
    }

    #[DataProvider('commentPages')]
    public function test_comments_have_twenty_a_page_with_the_total(int $page, int $rows, int $current): void
    {
        $id = $this->task(['title' => 'コメントあり']);
        $comment = ['record_module_id' => $this->tasks->id, 'record_id' => $id, 'user_id' => $this->writer->id];
        RecordComment::factory()->deleted()->create($comment);
        RecordComment::factory()->count(20)->create($comment);

        $arguments = ['module' => 'm_tasks', 'id' => $id, 'comments_page' => $page];
        $comments = self::body($this->callTool('ai_read_record', $arguments))['comments'];

        $this->assertCount($rows, $comments['rows']);
        $this->assertSame([21, $current, 2], [$comments['total'], $comments['currentPage'], $comments['lastPage']]);
    }

    /** 更新履歴(11 版)は 1 ページ 10 件と総数。差分の値の見えない参照先は #<id> */
    public function test_revisions_have_ten_a_page_and_hide_a_hidden_reference(): void
    {
        $id = $this->task(['title' => 'v1']);

        foreach (range(2, 10) as $version) {
            $this->updateTask($id, ['title' => "v{$version}"]);
        }

        $refId = $this->refRecord();
        $this->updateTask($id, ['ref' => $refId]);

        $arguments = ['module' => 'm_tasks', 'id' => $id];
        $first = self::body($this->callTool('ai_read_record', $arguments))['revisions'];
        $next = [...$arguments, 'revisions_page' => 2];
        $second = self::body($this->callTool('ai_read_record', $next, 'c2'))['revisions'];

        $pages = [count($first['rows']), $first['total'], $first['currentPage'], $first['lastPage']];
        $this->assertSame([10, 11, 1, 2], $pages);
        $this->assertSame([1, 2], [count($second['rows']), $second['currentPage']]);
        $this->assertSame("#{$refId}", $first['rows'][0]['changes']['fields'][0]['after']['label'] ?? null);
    }

    /** 更新履歴の差分の添付の変化も、添付の一覧と同じく名前と形式だけ(実体の id・開けるか・消す日を入れない) */
    public function test_an_attachment_change_in_the_revisions_is_the_name_and_the_type_only(): void
    {
        $this->fakeUploadDisk();
        $this->limitUploads();
        $id = $this->task(['title' => '添付を付ける']);
        $version = Record::query()->findOrFail($id)->version();
        $input = new RecordInput([], [], [$this->uploadedFile('a.png', 'png')]);
        app(UpdateRecord::class)->execute($this->contextFor($this->writer->id), 'm_tasks', $id, $input, $version);

        $arguments = ['module' => 'm_tasks', 'id' => $id];
        $revisions = self::body($this->callTool('ai_read_record', $arguments))['revisions'];

        $change = $revisions['rows'][0]['changes']['attachments'][0] ?? [];
        $this->assertSame(['change', 'name', 'mime'], array_keys($change));
        $this->assertSame(['added', 'a.png'], [$change['change'], $change['name']]);

        foreach (['uploadId', 'previewable', 'purgeDueOn'] as $key) {
            $this->assertArrayNotHasKey($key, $change);
        }
    }

    /**
     * 添付の数(上限 100 の境)と残りの有無。
     *
     * @return array<string, array{int, bool}>
     */
    public static function attachmentCounts(): array
    {
        return ['100 件は残り無し' => [100, false], '101 件は残りあり' => [101, true]];
    }

    #[DataProvider('attachmentCounts')]
    public function test_attachments_are_the_name_the_type_and_the_size_up_to_a_hundred(int $count, bool $more): void
    {
        $id = $this->task(['title' => '添付あり']);
        $attachment = ['record_module_id' => $this->tasks->id, 'record_id' => $id];
        RecordAttachment::factory()->count($count)->create($attachment);

        $arguments = ['module' => 'm_tasks', 'id' => $id];
        $attachments = self::body($this->callTool('ai_read_record', $arguments))['attachments'];

        $this->assertSame([100, $more], [count($attachments['items']), $attachments['hasMore']]);
        $this->assertSame(['name', 'mime', 'size'], array_keys($attachments['items'][0]));
        $this->assertSame('application/pdf', $attachments['items'][0]['mime']);
    }
}
