<?php

namespace App\Actions\Records;

use App\Actions\Master\WritesCommonMaster;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * 項目を並び替える(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/失敗と定義の変更.md 11 節)。入力 = 業務モジュール(明細のモジュールを含む)のコードと codes
 * (その業務モジュールの全項目のコードを新しい順に)。codes が全項目のコードの順列でなければ(過不足・重複・ほかの業務モジュールのコード)
 * キー codes の 422 で拒み、何も書かない。順列なら sort_key を codes の順に 0, 10, 20, … で振り直す(追加の既定 = 最大 + 10 が上限に
 * 達したときもこれで直る)。業務モジュールの判定はしない(入口のルートの認可で絞る = 認可とメニュー/README.md 4 節)。
 * 定義の書き込みなので共通マスタと業務モジュールの排他の勧告ロック({@see LocksRecordModule})の下で行う。
 */
class ReorderRecordFields
{
    use ChecksPermutation;
    use LocksRecordModule;
    use NormalizesInput;
    use ValidatesDefinitionText;
    use WritesCommonMaster;

    /**
     * @param  string  $moduleCode  業務モジュール(明細のモジュールを含む)のコード
     * @param  array<array-key, mixed>  $codes  項目のコードの新しい順
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無いとき
     * @throws ValidationException codes が全項目のコードの順列でないとき(キー codes)
     */
    public function execute(string $moduleCode, array $codes): void
    {
        $given = $this->normalizeInput($codes);

        $this->writeCommonMaster(function () use ($moduleCode, $given) {
            $module = $this->lockRecordModuleExclusively($moduleCode);
            $fields = RecordField::query()->where('record_module_id', $module->id)->get(['id', 'code', 'sort_key']);
            $order = $this->permutation($given, $fields->pluck('code')->all());

            if ($order === null) {
                throw ValidationException::withMessages(['codes' => ['項目の並びが今の項目と一致しません。画面を再読み込みしてから再度操作してください']]);
            }

            $positions = array_flip($order);

            foreach ($fields as $field) {
                $field->update(['sort_key' => $positions[$field->code] * self::SORT_KEY_STEP]);
            }
        });
    }
}
