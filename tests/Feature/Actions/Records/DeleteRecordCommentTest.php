<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\DeleteRecordComment;
use App\Enums\Records\RecordModuleAction;
use App\Models\Notice\Notice;
use App\Models\Records\Record;
use App\Models\Records\RecordComment;
use App\Models\Records\RecordCommentMention;
use Tests\Support\BuildsRecordComments;
use Tests\Support\TransactionalTestCase;

/**
 * コメントを消す業務処理 DeleteRecordComment(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 4 節、9 節の「削除」の業務処理の分。
 * ルートの認可・戻り先は RecordCommentControllerTest): 本人の経路は 業務モジュール 404 → 閲覧 403 → レコード 404 → コメント 404 → 本人でない 403、
 * 管理の経路は業務モジュールを見ない。消すと本文と宛先の行が消え、削除した日時と人が残り、送った通知は残る。
 */
final class DeleteRecordCommentTest extends TransactionalTestCase
{
    use BuildsRecordComments;

    public function test_the_author_deletes_leaving_a_mark_removing_the_mentions_but_not_the_notices(): void
    {
        $fixture = $this->rowAccessFixture();
        [$creator, $attendee] = [$fixture['people']['creator'], $fixture['people']['attendee']];
        $id = $this->postComment($creator->id, $fixture['recordId'], '消す本文', [$attendee->id]);

        $this->deleteOwn($creator->id, $fixture['recordId'], $id);

        $comment = RecordComment::query()->findOrFail($id);
        $this->assertNull($comment->body);
        $this->assertNotNull($comment->deleted_at);
        $this->assertSame($creator->id, $comment->deleted_by_user_id);
        $this->assertSame($creator->id, $comment->user_id);
        $this->assertSame(0, RecordCommentMention::query()->where('record_comment_id', $id)->count());
        $this->assertSame(1, Notice::query()->where('user_id', $attendee->id)->count());
    }

    public function test_a_second_delete_and_a_comment_of_another_record_are_404_and_a_non_author_is_403(): void
    {
        $fixture = $this->rowAccessFixture();
        [$creator, $attendee] = [$fixture['people']['creator'], $fixture['people']['attendee']];
        $id = $this->postComment($creator->id, $fixture['recordId']);
        $otherRecord = $this->createMeeting($creator, '別の会議', [], []);

        $message = $this->forbiddenMessage(fn () => $this->deleteOwn($attendee->id, $fixture['recordId'], $id));
        $this->assertSame('このコメントは削除できません', $message);
        $this->assertNull(RecordComment::query()->findOrFail($id)->deleted_at);
        $this->assertNotFound(fn () => $this->deleteOwn($creator->id, $otherRecord, $id));
        $this->deleteOwn($creator->id, $fixture['recordId'], $id);
        $this->assertNotFound(fn () => $this->deleteOwn($creator->id, $fixture['recordId'], $id));
        $this->assertNotFound(fn () => $this->deleteOwn($creator->id, $fixture['recordId'], $id + 1000));
    }

    public function test_the_order_is_module_404_then_403_then_record_404_for_the_guest_and_an_unrelated_person(): void
    {
        $fixture = $this->rowAccessFixture();
        $creator = $fixture['people']['creator'];
        $id = $this->postComment($creator->id, $fixture['recordId']);
        $this->recordModule('update_only', [RecordModuleAction::Update]);
        $readable = $this->recordModule('notes', [RecordModuleAction::Read]);
        $readableRecord = Record::factory()->create(['record_module_id' => $readable->id])->id;

        $byCreator = $this->contextFor($creator->id);

        $this->assertNotFound(fn () => app(DeleteRecordComment::class)->byAuthor($byCreator, 'missing', 1, $id));
        $this->forbiddenMessage(
            fn () => app(DeleteRecordComment::class)->byAuthor($byCreator, 'update_only', 999999999, $id),
        );
        $this->assertNotFound(fn () => $this->deleteOwn($creator->id, 999999999, $id));
        $this->assertNotFound(fn () => $this->deleteOwn($fixture['people']['watcher']->id, $fixture['recordId'], $id));
        $noteComment = $this->commentOn($readable->id, $readableRecord);
        $asGuest = $this->contextFor(null);
        $message = $this->forbiddenMessage(
            fn () => app(DeleteRecordComment::class)->byAuthor($asGuest, 'notes', $readableRecord, $noteComment),
        );
        $this->assertSame('このコメントは削除できません', $message);
        $this->assertNull(RecordComment::query()->findOrFail($id)->deleted_at);
    }

    public function test_the_administrator_route_deletes_anyones_comment_without_looking_at_the_module(): void
    {
        $fixture = $this->rowAccessFixture();
        $creator = $fixture['people']['creator'];
        $administrator = $this->user();
        $id = $this->postComment($creator->id, $fixture['recordId'], mentions: [$fixture['people']['attendee']->id]);

        $this->deleteAsAdministrator($administrator->id, $id);

        $comment = RecordComment::query()->findOrFail($id);
        $this->assertNull($comment->body);
        $this->assertSame([$creator->id, $administrator->id], [$comment->user_id, $comment->deleted_by_user_id]);
        $this->assertSame(0, RecordCommentMention::query()->where('record_comment_id', $id)->count());
        $this->assertNotFound(fn () => $this->deleteAsAdministrator($administrator->id, $id));
        $this->assertNotFound(fn () => $this->deleteAsAdministrator($administrator->id, $id + 1000));
    }

    public function test_the_administrator_route_needs_a_user_to_record_who_deleted(): void
    {
        $fixture = $this->rowAccessFixture();
        $id = $this->postComment($fixture['people']['creator']->id, $fixture['recordId']);

        $message = $this->forbiddenMessage(fn () => $this->deleteAsAdministrator(null, $id));

        $this->assertSame(DeleteRecordComment::GUEST_MESSAGE, $message);
        $this->assertNull(RecordComment::query()->findOrFail($id)->deleted_at);
    }

    private function deleteOwn(int $userId, int $recordId, int $commentId, string $module = 'meetings'): void
    {
        app(DeleteRecordComment::class)->byAuthor($this->contextFor($userId), $module, $recordId, $commentId);
    }

    private function deleteAsAdministrator(?int $userId, int $commentId): void
    {
        app(DeleteRecordComment::class)->byAdministrator($this->contextFor($userId), $commentId);
    }

    private function commentOn(int $moduleId, int $recordId): int
    {
        return RecordComment::factory()->create(['record_module_id' => $moduleId, 'record_id' => $recordId])->id;
    }
}
