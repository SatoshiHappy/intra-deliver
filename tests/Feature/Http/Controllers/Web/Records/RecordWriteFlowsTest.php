<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Actions\Records\Api\AttachRecordUploads;
use App\Actions\Records\DetachRecordAttachments;
use App\Models\Master\User;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordComment;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\BuildsRowAccessRecords;
use Tests\Support\BuildsUploads;
use Tests\Support\RegistersRecordHolds;
use Tests\Support\TransactionalTestCase;
use Tests\Support\WritesFromReferer;

/**
 * レコードの詳細からの添付とコメントの書き込みの、行の判定と移り先(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md の 9.2 の 2、
 * 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 1.2 節・10 節、コメント.md の 9 節、詳細の画面/README.md の 3 節)。
 * 行の判定を通る人と通らない人を 1 つのレコードで並べるため、参照制限のある会議の見本を使う。
 */
final class RecordWriteFlowsTest extends TransactionalTestCase
{
    use BuildsRowAccessRecords;
    use BuildsUploads;
    use RegistersRecordHolds;
    use WritesFromReferer;

    /** @var array{recordId: int, people: array<string, User>} */
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakeUploadDisk();
        $this->fixture = $this->rowAccessFixture();
    }

    /**
     * 鍵なしの添付: update は許されるが行の判定を通らない人は、入口でも業務処理でも 404 で、何も付かない。
     */
    public function test_attaching_without_a_key_is_not_found_for_a_person_failing_the_row_access(): void
    {
        $watcher = $this->person('watcher');

        $this->actingAs($watcher)->post($this->url('attachments'), ['attachments' => [$this->uploadedFile('a.pdf')]])
            ->assertNotFound();
        $this->assertThrows(
            fn () => app(AttachRecordUploads::class)->execute(
                $this->contextFor($watcher->id), 'meetings', $this->recordId(), [$this->uploadedFile('b.pdf')],
            ),
            ModelNotFoundException::class,
        );
        $this->assertSame(0, RecordAttachment::query()->where('record_id', $this->recordId())->count());
    }

    /**
     * 鍵を渡す添付は行の判定を見ない: 行の判定を通らない人でも、持ち主の鍵があれば付く。
     */
    public function test_attaching_with_the_holder_key_does_not_look_at_the_row_access(): void
    {
        $this->registerRecordHolds([$this->recordId() => 'test-holder:1']);

        $watcher = $this->contextFor($this->person('watcher')->id);

        app(AttachRecordUploads::class)
            ->execute($watcher, 'meetings', $this->recordId(), [$this->uploadedFile('a.pdf')], 'test-holder:1');

        $this->assertSame(1, RecordAttachment::query()->where('record_id', $this->recordId())->count());
    }

    /**
     * 外す: 行の判定を通らない人は、入口でも業務処理でも 404 で、添付は残る。
     */
    public function test_detaching_is_not_found_for_a_person_failing_the_row_access(): void
    {
        $attachmentId = $this->attachAsCreator('a.pdf');
        $watcher = $this->person('watcher');

        $this->actingAs($watcher)->delete($this->url("attachments/{$attachmentId}"))->assertNotFound();
        $this->assertThrows(
            fn () => app(DetachRecordAttachments::class)
                ->execute($this->contextFor($watcher->id), 'meetings', $this->recordId(), $attachmentId),
            ModelNotFoundException::class,
        );
        $this->assertTrue(RecordAttachment::query()->whereKey($attachmentId)->exists());
    }

    /**
     * 行の判定を通る人が、開いた添付の欄(クエリ panel)から追加・外すと同じ URL へ戻り、戻った画面の欄の先頭の行と帯の件数に結果が出る。
     */
    public function test_adding_and_removing_return_to_the_open_panel_with_the_result(): void
    {
        $this->actingAs($this->person('attendee'));
        $page = $this->pageUrl(['panel' => 'attachments']);

        $this->fromReferer($page)->post($this->url('attachments'), ['attachments' => [$this->uploadedFile('議事録.pdf')]])
            ->assertStatus(303)->assertRedirect($page);
        $props = $this->props($this->get($page));
        $this->assertSame('議事録.pdf', $props['panelContent']['rows'][0]['name'] ?? null);
        $this->assertSame(1, $this->bandCount($props, 'attachments'));

        $attachmentId = RecordAttachment::query()->where('record_id', $this->recordId())->sole()->id;
        $this->fromReferer($page)->delete($this->url("attachments/{$attachmentId}"))
            ->assertStatus(303)->assertRedirect($page);
        $props = $this->props($this->get($page));
        $this->assertSame([], $props['panelContent']['rows'] ?? null);
        $this->assertSame(0, $this->bandCount($props, 'attachments'));
    }

    /**
     * 行の判定を通る人がコメントを書き、本人が消すと、どちらも開いた欄の URL へ戻り、戻った画面の欄に結果が出る。システム管理者が管理の経路で
     * 消しても同じ URL へ戻る。
     */
    public function test_posting_and_deleting_comments_return_to_the_open_panel_with_the_result(): void
    {
        $page = $this->pageUrl(['panel' => 'comments']);
        $this->actingAs($this->person('attendee'));

        foreach (['本人が消す', '管理者が消す'] as $body) {
            $this->fromReferer($page)->post($this->url('comments'), ['body' => $body])
                ->assertStatus(303)->assertRedirect($page);
            $this->assertSame($body, $this->props($this->get($page))['panelContent']['rows'][0]['body'] ?? null);
        }

        $own = RecordComment::query()->where('body', '本人が消す')->sole()->id;
        $this->fromReferer($page)->delete($this->url("comments/{$own}"))->assertStatus(303)->assertRedirect($page);
        $byAdministrator = RecordComment::query()->where('body', '管理者が消す')->sole()->id;
        $this->actingAs(User::query()->where('code', 'u0004')->sole());
        $this->fromReferer($page)->delete("/records/admin/comments/{$byAdministrator}")
            ->assertStatus(303)->assertRedirect($page);

        $this->actingAs($this->person('attendee'));
        $rows = array_column($this->props($this->get($page))['panelContent']['rows'], null, 'id');

        foreach ([$own, $byAdministrator] as $id) {
            $this->assertArrayHasKey($id, $rows);
            $this->assertNull($rows[$id]['body']);
            $this->assertNotNull($rows[$id]['deleted']);
        }
    }

    private function recordId(): int
    {
        return $this->fixture['recordId'];
    }

    private function person(string $key): User
    {
        return $this->fixture['people'][$key];
    }

    private function attachAsCreator(string $name): int
    {
        $creator = $this->contextFor($this->person('creator')->id);
        app(AttachRecordUploads::class)->execute($creator, 'meetings', $this->recordId(), [$this->uploadedFile($name)]);

        return RecordAttachment::query()->where('record_id', $this->recordId())->orderByDesc('id')->firstOrFail()->id;
    }

    private function url(string $path): string
    {
        return "/records/data/meetings/{$this->recordId()}/{$path}";
    }

    /**
     * @param  array<string, string>  $query
     */
    private function pageUrl(array $query): string
    {
        return "http://localhost/records/data/meetings/{$this->recordId()}?".http_build_query($query);
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function bandCount(array $props, string $key): ?int
    {
        foreach ($props['panel']['items'] ?? [] as $item) {
            if (($item['key'] ?? null) === $key) {
                return $item['count'] ?? null;
            }
        }

        return null;
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        $response->assertOk();

        return $response->viewData('page')['props'];
    }
}
