<?php

namespace Tests\Feature\Models\Records;

use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordComment;
use App\Models\Records\RecordCommentMention;
use App\Models\Records\RecordModule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * コメント(docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.4 節・同じ階層の README.md の 7 節:
 * 削除の印の 3 列は「本文あり・日時と人なし」か「本文なし・日時と人あり」の組だけ、本文は空にせず 4000 文字まで、
 * レコードを消すとコメントと宛先が消える、別の業務モジュールのレコードを指せない)。
 * 外部キーの削除の動作とリレーションは ForeignKeysTest。
 */
final class RecordCommentTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    private const MARK_CHECK = 'record_comments_deleted_at_body_deleted_by_user_id_check';

    /**
     * 削除の印の 3 列の組(本文・日時・削除した人の有無)のうち、書けない組。
     *
     * @return array<string, array{bool, bool, bool}>
     */
    public static function invalidMarks(): array
    {
        return [
            '本文なしで印なし' => [false, false, false],
            '本文なしで日時だけ' => [false, true, false],
            '本文なしで人だけ' => [false, false, true],
            '本文ありで日時と人あり' => [true, true, true],
            '本文ありで日時だけ' => [true, true, false],
            '本文ありで人だけ' => [true, false, true],
        ];
    }

    #[DataProvider('invalidMarks')]
    public function test_rejects_a_deletion_mark_that_is_neither_present_nor_absent_as_a_whole(
        bool $hasBody,
        bool $hasDeletedAt,
        bool $hasDeletedBy,
    ): void {
        $attributes = [
            'body' => $hasBody ? '本文' : null,
            'deleted_at' => $hasDeletedAt ? now() : null,
            'deleted_by_user_id' => $hasDeletedBy ? User::factory() : null,
        ];

        $this->assertRejectedBy(self::MARK_CHECK, fn () => RecordComment::factory()->create($attributes));
    }

    public function test_accepts_a_comment_and_a_deleted_comment(): void
    {
        $comment = RecordComment::factory()->create()->refresh();
        $deleted = RecordComment::factory()->deleted()->create()->refresh();

        $this->assertNotNull($comment->body);
        $this->assertNull($comment->deleted_at);
        $this->assertNull($deleted->body);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertNotNull($deleted->deletedByUser);
    }

    public function test_rejects_an_empty_body(): void
    {
        $this->assertRejectedBy(
            'record_comments_body_check',
            fn () => RecordComment::factory()->create(['body' => '']),
        );
    }

    public function test_accepts_4000_characters_and_rejects_4001(): void
    {
        $comment = RecordComment::factory()->create(['body' => str_repeat('あ', 4000)]);

        $this->assertSame(4000, mb_strlen((string) $comment->refresh()->body));

        try {
            DB::transaction(fn () => RecordComment::factory()->create(['body' => str_repeat('あ', 4001)]));
            $this->fail('4001 文字の本文が書けた');
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'value too long for type character varying(4000)',
                $exception->getMessage(),
            );
        }
    }

    public function test_deleting_the_record_deletes_the_comments_and_their_mentions(): void
    {
        $comment = RecordComment::factory()->create();
        $mention = RecordCommentMention::factory()->create(['record_comment_id' => $comment->id]);

        DB::table('records')->where('id', $comment->record_id)->delete();

        $this->assertModelMissing($comment);
        $this->assertModelMissing($mention);
    }

    public function test_rejects_a_record_of_another_module(): void
    {
        $record = Record::factory()->create();
        $otherModule = RecordModule::factory()->create();

        $this->assertRejectedBy(
            'record_comments_record_module_id_record_id_foreign',
            fn () => RecordComment::factory()
                ->create(['record_module_id' => $otherModule->id, 'record_id' => $record->id]),
        );
    }
}
