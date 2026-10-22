<?php

namespace App\Actions\Records;

use App\Actions\Master\Api\CommonMasterWriteLock;
use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRelatedColumn;
use App\Queries\Records\RecordRelatedColumns;
use App\Queries\Records\RecordRelatedEntries;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * 関連レコードの列を選ぶ(docs の 設計/業務データの入れ物/レコードの共通機能/関連レコード.md の 2 節。入力 = 参照元の業務モジュールのコードと
 * { entry, columns })。入口の行を全部消し、columns の順に sort_key 1 から書く(置き換え。空 = 未選択に戻す)。版にしない。
 * 共通マスタと業務モジュールの排他の勧告ロック({@see LocksRecordModule})の下で行う。
 */
class SetRecordRelatedColumns
{
    use CommonMasterWriteLock;
    use LocksRecordModule;

    public const TOO_MANY_MESSAGE = '列は 5 つまで選択できます';

    public const UNSELECTABLE_MESSAGE = '選択できない項目が含まれています';

    public function __construct(private RecordRelatedEntries $entries) {}

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ModelNotFoundException<RecordModule|RecordField> 業務モジュールが無いとき、入口の項目が無い・他モジュール参照でないとき
     * @throws ValidationException 明細のモジュール(キー module)・列の誤り(キー columns)
     */
    public function execute(string $moduleCode, array $input): void
    {
        $this->writeCommonMaster(function () use ($moduleCode, $input): void {
            $module = $this->lockRecordModuleExclusively($moduleCode);

            if ($module->parent_record_module_id !== null) {
                throw ValidationException::withMessages(['module' => ['明細には設定できません']]);
            }

            $entry = $this->entries->find($module, $input['entry'] ?? null)
                ?? throw (new ModelNotFoundException)->setModel(RecordField::class);
            $columnIds = $this->columnIds($module, $input['columns'] ?? null);
            RecordRelatedColumn::query()->where('record_field_id', $entry['field']->id)->delete();

            foreach ($columnIds as $index => $columnId) {
                RecordRelatedColumn::query()->create([
                    'record_field_id' => $entry['field']->id,
                    'record_module_id' => $module->id,
                    'column_record_field_id' => $columnId,
                    'sort_key' => $index + 1,
                ]);
            }
        });
    }

    /**
     * 列の項目の id(並びの順)。行の業務モジュールの明細でない項目のコードを、重ねずに 5 つまで。
     *
     * @return list<int>
     *
     * @throws ValidationException 列の形の誤り・6 つ以上・選べない項目と重複(キー columns)
     */
    private function columnIds(RecordModule $module, mixed $columns): array
    {
        if (! is_array($columns) || ! array_is_list($columns)) {
            throw ValidationException::withMessages(['columns' => ['列の指定が正しくありません']]);
        }

        if (count($columns) > RecordRelatedColumns::MAX_COLUMNS) {
            throw ValidationException::withMessages(['columns' => [self::TOO_MANY_MESSAGE]]);
        }

        $ids = RecordField::query()->where('record_module_id', $module->id)
            ->where('type', '<>', RecordFieldType::Details)->pluck('id', 'code');
        $picked = array_map(fn (mixed $code) => is_string($code) ? $ids->get($code) : null, $columns);

        if (in_array(null, $picked, true) || count(array_unique($picked)) !== count($picked)) {
            throw ValidationException::withMessages(['columns' => [self::UNSELECTABLE_MESSAGE]]);
        }

        return array_map(fn (mixed $id) => (int) $id, $picked);
    }
}
