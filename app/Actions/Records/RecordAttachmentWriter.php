<?php

namespace App\Actions\Records;

use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\RecordUploadInput;
use App\Actions\Upload\Api\StoreUploads;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Queries\Records\RecordAttachmentFiles;
use App\Queries\Upload\Api\UploadLimits;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * レコードに添付を付ける 1 か所(登録・更新・添付の追加の口が同じ規則で付けるため。docs の
 * 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 1.1 節・2 節・5 節)。呼び手が業務処理の鍵を取った後に呼ぶ。
 */
class RecordAttachmentWriter
{
    public function __construct(
        private StoreUploads $storeUploads,
        private UploadLimits $limits,
        private RecordAttachmentFiles $files,
    ) {}

    /**
     * 登録・更新の入力の添付を付ける(無ければ何もしない)。例外は {@see self::attach()} と同じ。
     *
     * @param  int|null  $userId  付けた人・上げた人(契機の実行者か利用者)
     */
    public function attachInput(Record $record, ?int $userId, RecordInput $input): void
    {
        if ($input->hasAttachments()) {
            $this->attach($record, $userId, $input->attachFiles, $input->attachUploads);
        }
    }

    /**
     * 上げ済みの実体とこの送信のファイルを添付にする(上げ済みの実体は持ち主の無いものだけ = レコードの添付.md の 1.1 節)。
     *
     * @param  Record  $record  親の無いレコード(呼び手が鍵を取ったもの)
     * @param  int|null  $userId  付けた人・上げた人(契機の実行者か利用者。ゲストなら null)
     * @param  list<UploadedFile>  $files
     * @param  list<RecordUploadInput>  $uploads
     *
     * @throws InvalidArgumentException 上げ済みの実体に、持ち主の無い実体でないものがあるとき(呼び手の誤り)
     * @throws ValidationException ファイルの件数・大きさ・名前・形式・合計の誤り(キー attachments・attachments.<添字>)
     * @throws RuntimeException 実体を書けなかったとき
     */
    public function attach(Record $record, ?int $userId, array $files, array $uploads): void
    {
        $uploadIds = array_map(fn (RecordUploadInput $upload) => $upload->uploadId, $uploads);

        if (! $this->files->areOwnerless($uploadIds)) {
            throw new InvalidArgumentException('持ち主の無い実体でない upload_id がある: '.implode(',', $uploadIds));
        }

        // 上げ済みの実体だけを付けるときは上げない。どちらも無ければ StoreUploads が 0 個の 422 を返す
        $stored = $files === [] && $uploads !== [] ? [] : $this->storeUploads->execute($userId, $files);
        $names = array_map(fn (UploadedFile $file) => $this->limits->fileName($file->getClientOriginalName()), $files);
        $rows = [
            ...array_map(fn (RecordUploadInput $upload) => [$upload->uploadId, $upload->name], $uploads),
            ...array_map(null, $stored, $names),
        ];

        foreach ($rows as [$uploadId, $name]) {
            RecordAttachment::query()->create([
                'record_module_id' => $record->record_module_id,
                'record_id' => $record->id,
                'upload_id' => $uploadId,
                'name' => $name,
                'user_id' => $userId,
            ]);
        }
    }
}
