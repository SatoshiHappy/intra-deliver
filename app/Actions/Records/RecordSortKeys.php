<?php

namespace App\Actions\Records;

use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldHeading;
use App\Support\DefinitionText;

/**
 * 項目と見出しが共有する並びの数の空間(record_fields.sort_key と record_field_headings.sort_key は同じ数の空間。docs の
 * 設計/業務データの入れ物/レコードの共通機能/詳細の画面/見出し.md の 1・4 節)の「末尾の次」を 1 か所で決める。
 * 業務モジュールの排他の勧告ロック({@see LocksRecordModule})の下で呼ぶ。
 */
class RecordSortKeys
{
    /** 末尾の次の数が上限を超えるときの文言(項目を足す 422 と見出しを足す 422 で同じ) */
    public const LIMIT_MESSAGE = '並び順が上限に達しています。項目の表で並び順を変更すると解消されます';

    /** 並びの間隔(追加の既定 = 最大 + この値、並び替えの振り直し) */
    public const STEP = 10;

    /**
     * 見出しと項目の最大に間隔を足した数(どちらも無ければ 0)。
     */
    public function next(int $recordModuleId): int
    {
        $maxima = array_filter([
            $this->intOrNull(RecordField::query()->where('record_module_id', $recordModuleId)->max('sort_key')),
            $this->intOrNull(RecordFieldHeading::query()->where('record_module_id', $recordModuleId)->max('sort_key')),
        ], fn (?int $max) => $max !== null);

        return $maxima === [] ? 0 : max($maxima) + self::STEP;
    }

    /**
     * 末尾の次の数が上限を超えるときの誤りの文言(超えなければ null)。
     */
    public function limitError(int $next): ?string
    {
        return $next <= DefinitionText::SORT_KEY_MAX ? null : self::LIMIT_MESSAGE;
    }

    private function intOrNull(mixed $max): ?int
    {
        return is_numeric($max) ? (int) $max : null;
    }
}
