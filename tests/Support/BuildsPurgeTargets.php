<?php

namespace Tests\Support;

use App\Actions\Records\Api\AttachRecordUploads;
use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\PurgeRecordMedia;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\DetachRecordAttachments;
use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordModule;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

/**
 * 期限の削除のテストの手助け: 日付の項目 purge_on を持つ業務モジュール meetings と、そのレコード・添付・期限の削除の呼び出し。
 * 呼ぶテストは BuildsRecordModules・BuildsUploads を使い、setUp で {@see self::meetingsModule()} を呼ぶ。
 */
trait BuildsPurgeTargets
{
    private RecordModule $meetings;

    protected function meetingsModule(): void
    {
        $this->meetings = $this->recordModule('meetings');
        $this->field($this->meetings, 'title');
        $this->field($this->meetings, 'purge_on', RecordFieldType::Date);
    }

    /**
     * 期限の日(null = 値なし)と上げるファイルを持つレコードの id。
     *
     * @param  list<UploadedFile>  $files
     */
    protected function meeting(?string $purgeOn, array $files = []): int
    {
        $values = ['title' => '定例'] + ($purgeOn === null ? [] : ['purge_on' => $purgeOn]);

        return app(CreateRecord::class)->execute($this->contextFor(), 'meetings', new RecordInput($values, [], $files));
    }

    /**
     * 業務の画面と同じ口で 1 つ付けて、すぐ外す(版にだけ残る外した添付にする)。
     */
    protected function attachAndDetach(int $recordId, UploadedFile $file): void
    {
        app(AttachRecordUploads::class)->execute($this->contextFor(), 'meetings', $recordId, [$file]);
        $attachment = RecordAttachment::query()->where('record_id', $recordId)->orderByDesc('id')->firstOrFail();
        app(DetachRecordAttachments::class)->execute($this->contextFor(), 'meetings', $recordId, $attachment->id);
    }

    /**
     * 期限の削除を呼ぶ(基準日 = 引数。既定は 2026-10-21 の昼 = 東京の 2026-10-21)。
     */
    protected function purge(int $recordId, string $baseDate = '2026-10-21 03:00:00 UTC'): int
    {
        $base = CarbonImmutable::parse($baseDate);

        return app(PurgeRecordMedia::class)->execute('meetings', $recordId, 'purge_on', $base);
    }
}
