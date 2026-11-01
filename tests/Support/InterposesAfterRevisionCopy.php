<?php

namespace Tests\Support;

use App\Enums\Records\RecordModuleAction;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use Closure;
use DateTimeInterface;

/**
 * 写しの初期値の「版から」を作った直後に処理を差し込むフォームの口(ほかの口は本物のまま。案件からの複製の「できない → 写さない」の窓を作る =
 * docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/検査.md の 14 節の 13.4)。呼ばれた口の名前を順に残す
 * (写しの初期値の口・今の値の読み取りを呼ばないことの確かめ = 同じ 14 節の 12.3・13.1)。
 */
final class InterposesAfterRevisionCopy extends RecordFormQuery
{
    /** @var list<string> 呼ばれた口の名前(呼ばれた順) */
    public array $calls = [];

    public function __construct(private RecordFormQuery $real, private Closure $between) {}

    public function definition(
        Requester $requester,
        string $moduleCode,
        RecordModuleAction $action,
        DateTimeInterface $date,
    ): array {
        $this->calls[] = __FUNCTION__;

        return $this->real->definition($requester, $moduleCode, $action, $date);
    }

    public function allows(
        Requester $requester,
        string $moduleCode,
        RecordModuleAction $action,
        DateTimeInterface $date,
    ): bool {
        $this->calls[] = __FUNCTION__;

        return $this->real->allows($requester, $moduleCode, $action, $date);
    }

    public function draftValues(
        Requester $requester,
        string $moduleCode,
        array $values,
        array $details,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $this->calls[] = __FUNCTION__;

        return $this->real->draftValues($requester, $moduleCode, $values, $details, $date, $localeCode);
    }

    public function editValues(
        Requester $requester,
        string $moduleCode,
        int $recordId,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $this->calls[] = __FUNCTION__;

        return $this->real->editValues($requester, $moduleCode, $recordId, $date, $localeCode);
    }

    public function copyFromRevision(
        Requester $requester,
        string $moduleCode,
        int $revisionId,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $this->calls[] = __FUNCTION__;
        $copied = $this->real->copyFromRevision($requester, $moduleCode, $revisionId, $date, $localeCode);
        ($this->between)();

        return $copied;
    }

    public function copyFromValues(
        Requester $requester,
        string $moduleCode,
        ?int $targetRecordId,
        array $values,
        array $details,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $this->calls[] = __FUNCTION__;

        return $this->real->copyFromValues(
            $requester, $moduleCode, $targetRecordId, $values, $details, $date, $localeCode,
        );
    }

    public function effectiveViewers(array $viewers, DateTimeInterface $date): array
    {
        $this->calls[] = __FUNCTION__;

        return $this->real->effectiveViewers($viewers, $date);
    }
}
