<?php

namespace App\Actions\Records\Api;

use App\Actions\Records\LocksRecordModule;
use App\Actions\Upload\Api\PurgeUploads;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Records\RecordAttachmentFiles;
use App\Queries\Records\RecordDueField;
use App\Queries\Upload\Api\UploadFile;
use App\Queries\Upload\Api\UploadQuery;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 期限が来たレコードの音声・動画の実体だけを消す(期限の削除。口の保証の正本 = docs の
 * 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 1.1 節の表、消す規則 = 同 5 節・添付ファイル/README.md の 6 節の 4)。
 * 主体を判定しない(呼び手は業務モジュールの定期の処理)。期限は口が、行の鍵の下で読み直した日付の項目の値で確かめる。
 * 台帳の鍵は取らない(合計が減るだけ)。添付の行・版・records の行は変えない。
 */
class PurgeRecordMedia
{
    use LocksRecordModule;

    public function __construct(
        private RecordDueField $dueField,
        private RecordAttachmentFiles $files,
        private UploadQuery $uploads,
        private PurgeUploads $purgeUploads,
    ) {}

    /**
     * @param  string  $dueFieldCode  期限の項目(日付の型)のコード。値は行の鍵の下で読み直す
     * @param  DateTimeInterface  $baseDate  基準日(Asia/Tokyo の日付で見る。期限の日がこれより前のときだけ消す)
     * @return int 消した実体の数(期限が基準日より前でない・値が無い・消す実体が無いときは 0)
     *
     * @throws ModelNotFoundException<Record> 親の無いレコードがその業務モジュールに無いとき(明細の行を含む)
     * @throws LogicException 業務モジュール・項目が無い・明細のモジュール・項目が日付の型でないとき(作りの誤り)
     */
    public function execute(string $moduleCode, int $recordId, string $dueFieldCode, DateTimeInterface $baseDate): int
    {
        $module = $this->dueField->module($moduleCode);
        $today = CarbonImmutable::instance($baseDate)->setTimezone(AccessContext::TIMEZONE)->startOfDay();

        return DB::transaction(function () use ($module, $recordId, $dueFieldCode, $today): int {
            $this->lockRecordModuleShared($module);
            $field = $this->dueField->field($module, $dueFieldCode);
            $record = $this->lockedRecord($module, $recordId);
            $dueOn = $this->dueOn($record->field_values[$field->id] ?? null);

            if ($dueOn === null || ! $dueOn->lt($today)) {
                return 0;
            }

            $uploadIds = $this->files->uploadIdsOf($module->id, $record->id);
            $media = array_filter($this->uploads->find($uploadIds), self::isPurgeable(...));

            return $this->purgeUploads->execute(array_keys($media), $dueOn);
        });
    }

    /**
     * @throws ModelNotFoundException<Record>
     */
    private function lockedRecord(RecordModule $module, int $recordId): Record
    {
        return Record::query()->where('record_module_id', $module->id)->whereNull('parent_record_id')
            ->lock('for no key update')->findOrFail($recordId);
    }

    /**
     * 日付の項目の値(Y-m-d)を、東京のその日の始まりにする。値が無ければ null。
     *
     * @throws LogicException 値が日付の形でないとき(保存の不変条件が崩れた)
     */
    private function dueOn(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        $zone = AccessContext::TIMEZONE;
        $date = is_string($value) ? CarbonImmutable::createFromFormat('!Y-m-d', $value, $zone) : false;

        return $date ?: throw new LogicException('期限の項目の値が日付(Y-m-d)でない');
    }

    /**
     * まだ消していない音声・動画(型が audio/*・video/*)の実体か。
     */
    private static function isPurgeable(UploadFile $file): bool
    {
        return $file->purgeDueOn === null
            && (str_starts_with($file->mime, 'audio/') || str_starts_with($file->mime, 'video/'));
    }
}
