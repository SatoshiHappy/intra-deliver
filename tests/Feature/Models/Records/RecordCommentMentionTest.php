<?php

namespace Tests\Feature\Models\Records;

use App\Models\Records\RecordCommentMention;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * コメントの宛先(docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.5 節・同じ階層の README.md の 7 節:
 * 同じコメントに同じ宛先を 2 行書けない、コメントを消すと宛先が消える)。外部キーの削除の動作とリレーションは ForeignKeysTest。
 */
final class RecordCommentMentionTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_rejects_the_same_addressee_twice_on_a_comment(): void
    {
        $mention = RecordCommentMention::factory()->create();

        $this->assertRejectedBy(
            'record_comment_mentions_record_comment_id_user_id_unique',
            fn () => RecordCommentMention::factory()->create([
                'record_comment_id' => $mention->record_comment_id,
                'user_id' => $mention->user_id,
            ]),
        );
    }

    public function test_accepts_two_addressees_on_a_comment_and_one_addressee_on_two_comments(): void
    {
        $mention = RecordCommentMention::factory()->create();

        $sameComment = RecordCommentMention::factory()->create(['record_comment_id' => $mention->record_comment_id]);
        $sameUser = RecordCommentMention::factory()->create(['user_id' => $mention->user_id]);

        $this->assertModelExists($sameComment);
        $this->assertModelExists($sameUser);
    }

    public function test_deleting_the_comment_deletes_its_mentions(): void
    {
        $mention = RecordCommentMention::factory()->create();

        DB::table('record_comments')->where('id', $mention->record_comment_id)->delete();

        $this->assertModelMissing($mention);
    }
}
