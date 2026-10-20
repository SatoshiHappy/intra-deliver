<?php

namespace App\Actions\Alpha\Api;

use App\Models\Records\Record;

/**
 * 公開の口の業務処理の public メソッドの引数・戻り値にモデル
 */
final class TakesModels
{
    public function execute(Record $record): void {}

    public function find(int $recordId): Record
    {
        return Record::query()->findOrFail($recordId);
    }

    public function maybe(?Record $record): int
    {
        return $record === null ? 0 : $record->id;
    }

    /**
     * @return list<Record>
     */
    public function all(): array
    {
        return array_values(Record::query()->get()->all());
    }

    /** @phpstan-ignore forbiddenPublicApiType */
    public function suppressed(Record $record): void {}
}
