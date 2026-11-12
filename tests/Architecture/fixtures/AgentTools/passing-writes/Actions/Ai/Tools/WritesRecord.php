<?php

namespace App\Actions\Ai\Tools;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\RecordRevisionOrigin;
use App\Actions\Records\Api\UpdateRecord;
use App\Support\AccessContext;

/**
 * 契機(AI)を付けて持ち主の鍵を渡さない登録・更新は通る(鍵の引数を省く・名前つきの null の字句・位置の null の字句)
 */
final class WritesRecord
{
    public function __construct(private CreateRecord $create, private UpdateRecord $update) {}

    public function create(AccessContext $context, RecordInput $input): int
    {
        return $this->create->execute($context, 'tasks', $input, new RecordRevisionOrigin('AI'));
    }

    public function updateWithoutHolder(AccessContext $context, int $recordId, RecordInput $input): void
    {
        $this->update->execute($context, 'tasks', $recordId, $input, null, origin: new RecordRevisionOrigin('AI'));
    }

    public function updateWithNamedNull(AccessContext $context, int $recordId, RecordInput $input): void
    {
        $this->update->execute($context, 'tasks', $recordId, $input, null, holder: null, origin: new RecordRevisionOrigin('AI'));
    }

    public function updateWithPositionalNull(AccessContext $context, int $recordId, RecordInput $input): void
    {
        $this->update->execute($context, 'tasks', $recordId, $input, null, null, new RecordRevisionOrigin('AI'));
    }
}
