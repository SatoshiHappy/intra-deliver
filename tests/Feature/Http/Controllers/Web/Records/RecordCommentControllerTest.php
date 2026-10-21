<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Actions\Records\PostRecordComment;
use App\Enums\Records\RecordModuleAction;
use App\Models\Master\User;
use App\Models\Notice\Notice;
use App\Models\Records\Record;
use App\Models\Records\RecordComment;
use App\Models\Records\RecordCommentMention;
use App\Models\Records\RecordModule;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * コメントのルート(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 6 節・9 節の「書ける人」「削除」「宛先」の入口の分):
 * 投稿・削除は元の画面(クエリを含む URL)へ 303 とトースト、422 は欄のキーで入力を保ち、宛先の候補は JSON。判定の順(404 → 403 → 404)と、
 * 管理のルートはシステム管理者だけ。ルートの認可を外さない(初期データのポリシー)。初期データのプロジェクト(社内ポータル刷新)と
 * 見本のユーザーで開き、今日を 2026-10-11 に固定する。
 */
final class RecordCommentControllerTest extends TransactionalTestCase
{
    use BuildsRecordModules;

    private const PAGE = '/records/data/project/1?panel=comments';

    private int $recordId;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->recordId = $this->projectRecord(newest: false);
    }

    public function test_posting_returns_to_the_same_page_with_a_toast_and_saves_the_comment_and_the_notice(): void
    {
        [$writer, $mentioned] = [$this->seeded('u0001'), $this->seeded('u0002')];
        $input = ['body' => '確認をお願いします', 'mentions' => [$mentioned->id]];

        $this->actingAs($writer)->from(self::PAGE)->post($this->url('comments'), $input)
            ->assertStatus(303)->assertRedirect(self::PAGE)
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'コメントを投稿しました']);

        $comment = RecordComment::query()->where('body', '確認をお願いします')->sole();
        $this->assertSame([$writer->id, $this->recordId], [$comment->user_id, $comment->record_id]);
        $saved = RecordCommentMention::query()->where('record_comment_id', $comment->id)->pluck('user_id')->all();
        $this->assertSame([$mentioned->id], $saved);
        $notices = Notice::query()->where('type', 'records.comment')->where('actor_user_id', $writer->id)->get();
        $this->assertSame([[$mentioned->id, $this->recordId]], $notices->map(
            fn (Notice $notice) => [$notice->user_id, $notice->target_id],
        )->all());
    }

    public function test_input_errors_use_the_field_keys_and_keep_the_input(): void
    {
        $writer = $this->actingAs($this->seeded('u0001'));
        $stranger = (int) User::query()->max('id') + 1000;
        $post = fn (array $input) => $writer->from(self::PAGE)->post($this->url('comments'), $input);

        $post(['body' => ' ', 'mentions' => [$stranger]])
            ->assertRedirect(self::PAGE)
            ->assertSessionHasErrors([
                'body' => 'コメントを入力してください',
                'mentions.0' => '宛先に指定できないユーザーが含まれています',
            ])
            ->assertSessionHasInput('mentions', [$stranger]);
        $post(['body' => str_repeat('あ', 4001)])
            ->assertSessionHasErrors(['body' => 'コメントは 4000 文字までです'])->assertSessionHasInput('body');
        $post(['body' => '本文', 'mentions' => 'x'])
            ->assertSessionHasErrors(['mentions' => '宛先の指定が正しくありません']);
        $post(['body' => '本文', 'mentions' => ['a']])
            ->assertSessionHasErrors(['mentions.0' => '宛先の指定が正しくありません']);
        $post(['body' => '本文', 'mentions' => range(1, 11)])
            ->assertSessionHasErrors(['mentions' => '宛先は 10 人までです']);
        $this->assertSame(0, RecordComment::query()->where('user_id', $this->seeded('u0001')->id)->count());
    }

    public function test_the_order_is_module_404_then_403_then_record_404(): void
    {
        $module = $this->recordModule('update_only', [RecordModuleAction::Update]);
        $updateOnly = Record::factory()->create(['record_module_id' => $module->id])->id;
        $body = ['body' => '本文'];

        $this->actingAs($this->seeded('u0001'));
        $this->post('/records/data/missing/1/comments', $body)->assertNotFound();
        $this->get('/records/data/missing/1/comments/candidates?q=a')->assertNotFound();
        $this->post("/records/data/update_only/{$updateOnly}/comments", $body)->assertForbidden();
        $this->post('/records/data/update_only/999999999/comments', $body)->assertForbidden();
        $this->post('/records/data/project/999999999/comments', $body)->assertNotFound();
        $this->get('/records/data/project/999999999/comments/candidates?q=a')->assertNotFound();
        $this->post('/records/data/project/abc/comments', $body)->assertNotFound();
        $this->assertSame(0, RecordComment::query()->where('body', '本文')->count());
    }

    public function test_the_guest_can_neither_write_nor_list_candidates_nor_delete(): void
    {
        $id = $this->comment($this->seeded('u0001'), 'ゲストは消せない', []);

        $this->post($this->url('comments'), ['body' => '本文'])->assertForbidden();
        $this->get($this->url('comments/candidates').'?q=a')->assertForbidden();
        $this->delete($this->url("comments/{$id}"))->assertForbidden();
        $this->assertNotNull(RecordComment::query()->findOrFail($id)->body);
        $this->assertSame(0, RecordComment::query()->where('body', '本文')->count());
    }

    public function test_the_author_deletes_with_a_toast_back_to_the_same_page_and_others_cannot(): void
    {
        [$author, $other] = [$this->seeded('u0001'), $this->seeded('u0002')];
        $id = $this->comment($author, '消す', [$other]);
        $otherRecord = $this->projectRecord(newest: true);

        $this->actingAs($other)->delete($this->url("comments/{$id}"))->assertForbidden();
        $this->actingAs($author)->delete($this->url("comments/{$id}", $otherRecord))->assertNotFound();
        $this->from(self::PAGE)->delete($this->url("comments/{$id}"))
            ->assertStatus(303)->assertRedirect(self::PAGE)
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'コメントを削除しました']);

        $comment = RecordComment::query()->findOrFail($id);
        $this->assertSame([null, $author->id], [$comment->body, $comment->deleted_by_user_id]);
        $this->assertSame(0, RecordCommentMention::query()->where('record_comment_id', $id)->count());
        $this->delete($this->url("comments/{$id}"))->assertNotFound();
        $this->delete($this->url('comments/abc'))->assertNotFound();
    }

    public function test_the_candidates_are_json_of_viewers_only_without_caching(): void
    {
        $url = $this->url('comments/candidates');

        $response = $this->actingAs($this->seeded('u0001'))->get($url.'?q='.rawurlencode('達也'));

        $response->assertOk()->assertExactJson([
            ['id' => $this->seeded('u0009')->id, 'name' => '吉田 達也', 'department' => '総務部'],
        ]);
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
        $this->get($url)->assertOk()->assertExactJson([]);
        $this->getJson($url.'?q='.str_repeat('a', 101))->assertUnprocessable()
            ->assertJsonValidationErrors(['q' => '検索する語は 100 文字以内にしてください']);
        $this->getJson($url.'?q=%FF%FE')->assertUnprocessable()
            ->assertJsonValidationErrors(['q' => '検索する語の指定が正しくありません']);
    }

    public function test_only_the_system_administrator_uses_the_administrator_route_and_returns_to_the_page(): void
    {
        [$author, $administrator] = [$this->seeded('u0001'), $this->seeded('u0004')];
        $id = $this->comment($author, '管理者が消す', []);
        $url = "/records/admin/comments/{$id}";

        $this->delete($url)->assertForbidden();
        $this->actingAs($author)->delete($url)->assertForbidden();
        $this->actingAs($administrator)->from(self::PAGE)->delete($url)
            ->assertStatus(303)->assertRedirect(self::PAGE)
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'コメントを削除しました']);

        $comment = RecordComment::query()->findOrFail($id);
        $this->assertSame(
            [null, $author->id, $administrator->id],
            [$comment->body, $comment->user_id, $comment->deleted_by_user_id],
        );
        $this->delete($url)->assertNotFound();
        $this->delete('/records/admin/comments/999999999')->assertNotFound();
        $this->delete('/records/admin/comments/abc')->assertNotFound();
    }

    private function url(string $path, ?int $recordId = null): string
    {
        return '/records/data/project/'.($recordId ?? $this->recordId).'/'.$path;
    }

    private function seeded(string $code): User
    {
        return User::query()->where('code', $code)->sole();
    }

    /**
     * 初期データのプロジェクトのレコード(古い順の最初か、新しい順の最初)。
     */
    private function projectRecord(bool $newest): int
    {
        $module = RecordModule::query()->where('code', 'project')->sole();
        $records = Record::query()->where('record_module_id', $module->id);

        return $records->orderBy('id', $newest ? 'desc' : 'asc')->firstOrFail()->id;
    }

    /**
     * 業務処理でコメントを書く。
     *
     * @param  list<User>  $mentions
     */
    private function comment(User $writer, string $body, array $mentions): int
    {
        return app(PostRecordComment::class)->execute(
            $this->contextFor($writer->id),
            'project',
            $this->recordId,
            $body,
            array_map(fn (User $user) => $user->id, $mentions),
        );
    }
}
