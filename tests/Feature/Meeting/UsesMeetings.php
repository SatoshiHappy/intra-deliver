<?php

namespace Tests\Feature\Meeting;

use App\Actions\Ai\ExecutePrompt;
use App\Actions\Ai\ExecuteTranscription;
use App\Actions\Ai\PromptAgent;
use App\Actions\Meeting\CreateMeeting;
use App\Enums\Builtin\Meeting;
use App\Enums\Records\RecordModuleAction;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Master\Department;
use App\Models\Master\DepartmentSet;
use App\Models\Master\DepartmentTermTranslation;
use App\Models\Master\Locale;
use App\Models\Master\User;
use App\Models\Master\UserTermTranslation;
use App\Models\Meeting\MeetingRun;
use App\Models\Meeting\Transcript;
use App\Models\Meeting\TranscriptSegment;
use App\Models\Notice\Notice;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Upload\Upload;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\TranscriptionUsage;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Transcription;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsUploads;
use Throwable;

/**
 * 会議のテストの手助け(初期データの組み込みの会議を使う。ジョブは出さず、AI は PromptAgent::fake・Transcription::fake で応答を作る)。
 * 議事録の予算の区分は上限なしにする(上限のテストが値を置く)。音声は Ai の見本(docker/app/ffmpeg-check/samples)を使い、添付の実体の置き場と
 * 文字起こしの一時ファイルの置き場はテストごとの置き場にする。
 */
trait UsesMeetings
{
    use BuildsCommonMaster;
    use BuildsUploads;

    /** 議事録の出力(形に合う値) */
    protected const OUTPUT = [
        'title' => 'AI の件名',
        'summary' => 'AI の要約',
        'topics' => "## 話題\n- 要点",
        'decisions' => 'なし',
        'open_issues' => 'なし',
        'next_actions' => '未定: 確認(未定)',
    ];

    protected function setUpMeetings(): void
    {
        Queue::fake();
        $this->withoutVite();
        $this->fakeUploadDisk();
        $temp = sys_get_temp_dir().'/meeting-transcription-test-'.bin2hex(random_bytes(6));
        $this->beforeApplicationDestroyed(fn () => (new Filesystem)->deleteDirectory($temp));
        config([
            'ai_runs.budget.default' => ['daily_usd' => null, 'monthly_usd' => null, 'total_usd' => null],
            'ai_runs.transcription.temp_directory' => $temp,
        ]);
    }

    /**
     * 送られた音声(中身は Ai の見本。名前は送られたまま)。
     */
    protected function audioFile(string $name, string $sample = 'mp3float.mp3'): UploadedFile
    {
        $content = (string) file_get_contents(base_path("docker/app/ffmpeg-check/samples/{$sample}"));

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /**
     * 業務処理で音声の会議を作る(入口の判定を通さない)。
     *
     * @param  array<string, mixed>  $values
     */
    protected function audioMeeting(User $user, string $name = '定例.mp3', array $values = []): int
    {
        $file = $this->audioFile($name, self::sampleOf($name));

        return app(CreateMeeting::class)->execute($this->contextOf($user), $file, null, $values);
    }

    /**
     * 文字起こしの実行を、渡した区間(文・話者・開始の秒・終了の秒)で動かす(失敗しても投げ直された例外を受け止め、実行を返す)。
     *
     * @param  list<array{string, string, float|int, float|int}>  $segments
     */
    protected function completeTranscription(int $runId, array $segments): AiRun
    {
        Transcription::fake([new TranscriptionResponse(
            implode(' ', array_column($segments, 0)),
            new Collection(array_map(fn (array $segment) => new TranscriptionSegment(
                $segment[0],
                $segment[1],
                (float) $segment[2],
                (float) $segment[3],
            ), $segments)),
            new TranscriptionUsage(10, 5),
            new Meta('gemini', 'gemini-3.5-transcribe'),
        )]);

        try {
            app(ExecuteTranscription::class)->execute($runId);
        } catch (Throwable) {
            // 失敗は実行の状態で確かめる(投げ直しは Ai のテストが確かめる)
        }

        return AiRun::query()->findOrFail($runId);
    }

    /** 名前の拡張子に合う見本 */
    private static function sampleOf(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'm4a' => 'aac.m4a',
            'wav' => 'pcm_s16le.wav',
            default => 'mp3float.mp3',
        };
    }

    /** 名前(ja)の付いた有効なユーザー */
    protected function namedUser(string $name): User
    {
        $user = $this->user();
        UserTermTranslation::factory()->create([
            'user_term_id' => $user->userTerms()->sole()->id,
            'locale_id' => $this->ja()->id,
            'name' => $name,
        ]);

        return $user;
    }

    /** 名前(ja)の付いた有効な組織(新しい組織セット) */
    protected function namedDepartment(string $name, ?DepartmentSet $set = null): Department
    {
        $department = $this->department($set ?? DepartmentSet::factory()->create());
        DepartmentTermTranslation::factory()->create([
            'department_term_id' => $department->departmentTerms()->sole()->id,
            'locale_id' => $this->ja()->id,
            'name' => $name,
        ]);

        return $department;
    }

    protected function contextOf(?User $user): AccessContext
    {
        $today = CarbonImmutable::today(AccessContext::TIMEZONE);

        return new AccessContext($user?->id, null, null, $today, AccessContext::LOCALE_CODE);
    }

