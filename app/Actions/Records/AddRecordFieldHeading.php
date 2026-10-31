<?php

namespace App\Actions\Records;

use App\Actions\Master\Api\CommonMasterWriteLock;
use App\Models\Records\RecordFieldHeading;
use App\Models\Records\RecordModule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * 見出しを末尾に足す(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/見出し.md の 4 節。入力 = 業務モジュールのコードと { name })。
 * sort_key は見出しと項目の最大 + 10({@see RecordSortKeys}。どちらも無ければ 0)。位置は {@see ReorderRecordFields} で動かす。
 * 同じ業務モジュールのほかの見出しと固定の「基本情報」と同じ名前は足せない(キー name)。明細のモジュールには足せない(キー module)。持ち主のあるレコードがあっても拒まない(値を変えない定義)。版にしない。
 * 共通マスタと業務モジュールの排他の勧告ロック({@see LocksRecordModule})の下で行う。
 */
class AddRecordFieldHeading
{
    use CommonMasterWriteLock;
    use LocksRecordModule;
    use NormalizesInput;
    use ValidatesDefinitionText;
    use ValidatesHeadingName;

    public function __construct(private RecordSortKeys $sortKeys) {}

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無いとき
     * @throws ValidationException 明細のモジュール(キー module)・名前の誤り(キー name)・並び順が上限に達したとき(キー sort_key)
     */
    public function execute(string $moduleCode, array $input): RecordFieldHeading
    {
        $input = $this->normalizeInput($input);

        return $this->writeCommonMaster(function () use ($moduleCode, $input) {
            $module = $this->lockRecordModuleExclusively($moduleCode);

            if ($module->parent_record_module_id !== null) {
                throw ValidationException::withMessages(['module' => ['明細には見出しを追加できません']]);
            }

            $next = $this->sortKeys->next($module->id);
            $this->throwIfAny([
                'name' => $this->headingNameErrorIn($input['name'] ?? null, $module->id),
                'sort_key' => $this->sortKeys->limitError($next),
            ]);

            return RecordFieldHeading::query()->create([
                'record_module_id' => $module->id,
                'name' => $input['name'],
                'sort_key' => $next,
            ]);
        });
    }
}
