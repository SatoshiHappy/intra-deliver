<?php

namespace Tests\Feature\Queries\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordRevision;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Records\Api\RecordRevisionQuery;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BuildsRowAccessRecords;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 行の権限と他モジュール参照(docs の 設計/業務データの入れ物/認可とメニュー/README.md 2.2 の「参照の項目」・行の権限.md 2.4 の「参照の表示名」、メニューと初期データ.md 8 節):
 * 参照先のレコードが行の判定を通らない人には、表示名を返さない(画面は #<id>)。一覧・詳細・編集の画面の初期値・申請内容の表示・一時保存と再申請の初期値・
 * 更新履歴の版の差分のどれも同じ。見えないレコードを新しく書くと 422、今の値に残す見えない参照は確かめ直さない。
 * 参照元の業務モジュール notes は全員に見え、参照先の meetings は関係者だけに見える。
 */
final class RecordRowAccessReferenceTest extends TransactionalTestCase
{
    use BuildsRowAccessRecords;
    use CatchesValidationErrors;

    /** 見えない参照先の値(表示名なし。画面は #<id> を出す) */
    private const HIDDEN = ['code' => null, 'label' => null, 'affiliation' => null];

    private int $meetingId;

    private int $noteId;

    private User $attendee;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
        $fixture = $this->rowAccessFixture();
        $notes = $this->recordModule('notes');
        $this->field($notes, 'memo');
        $this->referenceField($notes, 'meeting', $fixture['module'], ['is_listed' => true]);
        $this->meetingId = $fixture['recordId'];
        $this->attendee = $fixture['people']['attendee'];
        $this->stranger = $fixture['people']['stranger'];
        $this->noteId = $this->createNote($this->attendee, ['memo' => '議事の補足', 'meeting' => $this->meetingId]);
    }

    public function test_the_list_the_detail_and_the_edit_form_show_no_label_of_a_hidden_record(): void
    {
        $this->actingAs($this->stranger);

        $this->get('/records/data/notes')
            ->assertInertia(fn (Assert $page) => $page->where('records.data.0.values.meeting', $this->hidden()));
        $this->get("/records/data/notes/{$this->noteId}")
            ->assertInertia(fn (Assert $page) => $page->where('record.values.meeting', $this->hidden()));
        $this->get("/records/data/notes/{$this->noteId}/edit")
            ->assertInertia(fn (Assert $page) => $page->where('record.values.meeting', $this->hidden()));

        $this->actingAs($this->attendee);
        $this->get("/records/data/notes/{$this->noteId}")
            ->assertInertia(fn (Assert $page) => $page->where('record.values.meeting.label', '定例'));
    }

    public function test_the_public_reading_shows_no_label_of_a_hidden_record(): void
    {
        $stranger = $this->requester($this->stranger);
        $attendee = $this->requester($this->attendee);
        $display = app(RecordDisplayQuery::class);
        $forms = app(RecordFormQuery::class);
        $today = CarbonImmutable::parse(self::TODAY);

        $shownTo = fn (Requester $user) => $display->show($user, $this->noteId, $today, 'ja')['record']['values'];
        $draft = $forms->draftValues($stranger, 'notes', ['meeting' => $this->meetingId], [], $today, 'ja');

        $this->assertSame($this->hidden(), $shownTo($stranger)['meeting']);
        $this->assertSame('定例', $shownTo($attendee)['meeting']['label']);
        $this->assertSame($this->hidden(), $draft['values']['meeting']);
        $edited = $forms->editValues($stranger, 'notes', $this->noteId, $today, 'ja');
        $this->assertSame($this->hidden(), $edited['values']['meeting']);
    }

    public function test_the_revision_diff_shows_no_label_of_a_hidden_record(): void
    {
        $noteId = $this->createNote($this->attendee, ['memo' => '参照なし']);
        $version = Record::query()->findOrFail($noteId)->version();
        $input = new RecordInput(['meeting' => $this->meetingId]);
        app(UpdateRecord::class)->execute($this->contextFor($this->attendee->id), 'notes', $noteId, $input, $version);
        $revisions = RecordRevision::query()->where('record_id', $noteId)->orderBy('revision')->pluck('id')->all();
        $today = CarbonImmutable::parse(self::TODAY);
        $diff = fn (User $user) => app(RecordRevisionQuery::class)
            ->diffBetween($this->requester($user), $revisions[0], $revisions[1], $today, 'ja');

        $this->assertSame(
            [['code' => 'meeting', 'name' => 'meeting', 'before' => null, 'after' => $this->hidden()]],
            $diff($this->stranger)['fields'],
        );
        $this->assertSame('定例', $diff($this->attendee)['fields'][0]['after']['label'] ?? null);
    }

    public function test_writing_a_hidden_record_is_rejected_and_keeping_one_is_not_checked_again(): void
    {
        $errors = $this->validationErrors(fn () => $this->createNote($this->stranger, ['meeting' => $this->meetingId]));
        $this->assertArrayHasKey('values.meeting', $errors);

        $version = Record::query()->findOrFail($this->noteId)->version();
        $input = new RecordInput(['memo' => '書き足し']);
        $context = $this->contextFor($this->stranger->id);
        app(UpdateRecord::class)->execute($context, 'notes', $this->noteId, $input, $version);

        $note = Record::query()->with('recordReferences')->findOrFail($this->noteId);
        $this->assertSame([$this->meetingId], $note->recordReferences->pluck('referenced_record_id')->all());
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function createNote(User $author, array $values): int
    {
        return app(CreateRecord::class)->execute($this->contextFor($author->id), 'notes', new RecordInput($values));
    }

    private function requester(User $user): Requester
    {
        return new Requester($user->id, null);
    }

    /**
     * @return array{id: int, code: null, label: null, affiliation: null}
     */
    private function hidden(): array
    {
        return ['id' => $this->meetingId, ...self::HIDDEN];
    }
}
