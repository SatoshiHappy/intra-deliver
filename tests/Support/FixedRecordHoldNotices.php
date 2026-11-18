<?php

namespace Tests\Support;

use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordHoldLink;
use App\Queries\Records\Api\RecordHoldNotices;
use App\Queries\Records\Api\RecordHoldState;
use Closure;
use DateTimeInterface;

/**
 * テストの中だけで使う持ち主の案内の実装(docs の 設計/ワークフロー/業務データとの接続/公開の口/表示と関係の口.md の 7.3 の口)。
 * 返す値は関数で決め(例外を投げる関数も渡せる)、呼ばれた引数を残す(何を渡して何回呼んだかを確かめるため)。
 */
final class FixedRecordHoldNotices implements RecordHoldNotices
{
    /** @var list<array{Requester, string, DateTimeInterface}> */
    public array $stateCalls = [];

    /** @var list<array{int, list<int>}> */
    public array $revisionCalls = [];

    /** @var list<int> */
    public array $attachmentCalls = [];

    /**
     * @param  Closure(Requester, string, DateTimeInterface): ?RecordHoldState  $state
     * @param  Closure(int, list<int>): array<int, RecordHoldLink>  $revisions
     * @param  Closure(int): ?string  $attachment
     */
    public function __construct(
        private Closure $state,
        private Closure $revisions,
        private Closure $attachment,
    ) {}

    /**
     * どのメソッドも案内を返さない実装(呼び手の側の関数だけを差し替えて使う)。
     *
     * @param  Closure(Requester, string, DateTimeInterface): ?RecordHoldState|null  $state
     * @param  Closure(int, list<int>): array<int, RecordHoldLink>|null  $revisions
     * @param  Closure(int): ?string|null  $attachment
     */
    public static function of(?Closure $state = null, ?Closure $revisions = null, ?Closure $attachment = null): self
    {
        return new self(
            $state ?? fn () => null,
            $revisions ?? fn () => [],
            $attachment ?? fn () => null,
        );
    }

    public function stateOf(Requester $requester, string $holder, DateTimeInterface $date): ?RecordHoldState
    {
        $this->stateCalls[] = [$requester, $holder, $date];

        return ($this->state)($requester, $holder, $date);
    }

    /**
     * @return array<int, RecordHoldLink>
     */
    public function revisionLinks(int $recordId, array $revisionIds): array
    {
        $this->revisionCalls[] = [$recordId, $revisionIds];

        return ($this->revisions)($recordId, $revisionIds);
    }

    public function attachmentDisableNotice(int $recordModuleId): ?string
    {
        $this->attachmentCalls[] = $recordModuleId;

        return ($this->attachment)($recordModuleId);
    }
}
