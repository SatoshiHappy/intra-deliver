<?php

namespace Tests\Feature\Queries\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Actions\Records\DetachRecordAttachments;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordActivityQuery;
use App\Queries\Records\Api\RecordShowPanelQuery;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsRowAccessRecords;
use Tests\Support\BuildsUploads;
use Tests\Support\TransactionalTestCase;

/**
 * 更新履歴の一覧をページの番号で読む口(docs の 設計/ワークフロー/業務データとの接続/公開の口/表示と関係の口.md の 7.3 の表の
 * RecordActivityQuery::revisions の行): 新しい版からのページの行はサイドパネルの更新履歴の欄と同じ中身からファイルを開く URL を除いたもの。
 * ページの最後の行も 1 つ前の版との差分を持つ。主体の判定をしない(差分の参照の表示名だけが判定を求める者で変わる)。
 */
final class RecordActivityRevisionsTest extends TransactionalTestCase
{
    use BuildsRowAccessRecords;
    use BuildsUploads;

    /**
     * ページ(7 件・3 件ずつ = 3 ページ): 1 未満は 1、最後のページより後は最後のページ。
     *
     * @return array<string, array{int, int}>
     */
    public static function pages(): array
    {
        return [
            '0 は 1' => [0, 1],
            '1 ページ目' => [1, 1],
            '2(最後の前)' => [2, 2],
            '3(最後)' => [3, 3],
            '4(最後の後)は最後' => [4, 3],
        ];
    }

    public function test_revision_pages_have_the_rows_of_the_side_panel_without_the_file_links(): void
    {
        $this->fakeUploadDisk();
        $this->limitUploads();
        $this->projects();
        $recordId = $this->createProject('v1');

        foreach (range(2, 6) as $version) {
            $this->updateProject($recordId, "v{$version}");
        }

        $this->updateProject($recordId, 'v7', [$this->uploadedFile('a.png', 'png')]);
        $attachment = RecordAttachment::query()->where('record_id', $recordId)->sole();
        app(DetachRecordAttachments::class)->execute($this->contextFor(), 'projects', $recordId, $attachment->id);
        $panel = fn (?int $beforeId) => app(RecordShowPanelQuery::class)
            ->content($this->contextFor(), 'projects', $recordId, 'revisions', $beforeId)['rows'] ?? [];
        $first = $panel(null);

        $pageOne = $this->revisions(new Requester(null, null), $recordId, 1, 5);
        $pageTwo = $this->revisions(new Requester(null, null), $recordId, 2, 5);

        $this->assertSame(self::withoutFileLinks($first), $pageOne['rows']);
        $this->assertSame(self::withoutFileLinks($panel($first[4]['id'])), $pageTwo['rows']);
        $this->assertSame([8, 1, 2], [$pageOne['total'], $pageOne['currentPage'], $pageOne['lastPage']]);
        $this->assertSame([8, 7, 6, 5, 4], array_column($pageOne['rows'], 'revision'));
        $this->assertSame('v3', $pageOne['rows'][4]['changes']['fields'][0]['before'] ?? null);
        $this->assertSame($attachment->upload_id, $pageOne['rows'][0]['changes']['attachments'][0]['uploadId'] ?? null);
        $this->assertArrayNotHasKey('downloadUrl', $pageOne['rows'][0]['changes']['attachments'][0] ?? []);
    }

    #[DataProvider('pages')]
    public function test_revision_pages_are_clamped_into_the_pages(int $page, int $current): void
    {
        $this->projects();
        $recordId = $this->createProject('v1');

        foreach (range(2, 7) as $version) {
            $this->updateProject($recordId, "v{$version}");
        }

        $read = $this->revisions(new Requester(null, null), $recordId, $page, 3);

        $this->assertSame([$current, 3, 7], [$read['currentPage'], $read['lastPage'], $read['total']]);
        $expected = array_slice([7, 6, 5, 4, 3, 2, 1], ($current - 1) * 3, 3);
        $this->assertSame($expected, array_column($read['rows'], 'revision'));
        // ページの最後の行の差分の前の版も読む(最後のページは 1 件だけ)
        $before = $read['rows'][2]['changes']['fields'][0]['before'] ?? null;
        $this->assertSame($current === 3 ? null : 'v'.(7 - $current * 3), $before);
    }

