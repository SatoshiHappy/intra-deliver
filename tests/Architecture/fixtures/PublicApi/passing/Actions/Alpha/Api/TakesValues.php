<?php

namespace App\Actions\Alpha\Api;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Queries\Authz\Api\Requester;
use App\Support\AccessContext;

/**
 * 公開の口の public メソッドは識別子・配列・値のクラス・列挙・共有の型・ほかのモジュールの公開の口の型を使う。
 * メソッドの中と private のメソッドではモデルを使える
 */
final class TakesValues
{
    /**
     * @param  list<int>  $recordIds
     * @return array<int, string>
     */
    public function execute(AccessContext $context, Requester $requester, RecordModuleAction $action, AlphaValue $value, array $recordIds): array
    {
        return [$value->id => $this->codeOf($this->record($recordIds[0] ?? 0)).$action->value.($context->userId ?? 0).($requester->userId ?? 0)];
    }

    private function record(int $recordId): Record
    {
        return Record::query()->findOrFail($recordId);
    }

    private function codeOf(Record $record): string
    {
        return (string) $record->id;
    }
}
