<?php

namespace App\Actions\Records;

use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use Illuminate\Database\Eloquent\Builder;

/**
 * 項目の名前の重複の検査(docs の 設計/業務データの入れ物/項目と規則/README.md 1 節。ユーザーレビュー 03 の 18): 項目の名前は、同じ業務モジュールとその
 * 明細のモジュール(親と全部の明細 = 1 つの名前の集まり)の中で重複させない。業務モジュールをまたぐ重複は許す。比べ方は前後の空白を除いた後の文字列の
 * 完全一致(大文字小文字・全角半角は区別する)。項目を追加するときと、名前を変えるときだけ確かめる(名前が今の値のままなら確かめない = 既に重複した
 * データがあっても名前以外の変更は拒まない)。DB の一意の制約は無い(親と明細をまたぐため)ので、呼び手は業務モジュールの排他の勧告ロック
 * ({@see LocksRecordModule}。鍵 = トップの業務モジュール)を取った後に呼ぶ = 同時に同じ名前を追加する競合をロックが防ぐ。
 * 名前の形の誤り(空・文字数)は {@see FieldDefinitionValidator} が先に返すので、ここには形の正しい名前だけが来る。
 */
class FieldNameUniqueness
{
    /**
     * 名前が集まりの中で重複するときの誤りの文言(無ければ null)。重複する項目のある業務モジュール(親か明細)の名前を文言に入れる。
     *
     * @param  RecordModule  $module  項目を足す・変える業務モジュール(明細のモジュールを含む)
     * @param  RecordField|null  $current  変える項目(足すときは null)
     */
    public function error(RecordModule $module, ?RecordField $current, mixed $name): ?string
    {
        if (! is_string($name) || ($current !== null && $name === $current->name)) {
            return null;
        }

        $topId = $module->parent_record_module_id ?? $module->id;
        $moduleName = RecordField::query()
            ->join('record_modules', 'record_modules.id', '=', 'record_fields.record_module_id')
            ->where(fn (Builder $query) => $query->where('record_modules.id', $topId)->orWhere('record_modules.parent_record_module_id', $topId))
            ->where('record_fields.name', $name)
            ->when($current !== null, fn (Builder $query) => $query->whereKeyNot($current?->id))
            ->orderBy('record_fields.id')
            ->value('record_modules.name');

        return is_string($moduleName) ? "この名前は「{$moduleName}」の項目で既に使用されています" : null;
    }
}
