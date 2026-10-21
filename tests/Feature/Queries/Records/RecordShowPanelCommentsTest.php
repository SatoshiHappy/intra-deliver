<?php

namespace Tests\Feature\Queries\Records;

use App\Actions\Records\DeleteRecordComment;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Records\Api\RecordShowPanelQuery;
use Tests\Support\BuildsRecordComments;
use Tests\Support\TransactionalTestCase;

/**
 * サイドパネルのコメントの欄(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 7 節、詳細の画面/README.md の 2 節・6 節):
 * 帯の項目(添付ファイルと更新履歴の間・件数は削除の印も数える)と、開いた欄の中身(新しい順の 5 件と続き・削除の印の行・書いた人・宛先・
 * 自分の分・書けるか・ほかの人の分も消せるか)。見える範囲が「関係する人だけ」の業務モジュール meetings と、初期データのプロジェクトの
 * 見本のコメントで確かめる。
 */
final class RecordShowPanelCommentsTest extends TransactionalTestCase
{
    use BuildsRecordComments;

    public function test_the_band_has_the_comments_pane_between_attachments_and_revisions_counting_deleted_marks(): void
    {
        $fixture = $this->rowAccessFixture();
        $creator = $fixture['people']['creator'];
        $context = $this->contextFor($creator->id);
        $this->postComment($creator->id, $fixture['recordId']);
        $deleted = $this->postComment($creator->id, $fixture['recordId']);
        app(DeleteRecordComment::class)->byAuthor($context, 'meetings', $fixture['recordId'], $deleted);

        $panel = app(RecordShowPanelQuery::class)->panel($context, 'meetings', $fixture['recordId']);

        $items = $panel['panel']['items'] ?? [];
        $this->assertSame(['attachments', 'comments', 'revisions'], array_column($items, 'key'));
        $this->assertSame(
            ['key' => 'comments', 'name' => 'コメント', 'shortName' => 'コメント', 'count' => 2, 'kind' => 'comments'],
            $items[1] ?? null,
        );
    }

    public function test_the_content_gives_the_newest_five_then_the_rest_after_before(): void
    {
        $fixture = $this->rowAccessFixture();
        $creator = $fixture['people']['creator'];
        $ids = array_map(
            fn (int $number) => $this->postComment($creator->id, $fixture['recordId'], "本文 {$number}"),
            range(1, 7),
        );

        $first = $this->content($creator->id, $fixture['recordId']);
        $rest = $this->content($creator->id, $fixture['recordId'], $ids[2]);

        $this->assertSame(array_reverse(array_slice($ids, 2)), array_column($first['rows'] ?? [], 'id'));
        $this->assertTrue($first['hasMore'] ?? null);
        $this->assertSame([$ids[1], $ids[0]], array_column($rest['rows'] ?? [], 'id'));
        $this->assertFalse($rest['hasMore'] ?? null);
    }

    public function test_a_row_has_the_author_the_body_the_mentions_in_order_and_whether_it_is_mine(): void
    {
        $fixture = $this->rowAccessFixture();
        ['creator' => $creator, 'attendee' => $attendee, 'member' => $member] = $fixture['people'];
        $this->postComment($creator->id, $fixture['recordId'], "一行目\n<b>二行目</b>", [$member->id, $attendee->id]);

        $mine = $this->content($creator->id, $fixture['recordId']);
        $others = $this->content($attendee->id, $fixture['recordId']);

        [$row] = $mine['rows'] ?? [];
        $this->assertSame("一行目\n<b>二行目</b>", $row['body']);
        $this->assertSame($creator->id, $row['author']['id'] ?? null);
        $this->assertSame([$member->id, $attendee->id], array_column($row['mentions'], 'id'));
        $this->assertNull($row['deleted']);
        $this->assertIsString($row['createdAt']);
        $this->assertTrue($row['mine']);
        $this->assertFalse($others['rows'][0]['mine'] ?? null);
        $this->assertTrue($mine['canPost'] ?? null);
        $this->assertFalse($mine['canDeleteOthers'] ?? null);
    }

