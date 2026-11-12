<?php

namespace App\Actions\Ai\Tools\Records;

use App\Actions\Ai\Api\AgentToolResult;
use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Actions\Ai\Api\RecordMarker;
use App\Actions\Ai\Tools\RecordToolBase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Queries\Records\Api\RecordViewAccess;

/**
 * 道具の名前空間の下の名前空間で、基底のクラスを通して約束を実装する道具。閲覧の判定の後に詳細の値を読むのは通る
 */
final class ReadsRecord extends RecordToolBase
{
    public function __construct(private RecordViewAccess $access, private RecordDisplayQuery $display) {}

    public function name(): string
    {
        return 'ai_read_record';
    }

    public function displayName(): string
    {
        return 'レコードを読む';
    }

    public function execute(array $arguments, AgentToolSubject $subject, AgentToolScope $scope): AgentToolResult
    {
        $module = $this->moduleOf($arguments) ?? '';
        $recordId = is_int($arguments['id'] ?? null) ? $arguments['id'] : 0;
        $requester = $this->requesterOf($subject);

        if (! $this->access->canView($requester, $module, $recordId, $subject->date())) {
            return AgentToolResult::notFound();
        }

        $values = $this->display->show($requester, $recordId, $subject->date(), $subject->localeCode());

        return new AgentToolResult(['record' => new RecordMarker($module, $recordId), 'values' => $values]);
    }

    private function requesterOf(AgentToolSubject $subject): Requester
    {
        return $subject->requester();
    }
}
