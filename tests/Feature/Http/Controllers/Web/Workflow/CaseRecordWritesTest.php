<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Records\Api\AttachRecordUploads;
use App\Actions\Records\RecordRevisionWriter;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Records\RecordRevisionAction;
use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordComment;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\WorkflowRecordHolds;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\BuildsRecordModules;
use Tests\Support\BuildsUploads;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;
use Tests\Support\WritesFromReferer;

/**
 * 申請の詳細からの書き込みと移り先、申請の関係者のファイル(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 3 節・
 * コメント.md の 5 節と 9 節・関連レコード.md の 9 節・更新履歴.md の 9 節・添付ファイル/レコードの添付.md の 7 節)。見本の WF-000005 の課長 u0002 を
 * 使うのは、申請の経路だけで申請書のレコードを見る人だから(参照制限があり、行の判定を通らない = 各テストの最初で確かめる)。
 */
final class CaseRecordWritesTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use BuildsUploads;
    use UsesSeededCases;
    use UsesSeededRecords;
    use WritesFromReferer;

    private WorkflowCase $case;

    private Record $record;

    private string $moduleCode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakeUploadDisk();
        $this->case = WorkflowCase::query()->findOrFail($this->caseNumbered(5));
        $this->record = Record::query()->findOrFail($this->case->record_id);
        $this->moduleCode = RecordModule::query()->findOrFail($this->record->record_module_id)->code;
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function moduleReads(): array
    {
        return ['read はあるが行の判定を通らない' => [true], 'read が無い' => [false]];
    }

    /**
     * 申請の関係者は、今の添付と外した時点のファイルを、ダウンロードもプレビューも、申請が未完了の間も完了の後も開ける(200)。
     */
    #[DataProvider('moduleReads')]
    public function test_a_participant_opens_the_files_while_active_and_after_completion(bool $read): void
    {
        $urls = $this->fileUrls();
        $this->actAsParticipantOutsideTheRowAccess($read);

        foreach ([false, true] as $completed) {
            if ($completed) {
                $this->complete();
            }

            foreach ($urls as $url) {
                $this->get($url)->assertOk();
            }
        }
    }

    /**
     * 申請を閲覧できない人(更新履歴.md の 9 節・認可と初期データ.md の 5 節)。URL が開けることは作成者で先に確かめる。
     */
    public function test_a_person_who_cannot_view_the_case_cannot_open_the_files(): void
    {
        $urls = $this->fileUrls();
        $this->actingAsUser('u0001');
        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }
        $this->actingAs(User::factory()->create());

        foreach ($urls as $url) {
            $this->get($url)->assertNotFound();
        }

        $this->deny($this->moduleCode, RecordModuleAction::Read);

        foreach ($urls as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    /**
     * 申請の関係者が申請の詳細(開いた欄のクエリ付き)からコメントを書き、自分のコメントを消すと、どちらも同じ URL へ戻り、戻った画面の欄に結果が出る
     * (未完了の間も完了の後も)。
     */
    #[DataProvider('moduleReads')]
    public function test_a_participant_posts_and_deletes_own_comments_from_the_case(bool $read): void
    {
        $this->actAsParticipantOutsideTheRowAccess($read);
        $page = $this->caseUrl(['panel' => 'comments']);

        foreach (['未完了の間のコメント', '完了の後のコメント'] as $index => $body) {
            if ($index === 1) {
                $this->complete();
            }

            $posted = $this->fromReferer($page)->post($this->recordUrl('comments'), ['body' => $body]);
            $posted->assertStatus(303)->assertRedirect($page);
            $this->assertSame($body, $this->commentRowsOf($this->get($page))[0]['body'] ?? null);

            $id = RecordComment::query()->where('body', $body)->sole()->id;
            $deleted = $this->fromReferer($page)->delete($this->recordUrl("comments/{$id}"));
            $deleted->assertStatus(303)->assertRedirect($page);
            $this->assertDeletedRow($this->commentRowsOf($this->get($page)), $id);
        }
    }

    /**
     * システム管理者が申請の詳細から管理の経路でコメントを消すと、同じ申請の詳細(クエリを含む)へ戻る。
     */
    public function test_the_administrator_deletes_a_comment_from_the_case_and_returns_to_it(): void
    {
        $this->actingAsUser('u0002');
        $page = $this->caseUrl(['panel' => 'comments']);
        $this->fromReferer($page)->post($this->recordUrl('comments'), ['body' => '管理者が消す'])->assertStatus(303);
        $id = RecordComment::query()->where('body', '管理者が消す')->sole()->id;

        $this->actingAsUser('u0004');
        $this->fromReferer($page)->delete("/records/admin/comments/{$id}")->assertStatus(303)->assertRedirect($page);

        $this->actingAsUser('u0002');
        $this->assertDeletedRow($this->commentRowsOf($this->get($page)), $id);
    }

    /**
     * 申請の詳細の関連レコードのタブから「新規」で登録すると、同じ申請の詳細(タブと入口のクエリ付き)へ戻り、戻った画面の表に新しい行が出る
     * (登録する人 = 申請者 u0001。参照の値に申請書のレコードを入れるので、そのレコードの行の判定を通る人)。
     */
    public function test_a_related_store_from_the_case_returns_to_the_case_with_the_new_row(): void
    {
        $application = RecordModule::query()->findOrFail($this->record->record_module_id);
        $notes = $this->recordModule('case_notes');
        $this->field($notes, 'title');
        $this->referenceField($notes, 'plan', $application);
        $this->actingAsUser('u0001');
        $page = $this->caseUrl(['tab' => 'related', 'related' => 'case_notes.plan']);
        $input = [
            'values' => ['title' => '申請の詳細から登録', 'plan' => $this->record->id],
            'related' => ['field' => 'plan', 'record' => $this->record->id],
        ];

        $this->fromReferer($page)->post('/records/data/case_notes', $input)->assertStatus(303)->assertRedirect($page);

        $created = Record::query()->where('record_module_id', $notes->id)->sole();
        $props = $this->props($this->get($page));
        $this->assertSame([$created->id], array_column($props['relatedRecordRows']['rows'], 'id'));
        $entries = array_column($props['relatedRecords']['entries'], 'count', 'key');
        $this->assertSame(1, $entries['case_notes.plan'] ?? null);
    }

    /**
     * u0002 になり、申請書のレコードを業務の画面からは見られないこと(read があれば行の判定の 404、無ければ 403)を確かめる。
     */
    private function actAsParticipantOutsideTheRowAccess(bool $read): void
    {
        if (! $read) {
            $this->deny($this->moduleCode, RecordModuleAction::Read);
        }
        $this->actingAsUser('u0002');
        $this->get("/records/data/{$this->moduleCode}/{$this->record->id}")->assertStatus($read ? 404 : 403);
    }

    /**
     * 消したコメントは、本文が無く削除の印のある行として欄に残る(コメント.md の 9 節)。
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertDeletedRow(array $rows, int $id): void
    {
        $row = array_values(array_filter($rows, fn (array $row) => $row['id'] === $id))[0] ?? null;
        $this->assertNotNull($row, "消したコメント {$id} の行が欄に無い");
        $this->assertNull($row['body']);
        $this->assertNotNull($row['deleted']);
    }

    /**
     * 今の添付 1 つと、外した時点のファイル 1 つのダウンロードとプレビューの URL(添付は持ち主の鍵で付け、外すのは持ち主の規則を通らずに行を消す)。
     *
     * @return list<string>
     */
    private function fileUrls(): array
    {
        $writer = $this->contextFor(User::query()->where('code', 'u0001')->sole()->id);
        $attach = fn (string $name) => app(AttachRecordUploads::class)->execute(
            $writer, $this->moduleCode, $this->record->id, [$this->uploadedFile($name)],
            WorkflowRecordHolds::holderOf($this->case->id),
        );
        $attach('外す版.pdf');
        $removed = RecordAttachment::query()->where('record_id', $this->record->id)->orderByDesc('id')->firstOrFail();
        $removed->delete();
        app(RecordRevisionWriter::class)->write($this->record->id, RecordRevisionAction::Update, null, null);
        $attach('今の版.pdf');
        $current = RecordAttachment::query()->where('record_id', $this->record->id)->orderByDesc('id')->firstOrFail();

        return [
            $this->recordUrl("attachments/{$current->id}"),
            $this->recordUrl("attachments/{$current->id}/preview"),
            $this->recordUrl("revision-files/{$removed->upload_id}"),
            $this->recordUrl("revision-files/{$removed->upload_id}/preview"),
        ];
    }

    private function complete(): void
    {
        WorkflowCase::query()->whereKey($this->case->id)->update([
            'state' => WorkflowCaseState::Completed,
            'completion_reason' => WorkflowCompletionReason::Approved,
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function caseUrl(array $query): string
    {
        return "http://localhost/workflow/cases/{$this->case->id}?".http_build_query($query);
    }

    private function recordUrl(string $path): string
    {
        return "/records/data/{$this->moduleCode}/{$this->record->id}/{$path}";
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<array<string, mixed>>
     */
    private function commentRowsOf(TestResponse $response): array
    {
        return $this->props($response)['panelContent']['rows'] ?? [];
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