    /**
     * 1 ページの件数の境(件数 4 に対して 1・2・件数 - 1・件数・件数 + 1・整数の最大。1 未満の 0 は作りの誤りの別のテスト)。
     * 各行 = 1 ページの件数・最後のページ・2 ページ目を求めたときのページ・そのページの前に飛ばす件数。
     *
     * @return array<string, array{int, int, int, int}>
     */
    public static function perPages(): array
    {
        return [
            '1 件' => [1, 4, 2, 1],
            '2 件' => [2, 2, 2, 2],
            '件数 - 1' => [3, 2, 2, 3],
            '件数' => [4, 1, 1, 0],
            '件数 + 1' => [5, 1, 1, 0],
            '整数の最大' => [PHP_INT_MAX, 1, 1, 0],
        ];
    }

    #[DataProvider('perPages')]
    public function test_any_number_a_page_from_one_up_to_the_largest_integer_gives_the_right_page(
        int $perPage,
        int $last,
        int $second,
        int $skipped,
    ): void {
        $this->projects();
        $recordId = $this->createProject('v1');
        array_map(fn (int $version) => $this->updateProject($recordId, "v{$version}"), range(2, 4));
        $ids = [4, 3, 2, 1];

        $first = $this->revisions(new Requester(null, null), $recordId, 1, $perPage);
        $next = $this->revisions(new Requester(null, null), $recordId, 2, $perPage);

        $this->assertSame([1, $last, 4], [$first['currentPage'], $first['lastPage'], $first['total']]);
        $this->assertSame(array_slice($ids, 0, min($perPage, 4)), array_column($first['rows'], 'revision'));
        $this->assertSame([$second, $last], [$next['currentPage'], $next['lastPage']]);
        $this->assertSame(array_slice($ids, $skipped, min($perPage, 4)), array_column($next['rows'], 'revision'));
    }

    public function test_an_empty_revision_list_is_the_first_page_and_one_a_page_reads_one(): void
    {
        $this->projects();
        $empty = Record::factory()->create(['record_module_id' => $this->moduleId('projects')])->id;
        $recordId = $this->createProject('v1');
        $this->updateProject($recordId, 'v2');

        $none = $this->revisions(new Requester(null, null), $empty, 3, 5);
        $one = $this->revisions(new Requester(null, null), $recordId, 1, 1);

        $this->assertSame([[], 0, 1, 1], [$none['rows'], $none['total'], $none['currentPage'], $none['lastPage']]);
        $this->assertSame([[2], 2], [array_column($one['rows'], 'revision'), $one['lastPage']]);
        $this->assertSame('v1', $one['rows'][0]['changes']['fields'][0]['before'] ?? null);
    }

