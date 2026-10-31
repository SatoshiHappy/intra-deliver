<?php

namespace App\Actions\Records;

use App\Models\Records\RecordFieldHeading;
use App\Support\DefinitionText;

/**
 * 見出しの名前の確かめ(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/見出し.md の 4 節。文言はその表のとおり)。
 * 入力の変換(前後の空白・空の扱い)は呼び手が先に行う({@see NormalizesInput})。
 */
trait ValidatesHeadingName
{
    /**
     * 索引では固定の「基本情報」を表せず、規則より前の重なりがあると張れないので、業務処理で止める(docs の 表.md 2.6・見出し.md 4 節)。
     * 保存済みの名前は書き込みで前後の空白を除いてあるので、そのまま比べる。業務モジュールの排他の勧告ロックの下で呼ぶ。
     */
    private function headingNameErrorIn(mixed $name, int $recordModuleId, ?int $exceptId = null): ?string
    {
        $error = $this->headingNameError($name);

        if ($error !== null) {
            return $error;
        }

        $isUsed = $name === RecordFieldHeading::HEAD_GROUP_NAME
            || RecordFieldHeading::query()->where('record_module_id', $recordModuleId)->where('name', $name)
                ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
                ->exists();

        return $isUsed ? 'ほかの見出しと同じ名前と「基本情報」は設定できません' : null;
    }

    /**
     * 見出しの名前の形の誤りの文言(無ければ null)。
     */
    private function headingNameError(mixed $name): ?string
    {
        return match (true) {
            $name === null => '見出しの名前を入力してください',
            ! is_string($name) || ! mb_check_encoding($name, 'UTF-8') => '見出しの名前は文字列で入力してください',
            str_contains($name, "\0") => '見出しの名前に使用できない文字(U+0000)が含まれています',
            mb_strlen($name) > DefinitionText::NAME_MAX => '見出しの名前は '.DefinitionText::NAME_MAX.' 文字までです',
            default => null,
        };
    }
}
