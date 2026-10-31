<?php

namespace App\Actions\Records;

use App\Actions\Master\Api\CommonMasterWriteLock;
use App\Models\Records\RecordFieldHeading;
use App\Models\Records\RecordModule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * 見出しの名前を変える(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/見出し.md の 4 節。入力 = 業務モジュールのコード・見出しの id・{ name })。
 * 見出しが無い・その業務モジュールのでないときは 404、名前の検証(重なりを含む。自分の名前のままは通る)は {@see AddRecordFieldHeading} と同じ。位置は変えない。
 * 共通マスタと業務モジュールの排他の勧告ロック({@see LocksRecordModule})の下で行う。
 */
class UpdateRecordFieldHeading
{
    use CommonMasterWriteLock;
    use LocksRecordModule;
    use NormalizesInput;
    use ValidatesDefinitionText;
    use ValidatesHeadingName;

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ModelNotFoundException<RecordModule|RecordFieldHeading> 業務モジュールが無い・見出しがその業務モジュールに無いとき
     * @throws ValidationException 名前の誤り(キー name)
     */
    public function execute(string $moduleCode, int $headingId, array $input): RecordFieldHeading
    {
        $input = $this->normalizeInput($input);

        return $this->writeCommonMaster(function () use ($moduleCode, $headingId, $input) {
            $module = $this->lockRecordModuleExclusively($moduleCode);
            $heading = RecordFieldHeading::query()->where('record_module_id', $module->id)->findOrFail($headingId);
            $this->throwIfAny(['name' => $this->headingNameErrorIn($input['name'] ?? null, $module->id, $heading->id)]);
            $heading->update(['name' => $input['name']]);

            return $heading;
        });
    }
}