    public function test_a_deleted_row_has_no_body_author_or_mentions_but_who_deleted_it_and_when(): void
    {
        $fixture = $this->rowAccessFixture();
        ['creator' => $creator, 'attendee' => $attendee] = $fixture['people'];
        $id = $this->postComment($creator->id, $fixture['recordId'], '消す', [$attendee->id]);
        app(DeleteRecordComment::class)->byAdministrator($this->contextFor($attendee->id), $id);

        [$row] = $this->content($creator->id, $fixture['recordId'])['rows'] ?? [];

        $this->assertSame($id, $row['id']);
        $this->assertNull($row['body']);
        $this->assertNull($row['author']);
        $this->assertSame([], $row['mentions']);
        $this->assertSame($attendee->id, $row['deleted']['by']['id'] ?? null);
        $this->assertIsString($row['deleted']['at'] ?? null);
        $this->assertFalse($row['mine']);
    }

    public function test_the_guest_reads_but_cannot_post_and_an_unrelated_person_gets_nothing(): void
    {
        $fixture = $this->rowAccessFixture();
        $watcher = $fixture['people']['watcher'];
        $this->postComment($fixture['people']['creator']->id, $fixture['recordId']);
        $notes = $this->recordModule('notes');
        $noteId = Record::factory()->create(['record_module_id' => $notes->id])->id;
        $this->postComment($this->user()->id, $noteId, module: 'notes');

        $guest = app(RecordShowPanelQuery::class)->content($this->contextFor(), 'notes', $noteId, 'comments', null);

        $this->assertCount(1, $guest['rows'] ?? []);
        $this->assertFalse($guest['canPost'] ?? null);
        $this->assertFalse($guest['canDeleteOthers']);
        $this->assertFalse($guest['rows'][0]['mine'] ?? null);
        $this->assertNull($this->content($watcher->id, $fixture['recordId']));
        $watcherContext = $this->contextFor($watcher->id);
        $hidden = app(RecordShowPanelQuery::class)->panel($watcherContext, 'meetings', $fixture['recordId']);
        $this->assertNull($hidden);
    }

    public function test_the_seeded_project_has_three_comments_and_only_the_administrator_can_delete_others(): void
    {
        $module = RecordModule::query()->where('code', 'project')->sole();
        $recordId = Record::query()->where('record_module_id', $module->id)->orderBy('id')->firstOrFail()->id;
        $administrator = User::query()->where('code', 'u0004')->sole();
        $other = User::query()->where('code', 'u0001')->sole();
        $writer = User::query()->where('code', 'u0012')->sole();

        $asAdministrator = $this->content($administrator->id, $recordId, null, 'project');
        $asOther = $this->content($other->id, $recordId, null, 'project');
        $asWriter = $this->content($writer->id, $recordId, null, 'project');

        $this->assertSame([false, true, false], array_column($asAdministrator['rows'] ?? [], 'mine'));
        $this->assertTrue($asAdministrator['canPost'] ?? null);
        $this->assertTrue($asAdministrator['canDeleteOthers'] ?? null);
        $this->assertSame([false, false, false], array_column($asOther['rows'] ?? [], 'mine'));
        $this->assertTrue($asOther['canPost'] ?? null);
        $this->assertFalse($asOther['canDeleteOthers'] ?? null);
        $this->assertSame([true, false, false], array_column($asWriter['rows'] ?? [], 'mine'));
        $this->assertSame(
            [['u0004'], ['u0012', 'u0011'], []],
            array_map(fn (array $row) => array_column($row['mentions'], 'code'), $asOther['rows'] ?? []),
        );
        $panel = app(RecordShowPanelQuery::class)->panel($this->contextFor($administrator->id), 'project', $recordId);
        $this->assertSame(3, $panel['panel']['items'][1]['count'] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function content(int $userId, int $recordId, ?int $beforeId = null, string $module = 'meetings'): ?array
    {
        $context = $this->contextFor($userId);

        return app(RecordShowPanelQuery::class)->content($context, $module, $recordId, 'comments', $beforeId);
    }
}
