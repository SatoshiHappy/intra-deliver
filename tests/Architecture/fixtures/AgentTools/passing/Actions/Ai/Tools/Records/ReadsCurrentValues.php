<?php

namespace App\Actions\Ai\Tools\Records;

use App\Actions\Ai\Api\AgentToolParties;
use App\Actions\Ai\Api\AgentToolResult;
use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Actions\Ai\Tools\RecordToolBase;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Records\Api\RecordViewAccess;

/**
 * 道具の名前空間の道具。閲覧の判定の後に編集の初期値(今の値と版)を読むのは通る(直すの確認の内容を作る口の 1 段目)
 */
final class ReadsCurrentValues extends RecordToolBase
{
    public function __construct(private RecordViewAccess $access, private RecordFormQuery $forms) {}

    public function name(): string
    {
        return 'ai_read_current_values';
    }

    public function displayName(): string
    {
        return '今の値を読む';
    }

    public function execute(
        array $arguments,
        AgentToolSubject $subject,
        AgentToolScope $scope,
        AgentToolParties $parties,
    ): AgentToolResult {
        $module = $this->moduleOf($arguments) ?? '';
        $recordId = is_int($arguments['id'] ?? null) ? $arguments['id'] : 0;
        $requester = $subject->requester();

        if (! $this->access->canView($requester, $module, $recordId, $subject->date())) {
            return AgentToolResult::notFound();
        }

        $values = $this->forms->editValues($requester, $module, $recordId, $subject->date(), $subject->localeCode());

        return new AgentToolResult(['values' => $values['values'], 'version' => $values['version']]);
    }
}
