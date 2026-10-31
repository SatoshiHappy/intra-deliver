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
     * 見出しの名前の誤りの文言(無ければ null)。形の誤りを先に見て、次に同じ業務モジュールの中の重なり
     * (ほかの見出しの名前・固定の見出し {@see RecordFieldHeading::HEAD_GROUP_NAME}。前後の空白を除いて比べる)を見る。
     * exceptId = 名前を変える見出し自身の id(自分と同じ名前のままは重ならない)。業務モジュールの排他の勧告ロックの下で呼ぶ。
     */
    private function headingNameErrorIn(mixed $name, int $recordModuleId, ?int $exceptId = null): ?string
    {
        $error = $this->headingNameError($name);

        if ($error !== null) {
            return $error;
        }

        $others = RecordFieldHeading::query()->where('record_module_id', $recordModuleId)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->pluck('name');
        $used = [RecordFieldHeading::HEAD_GROUP_NAME, ...$others->map(fn (string $other) => trim($other))->all()];

        return in_array($name, $used, true) ? 'ほかの見出しと同じ名前と「基本情報」は設定できません' : null;
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
