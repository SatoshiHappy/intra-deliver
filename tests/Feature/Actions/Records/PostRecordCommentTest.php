<?php

namespace Tests\Feature\Actions\Records;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordComment;
use App\Models\Records\RecordRevision;
use App\Queries\Records\RecordCommentGate;
use Tests\Support\BuildsRecordComments;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RegistersRecordHolds;
use Tests\Support\TransactionalTestCase;

/**
 * コメントを書く業務処理 PostRecordComment のうち、書ける人・判定の順・本文(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の
 * 1 節・9 節の「書ける人」「本文」)。宛先と通知は PostRecordCommentMentionsTest。見える範囲が「関係する人だけ」の業務モジュール meetings で確かめる。
 */
final class PostRecordCommentTest extends TransactionalTestCase
{
    use BuildsRecordComments;
    use CatchesValidationErrors;
    use RegistersRecordHolds;

    public function test_a_reader_posts_the_trimmed_body_keeping_newlines_and_markup_without_a_revision(): void
    {
        $fixture = $this->rowAccessFixture();
        $revisions = RecordRevision::query()->count();

        $creator = $fixture['people']['creator'];

        $id = $this->postComment($creator->id, $fixture['recordId'], "  \u{3000}<b>一行目</b>\n\n二行目 \n");

        $comment = RecordComment::query()->findOrFail($id);
        $this->assertSame("<b>一行目</b>\n\n二行目", $comment->body);
        $this->assertSame(
            [$fixture['module']->id, $fixture['recordId'], $creator->id],
            [$comment->record_module_id, $comment->record_id, $comment->user_id],
        );
        $this->assertNull($comment->deleted_at);
        $this->assertSame($revisions, RecordRevision::query()->count());
    }

    public function test_the_body_is_one_to_4000_characters_after_trimming(): void
    {
        $fixture = $this->rowAccessFixture();
        $userId = $fixture['people']['creator']->id;
        $post = fn (string $body) => fn () => $this->postComment($userId, $fixture['recordId'], $body);

        $this->assertSame(['body' => 'コメントを入力してください'], $this->validationErrors($post('')));
        $this->assertSame(['body' => 'コメントを入力してください'], $this->validationErrors($post(" \n\u{3000}\t")));
        $this->assertSame(['body' => 'コメントは 4000 文字までです'], $this->validationErrors($post(str_repeat('あ', 4001))));
        $this->assertSame(
            ['body' => 'コメントは 4000 文字までです'],
            $this->validationErrors($post(' '.str_repeat('あ', 4001).' ')),
        );
        $this->assertSame(0, $this->commentsAdded());

        $this->postComment($userId, $fixture['recordId'], ' '.str_repeat('あ', 4000).' ');
        $saved = RecordComment::query()->where('record_module_id', $fixture['module']->id)->sole();
        $this->assertSame(4000, mb_strlen((string) $saved->body));
    }

    public function test_invalid_utf8_and_null_characters_are_rejected_and_surrounding_spaces_removed(): void
    {
        $fixture = $this->rowAccessFixture();
        $userId = $fixture['people']['creator']->id;
        $post = fn (string $body) => fn () => $this->postComment($userId, $fixture['recordId'], $body);

        $this->assertSame(['body' => 'コメントを入力してください'], $this->validationErrors($post("\xFF")));
        $this->assertSame(['body' => 'コメントを入力してください'], $this->validationErrors($post("a\xFFb")));
        $this->assertSame(
            ['body' => 'コメントに使用できない文字(U+0000)が含まれています'],
            $this->validationErrors($post("a\0b")),
        );
        $this->assertSame(0, $this->commentsAdded());

        $id = $this->postComment($userId, $fixture['recordId'], "\u{3000}\u{200B} 前\n後 \u{3000}");
        $this->assertSame("前\n後", RecordComment::query()->findOrFail($id)->body);
    }

    public function test_a_user_with_only_update_a_user_without_a_decision_and_the_guest_are_forbidden(): void
    {
        $updateOnly = $this->recordModule('update_only', [RecordModuleAction::Update]);
        $none = $this->recordModule('none', []);
        $user = $this->user();
        $updateOnlyRecord = Record::factory()->create(['record_module_id' => $updateOnly->id])->id;
        $noneRecord = Record::factory()->create(['record_module_id' => $none->id])->id;

        $this->forbiddenMessage(fn () => $this->postComment($user->id, $updateOnlyRecord, module: 'update_only'));
        $this->forbiddenMessage(fn () => $this->postComment($user->id, $noneRecord, module: 'none'));
        $this->assertSame(0, $this->commentsAdded());
    }

    public function test_the_guest_can_view_but_cannot_write(): void
    {
        $readable = $this->recordModule('notes', [RecordModuleAction::Read]);
        $recordId = Record::factory()->create(['record_module_id' => $readable->id])->id;

        $message = $this->forbiddenMessage(fn () => $this->postComment(null, $recordId, module: 'notes'));

        $this->assertSame(RecordCommentGate::GUEST_MESSAGE, $message);
        $this->assertSame('コメントを追加するには、ユーザーを選択してください。', $message);
        $this->assertSame(0, $this->commentsAdded());
    }

    public function test_the_order_is_module_404_then_403_then_record_404_and_an_unrelated_person_gets_404(): void
    {
        $fixture = $this->rowAccessFixture();
        $user = $this->user();
        $this->recordModule('update_only', [RecordModuleAction::Update]);

        $this->assertNotFound(fn () => $this->postComment($user->id, 1, module: 'missing'));
        $this->forbiddenMessage(fn () => $this->postComment($user->id, 999999999, module: 'update_only'));
        $this->assertNotFound(fn () => $this->postComment($fixture['people']['creator']->id, 999999999));
        $this->assertNotFound(fn () => $this->postComment($fixture['people']['watcher']->id, $fixture['recordId']));
        $this->assertNotFound(fn () => $this->postComment($fixture['people']['stranger']->id, $fixture['recordId']));
        $this->assertSame(0, $this->commentsAdded());
    }

    public function test_a_record_held_by_an_application_can_still_be_commented(): void
    {
        $fixture = $this->rowAccessFixture();
        $this->registerRecordHolds([$fixture['recordId'] => 'case:1']);

        $this->postComment($fixture['people']['creator']->id, $fixture['recordId']);

        $this->assertSame(1, $this->commentsAdded());
    }

    public function test_a_record_in_a_details_module_or_another_module_is_not_found(): void
    {
        $fixture = $this->rowAccessFixture();
        $other = $this->recordModule('notes', [RecordModuleAction::Read]);
        $otherRecord = Record::factory()->create(['record_module_id' => $other->id])->id;
        $creator = $fixture['people']['creator']->id;

        $this->assertNotFound(fn () => $this->postComment($creator, $otherRecord));
        $this->assertNotFound(fn () => $this->postComment($creator, $fixture['recordId'], module: 'rows_rows'));
    }
}