    /**
     * 主体の判定をしない: 閲覧できない人・ゲストでも同じ行を返し、差分の参照の表示名だけが判定を求める者で変わる(見えない参照先は #<id>)。
     */
    public function test_revisions_judge_no_subject_but_hide_the_label_of_a_hidden_reference(): void
    {
        $fixture = $this->rowAccessFixture();
        ['attendee' => $attendee, 'stranger' => $stranger] = $fixture['people'];
        $notes = $this->recordModule('notes');
        $this->referenceField($notes, 'meeting', $fixture['module']);
        $noteId = app(CreateRecord::class)->execute($this->contextFor($attendee->id), 'notes', new RecordInput([]));
        $version = Record::query()->findOrFail($noteId)->version();
        $input = new RecordInput(['meeting' => $fixture['recordId']]);
        app(UpdateRecord::class)->execute($this->contextFor($attendee->id), 'notes', $noteId, $input, $version);

        $asAttendee = $this->revisions(new Requester($attendee->id, null), $noteId, 1, 5)['rows'];
        $asStranger = $this->revisions(new Requester($stranger->id, null), $noteId, 1, 5)['rows'];
        $hiddenMeeting = [
            $this->revisions(new Requester($stranger->id, null), $fixture['recordId'], 1, 5),
            $this->revisions(new Requester(null, null), $fixture['recordId'], 1, 5),
        ];

        $this->assertSame('定例', $asAttendee[0]['changes']['fields'][0]['after']['label'] ?? null);
        $hidden = ['id' => $fixture['recordId'], 'code' => null, 'label' => null, 'affiliation' => null];
        $this->assertSame($hidden, $asStranger[0]['changes']['fields'][0]['after'] ?? null);
        $this->assertSame(array_column($asAttendee, 'id'), array_column($asStranger, 'id'));
        $visibleMeeting = $this->revisions(new Requester($attendee->id, null), $fixture['recordId'], 1, 5);
        $this->assertSame($visibleMeeting, $hiddenMeeting[0]);
        $this->assertSame($hiddenMeeting[0], $hiddenMeeting[1]);
        $this->assertSame(1, $hiddenMeeting[0]['total']);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function missingRecords(): array
    {
        return ['無いレコード' => [false], '明細の行' => [true]];
    }

    #[DataProvider('missingRecords')]
    public function test_a_missing_record_or_a_detail_row_is_not_found(bool $detailRow): void
    {
        $fixture = $this->rowAccessFixture();
        $recordId = $detailRow
            ? (int) Record::query()->where('parent_record_id', $fixture['recordId'])->value('id')
            : (int) Record::query()->max('id') + 1;

        $this->expectException(ModelNotFoundException::class);

        $this->revisions(new Requester(null, null), $recordId, 1, 5);
    }

    public function test_less_than_one_a_page_is_a_logic_error(): void
    {
        $fixture = $this->rowAccessFixture();

        $this->expectException(LogicException::class);

        $this->revisions(new Requester(null, null), $fixture['recordId'], 1, 0);
    }

    public function test_reads_without_writes_or_locks(): void
    {
        $this->projects();
        $recordId = $this->createProject('v1');
        $this->updateProject($recordId, 'v2');

        $this->assertReadsWithoutLocks(fn () => $this->revisions(new Requester(null, null), $recordId, 1, 5));
    }

    /**
     * サイドパネルの更新履歴の行から、添付の変化の行に足したファイルを開く URL を除く。
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function withoutFileLinks(array $rows): array
    {
        return array_map(function (array $row): array {
            if (is_array($row['changes'] ?? null)) {
                $row['changes']['attachments'] = array_map(function (array $change): array {
                    unset($change['downloadUrl'], $change['previewUrl']);

                    return $change;
                }, $row['changes']['attachments']);
            }

            return $row;
        }, $rows);
    }

    /**
     * 業務モジュール projects(項目 title)。
     */
    private function projects(): void
    {
        $this->field($this->recordModule('projects'), 'title');
    }

    private function createProject(string $title): int
    {
        return app(CreateRecord::class)->execute($this->contextFor(), 'projects', new RecordInput(['title' => $title]));
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function updateProject(int $recordId, string $title, array $files = []): void
    {
        $version = Record::query()->findOrFail($recordId)->version();
        $input = new RecordInput(['title' => $title], [], $files);
        app(UpdateRecord::class)->execute($this->contextFor(), 'projects', $recordId, $input, $version);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, currentPage: int, lastPage: int}
     */
    private function revisions(Requester $requester, int $recordId, int $page, int $perPage): array
    {
        return $this->activity()->revisions($requester, $recordId, $page, $perPage, self::day(self::TODAY), 'ja');
    }

    private function activity(): RecordActivityQuery
    {
        return app(RecordActivityQuery::class);
    }

    private function moduleId(string $code): int
    {
        return RecordModule::query()->where('code', $code)->sole()->id;
    }

    /**
     * 呼んだ後までに流れた SQL(小文字)が、読み取りの文だけで、行の鍵と勧告ロックを取らないこと。
     */
    private function assertReadsWithoutLocks(Closure $action): void
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });
        $action();

        $this->assertNotSame([], $statements);

        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/^\s*(select|with)\b/', $sql);
            $this->assertStringNotContainsString('for update', $sql);
            $this->assertStringNotContainsString('for share', $sql);
            $this->assertStringNotContainsString('pg_advisory', $sql);
        }
    }
}