    /**
     * 業務処理で貼り付けの会議を作る(入口の判定を通さない)。
     *
     * @param  array<string, mixed>  $values
     */
    protected function pastedMeeting(User $user, string $text = "最初の発言\n次の発言", array $values = []): int
    {
        return app(CreateMeeting::class)->execute($this->contextOf($user), null, $text, $values);
    }

    /** 会議の最後の対応 */
    protected function lastRunOf(int $recordId): MeetingRun
    {
        return MeetingRun::query()->where('record_id', $recordId)->orderByDesc('id')->firstOrFail();
    }

    /** 会議の最後の対応の実行の id */
    protected function lastRunIdOf(int $recordId): int
    {
        return (int) $this->lastRunOf($recordId)->ai_run_id;
    }

    protected function userTextOf(int $runId): string
    {
        return (string) AiRunText::query()->where('ai_run_id', $runId)->value('user_text');
    }

    /**
     * 議事録の実行を、渡した出力で動かす(失敗しても投げ直された例外を受け止め、実行を返す)。
     *
     * @param  array<string, mixed>  $output
     */
    protected function completeMinutes(int $runId, array $output = self::OUTPUT): AiRun
    {
        PromptAgent::fake([$output]);

        try {
            app(ExecutePrompt::class)->execute($runId);
        } catch (Throwable) {
            // 失敗は実行の状態で確かめる(投げ直しは Ai のテストが確かめる)
        }

        return AiRun::query()->findOrFail($runId);
    }

    /**
     * 会議の値(欄のコード → 保存形の値。参照の欄は除く)。
     *
     * @return array<string, mixed>
     */
    protected function valuesOf(int $recordId): array
    {
        $codes = RecordField::query()->where('record_module_id', $this->meetingModule()->id)->pluck('code', 'id');
        $values = [];

        foreach (Record::query()->findOrFail($recordId)->field_values ?? [] as $fieldId => $value) {
            $values[(string) $codes[$fieldId]] = $value;
        }

        return $values;
    }

    protected function meetingModule(): RecordModule
    {
        return RecordModule::query()->where('code', Meeting::CODE)->sole();
    }

    protected function meetingCount(): int
    {
        return Record::query()->where('record_module_id', $this->meetingModule()->id)->count();
    }

    protected function latestMeetingId(): int
    {
        return (int) Record::query()->where('record_module_id', $this->meetingModule()->id)->max('id');
    }

    /**
     * 会議の通知(種類・宛先・起こした人・params。宛先の昇順)。
     *
     * @return list<array{string, int, int|null, array<string, mixed>}>
     */
    protected function meetingNotices(int $recordId): array
    {
        return array_values(Notice::query()->where('type', 'like', 'meeting.%')->where('target_id', $recordId)
            ->where('target_type', 'record')->orderBy('user_id')->get()
            ->map(fn (Notice $notice) => [
                $notice->type, $notice->user_id, $notice->actor_user_id, (array) $notice->params,
            ])
            ->all());
    }

    /** 会議の業務モジュールのアクションの許可を消す(ゲストと認証済みの全員の初期ポリシー) */
    protected function denyMeeting(RecordModuleAction $action): void
    {
        $code = RecordModuleAction::resourceGroupCode(Meeting::CODE);
        $groupId = AuthzResourceGroup::query()->where('code', $code)->sole()->id;
        AuthzPolicy::query()->where('authz_resource_group_id', $groupId)->where('action', $action->value)->delete();
    }

    /**
     * 利用者(既定は新しいユーザー)で会議の作成を送る(前の画面 = 会議の一覧)。
     *
     * @param  array<string, mixed>  $input  file = [名前, 中身]
     * @return TestResponse<Response>
     */
    protected function postMeeting(array $input, ?User $user = null): TestResponse
    {
        if (isset($input['file']) && is_array($input['file'])) {
            $input['file'] = UploadedFile::fake()->createWithContent($input['file'][0], $input['file'][1]);
        }

        return $this->actingAs($user ?? $this->user())->from('/records/data/meeting')->post('/meetings', $input);
    }

    /**
     * 書き起こしの発言の行(話者・開始の秒・終了の秒・発言。順の昇順)。
     *
     * @return list<array{string|null, string|null, string|null, string}>
     */
    protected function segmentsOf(int $transcriptId): array
    {
        return array_values(TranscriptSegment::query()->where('transcript_id', $transcriptId)->orderBy('seq')->get()
            ->map(fn (TranscriptSegment $segment) => [
                $segment->speaker_label, $segment->started_at_sec, $segment->ended_at_sec, $segment->text,
            ])
            ->all());
    }

    /**
     * 会議・書き起こし・発言の行・対応・実行・添付・実体の件数。
     *
     * @return list<int>
     */
    protected function counts(): array
    {
        return [
            $this->meetingCount(),
            Transcript::query()->count(),
            TranscriptSegment::query()->count(),
            MeetingRun::query()->count(),
            AiRun::query()->count(),
            RecordAttachment::query()->count(),
            Upload::query()->count(),
        ];
    }

    private function ja(): Locale
    {
        return Locale::query()->where('code', 'ja')->sole();
    }
}
