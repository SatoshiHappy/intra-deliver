<?php

namespace Tests\Feature\Meeting;

use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Models\Master\User;
use App\Models\Records\RecordAttachment;
use App\Models\Upload\Upload;
use Illuminate\Http\UploadedFile;

/**
 * 受ける音声が無い会議を作るテストの手助け({@see UsesMeetings} と一緒に使う)。
 */
trait RemovesMeetingAudio
{
    /**
     * 受ける音声が無い会議の作り方(removeAcceptedAudio の case)。
     *
     * @return array<string, array{string}>
     */
    public static function withoutAcceptedAudio(): array
    {
        return [
            '添付が無い' => ['removed'],
            '受けない形式だけ' => ['video'],
            '期限で消えた音声だけ' => ['purged'],
        ];
    }

    /**
     * 会議の今の添付を、受ける音声が無い形にする(case = {@see self::withoutAcceptedAudio()} の値)。
     */
    protected function removeAcceptedAudio(User $user, int $recordId, string $case): void
    {
        $attachments = RecordAttachment::query()->where('record_id', $recordId);

        if ($case === 'purged') {
            Upload::query()->whereIn('id', $attachments->pluck('upload_id'))
                ->update(['purged_at' => now(), 'purge_due_on' => '2026-01-01']);

            return;
        }

        $attachments->delete();

        if ($case === 'video') {
            $input = new RecordInput(attachFiles: [
                UploadedFile::fake()->createWithContent('録画.mp4', $this->sampleContent('mp4')),
            ]);
            app(UpdateRecord::class)->execute($this->contextOf($user), 'meeting', $recordId, $input, null);
        }
    }
}
