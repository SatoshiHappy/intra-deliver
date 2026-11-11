<?php

namespace App\Queries\Authz;

use App\Queries\Authz\Api\AuthorizationReuse;
use App\Queries\Authz\Api\Requester;
use DateTimeInterface;
use Illuminate\Container\Attributes\Singleton;

/**
 * 判定の使い回し({@see AuthorizationReuse})の覚え(規則 = docs の 設計/共通マスタ/ロールと認可/認可の確認/判定の使い回し.md 3.4)。
 * 実体は 1 つ(#[Singleton]): 覚える側(注入で持つ判定の口)と捨てる側(リスナー)が要求の寿命の区切りで別の実体に分かれないため。
 * 覚えはいちばん外のまとまりを出たら空にするので、要求(キューのジョブ)をまたいで残らない。
 */
#[Singleton]
class AuthorizationMemo
{
    /** 開いているまとまりの数(入れ子は外のまとまりに含まれる) */
    private int $depth = 0;

    /** 開いている使い回さない区間の数 */
    private int $bypass = 0;

    /** 捨てる場面を見た回数 */
    private int $generation = 0;

    /** @var array<string, bool> */
    private array $allowed = [];

    public function open(): void
    {
        $this->depth++;
    }

    /**
     * いちばん外のまとまりを閉じたら覚えを捨てる。
     */
    public function close(): void
    {
        $this->depth--;

        if ($this->depth === 0) {
            $this->allowed = [];
        }
    }

    /**
     * 使い回さない区間に入り、覚えを捨てる(理由 = ADR 0046 の決定 3)。
     */
    public function openBypass(): void
    {
        $this->bypass++;
        $this->discard();
    }

    public function closeBypass(): void
    {
        $this->bypass--;
    }

    /**
     * 今の世代(判定の前に控え、覚えるときに渡す)。
     */
    public function generation(): int
    {
        return $this->generation;
    }

    /**
     * 覚えた結果(使い回さないとき・覚えていなければ null)。
     */
    public function recall(Requester $requester, string $uri, string $action, DateTimeInterface $date): ?bool
    {
        return $this->reuses() ? $this->allowed[self::keyOf($requester, $uri, $action, $date)] ?? null : null;
    }

    /**
     * 判定の結果を覚える(使い回さないとき・判定を始めた世代 $since から捨てる場面があったときは覚えない)。
     */
    public function remember(
        Requester $requester,
        string $uri,
        string $action,
        DateTimeInterface $date,
        bool $allowed,
        int $since,
    ): void {
        if ($this->reuses() && $this->generation === $since) {
            $this->allowed[self::keyOf($requester, $uri, $action, $date)] = $allowed;
        }
    }

    /**
     * DB に送った文が読み取りでなければ捨てる。
     */
    public function noteStatement(string $sql): void
    {
        if (! self::isRead($sql)) {
            $this->discard();
        }
    }

    /**
     * 覚えを捨て、世代を進める(覚えが空でも、まとまりの外でも進める)。
     */
    public function discard(): void
    {
        $this->allowed = [];
        $this->generation++;
    }

    private function reuses(): bool
    {
        return $this->depth > 0 && $this->bypass === 0;
    }

    /**
     * 読み取りの文か(文の種類 = 3.4 の捨てる場面)。見分けに迷う文は読み取りとみない(捨て過ぎは遅くなるだけ = ADR 0046 の決定 3)。
     */
    private static function isRead(string $sql): bool
    {
        return preg_match('/^[\s(]*(select|with)\b/i', $sql) === 1
            && preg_match('/\b(insert|update|delete|merge|truncate|share|into)\b|advisory/i', $sql) !== 1;
    }

    private static function keyOf(Requester $requester, string $uri, string $action, DateTimeInterface $date): string
    {
        return implode('|', [
            $requester->userId ?? '',
            $requester->selectedDepartmentId ?? '',
            $requester->ipv4Address ?? '',
            $date->format('Y-m-d'),
            $action,
            $uri,
        ]);
    }
}
