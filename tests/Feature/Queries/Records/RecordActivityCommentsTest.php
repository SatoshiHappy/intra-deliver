<?php

namespace Tests\Feature\Queries\Records;

use App\Actions\Records\DeleteRecordComment;
use App\Models\Records\Record;
use App\Queries\Records\Api\RecordActivityQuery;
use App\Queries\Records\Api\RecordShowPanelQuery;
use App\Queries\Records\RecordCommentQuery;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsRecordComments;
use Tests\Support\TransactionalTestCase;

/**
 * コメントの一覧をページの番号で読む口(docs の 設計/ワークフロー/業務データとの接続/公開の口/表示と関係の口.md の 7.3 の表の
 * RecordActivityQuery::comments の行): 新しい順のページの行はサイドパネルのコメントの欄と同じ中身から今の利用者に依る mine を除いたもの。
 * 削除の印の行も 1 件に数え、総数は帯の件数と同じ。主体の判定をしない(主体を受けない)。
 */
final class RecordActivityCommentsTest extends TransactionalTestCase
{
    use BuildsRecordComments;

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

    public function test_comment_pages_have_the_rows_of_the_side_panel_without_mine_counting_deleted_marks(): void
    {
        $fixture = $this->rowAccessFixture();
        $creator = $fixture['people']['creator'];
        $mentions = [$fixture['people']['attendee']->id];
        $ids = array_map(
            fn (int $number) => $this->postComment($creator->id, $fixture['recordId'], "本文 {$number}", $mentions),
            range(1, 7),
        );
        app(DeleteRecordComment::class)
            ->byAuthor($this->contextFor($creator->id), 'meetings', $fixture['recordId'], $ids[5]);
        $panel = fn (?int $beforeId) => app(RecordShowPanelQuery::class)
            ->content($this->contextFor($creator->id), 'meetings', $fixture['recordId'], 'comments', $beforeId);
        $first = $panel(null);
        $rest = $panel($ids[2]);

        $pageOne = $this->activity()->comments($fixture['recordId'], 1, 5, self::day(self::TODAY), 'ja');
        $pageTwo = $this->activity()->comments($fixture['recordId'], 2, 5, self::day(self::TODAY), 'ja');

        $this->assertSame(self::withoutMine($first['rows'] ?? []), $pageOne['rows']);
        $this->assertSame(self::withoutMine($rest['rows'] ?? []), $pageTwo['rows']);
        $this->assertSame([7, 1, 2], [$pageOne['total'], $pageOne['currentPage'], $pageOne['lastPage']]);
        $this->assertSame(app(RecordCommentQuery::class)->countFor($fixture['recordId']), $pageOne['total']);
        $this->assertNotNull($pageOne['rows'][1]['deleted']);
        $keys = ['id', 'author', 'body', 'createdAt', 'mentions', 'deleted'];
        $this->assertSame($keys, array_keys($pageOne['rows'][0]));
    }

    #[DataProvider('pages')]
    public function test_comment_pages_are_clamped_into_the_pages(int $page, int $current): void
    {
        $fixture = $this->rowAccessFixture();
        $ids = array_reverse(array_map(
            fn () => $this->postComment($fixture['people']['creator']->id, $fixture['recordId']),
            range(1, 7),
        ));

        $read = $this->activity()->comments($fixture['recordId'], $page, 3, self::day(self::TODAY), 'ja');

        $this->assertSame([$current, 3, 7], [$read['currentPage'], $read['lastPage'], $read['total']]);
        $this->assertSame(array_slice($ids, ($current - 1) * 3, 3), array_column($read['rows'], 'id'));
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
        $fixture = $this->rowAccessFixture();
        $ids = array_reverse(array_map(
            fn () => $this->postComment($fixture['people']['creator']->id, $fixture['recordId']),
            range(1, 4),
        ));

        $first = $this->activity()->comments($fixture['recordId'], 1, $perPage, self::day(self::TODAY), 'ja');
        $next = $this->activity()->comments($fixture['recordId'], 2, $perPage, self::day(self::TODAY), 'ja');

        $this->assertSame([1, $last, 4], [$first['currentPage'], $first['lastPage'], $first['total']]);
        $this->assertSame(array_slice($ids, 0, min($perPage, 4)), array_column($first['rows'], 'id'));
        $this->assertSame([$second, $last], [$next['currentPage'], $next['lastPage']]);
        $this->assertSame(array_slice($ids, $skipped, min($perPage, 4)), array_column($next['rows'], 'id'));
    }

    public function test_an_empty_comment_list_is_the_first_page_and_one_a_page_reads_one(): void
    {
        $fixture = $this->rowAccessFixture();
        $empty = $this->activity()->comments($fixture['recordId'], 2, 5, self::day(self::TODAY), 'ja');
        $id = $this->postComment($fixture['people']['creator']->id, $fixture['recordId']);
        $this->postComment($fixture['people']['creator']->id, $fixture['recordId']);
        $one = $this->activity()->comments($fixture['recordId'], 2, 1, self::day(self::TODAY), 'ja');

        $this->assertSame([[], 0, 1, 1], [$empty['rows'], $empty['total'], $empty['currentPage'], $empty['lastPage']]);
        $this->assertSame([[$id], 2, 2], [array_column($one['rows'], 'id'), $one['currentPage'], $one['lastPage']]);
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

        $this->activity()->comments($recordId, 1, 5, self::day(self::TODAY), 'ja');
    }

    public function test_less_than_one_a_page_is_a_logic_error(): void
    {
        $fixture = $this->rowAccessFixture();

        $this->expectException(LogicException::class);

        $this->activity()->comments($fixture['recordId'], 1, 0, self::day(self::TODAY), 'ja');
    }

    public function test_reads_without_writes_or_locks(): void
    {
        $fixture = $this->rowAccessFixture();
        $mentions = [$fixture['people']['attendee']->id];
        $this->postComment($fixture['people']['creator']->id, $fixture['recordId'], mentions: $mentions);

        $this->assertReadsWithoutLocks(
            fn () => $this->activity()->comments($fixture['recordId'], 1, 5, self::day(self::TODAY), 'ja'),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function withoutMine(array $rows): array
    {
        return array_map(function (array $row): array {
            unset($row['mine']);

            return $row;
        }, $rows);
    }

    private function activity(): RecordActivityQuery
    {
        return app(RecordActivityQuery::class);
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
