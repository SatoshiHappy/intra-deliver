<?php

namespace App\Actions\Records;

use App\Actions\Master\WritesCommonMaster;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldHeading;
use App\Models\Records\RecordModule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * 項目と見出しを並び替える(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/失敗と定義の変更.md 11 節、
 * 設計/業務データの入れ物/レコードの共通機能/詳細の画面/見出し.md の 4 節)。入力 = 業務モジュール(明細のモジュールを含む)のコードと items
 * (その業務モジュールの全見出しと全項目の 1 本の新しい順。要素 = { kind: 'heading', id } か { kind: 'field', code })。
 * items が全見出しと全項目の順列でなければ(過不足・重複・ほかの業務モジュールの行・形の誤り)キー items の 422 で拒み、何も書かない。
 * 順列なら sort_key を items の順に 0, 10, 20, … で振り直す(追加の既定 = 最大 + 10 が上限に達したときもこれで直る)。
 * 持ち主のあるレコードがあっても拒まない(値を変えない定義)。業務モジュールの判定はしない(入口のルートの認可で絞る = 認可とメニュー/README.md 4 節)。
 * 定義の書き込みなので共通マスタと業務モジュールの排他の勧告ロック({@see LocksRecordModule})の下で行う。
 */
class ReorderRecordFields
{
    use ChecksPermutation;
    use LocksRecordModule;
    use NormalizesInput;
    use WritesCommonMaster;

    /**
     * @param  string  $moduleCode  業務モジュール(明細のモジュールを含む)のコード
     * @param  array<array-key, mixed>  $items  見出しと項目の新しい順
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無いとき
     * @throws ValidationException items が全見出しと全項目の順列でないとき(キー items)
     */
    public function execute(string $moduleCode, array $items): void
    {
        $given = array_map($this->keyOf(...), $this->normalizeInput($items));

        $this->writeCommonMaster(function () use ($moduleCode, $given) {
            $module = $this->lockRecordModuleExclusively($moduleCode);
            $fields = RecordField::query()->where('record_module_id', $module->id)->get(['id', 'code', 'sort_key']);
            $headings = RecordFieldHeading::query()->where('record_module_id', $module->id)->get(['id', 'sort_key']);
            $order = $this->permutation($given, [
                ...$fields->map(fn (RecordField $field) => $this->fieldKey($field->code))->all(),
                ...$headings->map(fn (RecordFieldHeading $heading) => $this->headingKey($heading->id))->all(),
            ]);

            if ($order === null) {
                throw ValidationException::withMessages(['items' => ['項目の並びが今の項目と一致しません。画面を再読み込みしてから再度操作してください']]);
            }

            $positions = array_flip($order);

            foreach ($fields as $field) {
                $field->update(['sort_key' => $positions[$this->fieldKey($field->code)] * RecordSortKeys::STEP]);
            }

            foreach ($headings as $heading) {
                $heading->update(['sort_key' => $positions[$this->headingKey($heading->id)] * RecordSortKeys::STEP]);
            }
        });
    }

    private function fieldKey(string $code): string
    {
        return 'field:'.$code;
    }

    private function headingKey(int $id): string
    {
        return 'heading:'.$id;
    }

    /**
     * 送られた要素 1 つの照合用の文字列(型の確かめ = code は文字列・id は整数。形の誤りは null で、順列の確かめが落とす)。
     */
    private function keyOf(mixed $item): ?string
    {
        if (! is_array($item)) {
            return null;
        }

        return match ($item['kind'] ?? null) {
            'field' => is_string($item['code'] ?? null) ? $this->fieldKey($item['code']) : null,
            'heading' => is_int($item['id'] ?? null) ? $this->headingKey($item['id']) : null,
            default => null,
        };
    }
}
