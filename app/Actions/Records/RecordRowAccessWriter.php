<?php

namespace App\Actions\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Records\RecordRowAccessViewer;
use App\Queries\Records\RecordRowAccessValues;

/**
 * 参照者の行を書く。行は変わるときだけ消して入れ直す(並べ替えでも sort_key の一意の違反を起こさず、外れのレコードに残った行を戻さない。
 * docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md の 9.2、表 = 設計/業務データの入れ物/データモデル/表.md の 2.8)。
 *
 * @phpstan-import-type StoredRowAccess from RecordRowAccessValues
 */
class RecordRowAccessWriter
{
    /**
     * @param  Record  $record  書いた親のレコード
     * @param  StoredRowAccess  $original  元の値({@see RecordRowAccessInput::original()})
     * @param  StoredRowAccess  $merged  合わせた値({@see RecordRowAccessInput::read()})
     */
    public function write(Record $record, array $original, array $merged): void
    {
        $unchanged = $original['restricted'] === $merged['restricted'] && $original['viewers'] === $merged['viewers'];

        if ($unchanged || (! $original['restricted'] && ! $merged['restricted'])) {
            return;
        }

        RecordRowAccessViewer::query()->where('record_id', $record->id)->delete();

        foreach ($merged['viewers'] as $index => $viewer) {
            RecordRowAccessViewer::query()->create([
                'record_id' => $record->id,
                'user_id' => $viewer['type'] === RecordFieldType::User->value ? $viewer['id'] : null,
                'department_id' => $viewer['type'] === RecordFieldType::Department->value ? $viewer['id'] : null,
                'sort_key' => $index,
            ]);
        }
    }
}
