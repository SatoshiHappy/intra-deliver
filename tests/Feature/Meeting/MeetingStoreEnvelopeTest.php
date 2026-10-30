<?php

namespace Tests\Feature\Meeting;

use App\Actions\Meeting\CreateMeeting;
use App\Http\Requests\Meeting\MeetingStoreRequest;
use App\Models\Master\User;
use App\Models\Records\RecordReference;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * ファイルからの会議の作成の multipart の封筒(docs の 設計/業務ツール/会議/処理.md の 3.2。ファイルの外の入力 = JSON の envelope):
 * 参照の id を数のまま受け、読めない封筒は 403 の後に 422 envelope だけを返し、封筒の中の文字列は JSON の要求と同じに変換する。
 */
final class MeetingStoreEnvelopeTest extends TransactionalTestCase
{
    use UsesMeetings;
    use UsesSeededRecords;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMeetings();
        $this->creator = $this->user();
    }

    public function test_references_in_the_envelope_create_a_meeting_from_a_file(): void
    {
        $viewers = [$this->namedUser('佐藤 花子'), $this->namedUser('鈴木 一郎')];
        $department = $this->namedDepartment('開発部');
        $projectId = $this->recordNamed('project', 'name', '社内ポータル刷新')->id;
        $values = [
            'related_parties' => [
                ['type' => 'user', 'id' => $viewers[0]->id],
                ['type' => 'department', 'id' => $department->id],
                ['type' => 'user', 'id' => $viewers[1]->id],
            ],
            'project' => $projectId,
        ];

        $this->sendFile(['envelope' => json_encode(['values' => $values], JSON_THROW_ON_ERROR)])
            ->assertSessionHasNoErrors()->assertRedirect('/records/data/meeting');

        $recordId = $this->latestMeetingId();
        $this->assertSame('定例.txt', $this->valuesOf($recordId)['title']);
        $references = RecordReference::query()->where('record_id', $recordId)
            ->orderByRaw('user_id nulls last, department_id nulls last')->get()
            ->map(fn (RecordReference $row) => [$row->user_id, $row->department_id, $row->referenced_record_id])->all();
        $this->assertSame([
            [$viewers[0]->id, null, null],
            [$viewers[1]->id, null, null],
            [null, $department->id, null],
            [null, null, $projectId],
        ], $references);
    }

    public function test_a_guest_with_a_broken_envelope_is_forbidden_first(): void
    {
        $this->sendFile(['envelope' => '{壊れた'], asGuest: true)->assertForbidden();
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unreadableEnvelopes(): array
    {
        return [
            '封筒が無い(文字列の欄だけ)' => [['values' => ['title' => '定例', 'related_parties' => ['1']]]],
            'JSON のリスト' => [['envelope' => '[{"values": {}}]']],
            'JSON でない' => [['envelope' => '{"values":']],
            '大きすぎる' => [['envelope' => '{"values": {"notes": "'.str_repeat('a', 1_048_576).'"}}']],
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    #[DataProvider('unreadableEnvelopes')]
    public function test_an_unreadable_envelope_is_only_an_envelope_error(array $fields): void
    {
        $counts = $this->counts();

        $this->sendFile($fields)->assertSessionHasErrors([
            'envelope' => MeetingStoreRequest::ENVELOPE_UNREADABLE,
        ])->assertSessionDoesntHaveErrors(['file', 'values']);

        $this->assertSame($counts, $this->counts());
    }

    public function test_strings_in_the_envelope_are_trimmed_and_empty_ones_become_null(): void
    {
        $this->mock(CreateMeeting::class, function (MockInterface $mock): void {
            $mock->expects('execute')->withArgs(
                fn (mixed $context, mixed $file, mixed $text, mixed $values) => $file instanceof UploadedFile
                    && $text === null && $values === ['title' => '定例', 'agenda' => null],
            )->andReturn(1);
        });
        $envelope = ['values' => ['title' => '  定例  ', 'agenda' => '']];

        $this->sendFile(['envelope' => json_encode($envelope, JSON_THROW_ON_ERROR)])->assertSessionHasNoErrors();
    }

    /**
     * 画面と同じ multipart(ファイル file と欄)で会議の作成を送る(既定は作る人、$asGuest ならゲスト)。
     *
     * @param  array<string, mixed>  $fields
     * @return TestResponse<Response>
     */
    private function sendFile(array $fields, bool $asGuest = false): TestResponse
    {
        $file = UploadedFile::fake()->createWithContent('定例.txt', "最初の発言\n次の発言");
        $server = ['CONTENT_TYPE' => 'multipart/form-data; boundary=x', 'HTTP_REFERER' => '/records/data/meeting'];

        if (! $asGuest) {
            $this->actingAs($this->creator);
        }

        return $this->call('POST', '/meetings', $fields, [], ['file' => $file], $server);
    }
}
