<?php

namespace App\Actions\Ai\Api;

use App\Queries\Authz\Api\Requester;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;

/**
 * 判定の主体の文脈(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.4 の表。値の型で、作るのは ③ の共通の部品だけ)。道具のクラスは
 * 受けて使うだけで、判定を求める者を受ける口には {@see self::requester()} を渡す。
 */
final readonly class AgentToolSubject
{
    public function __construct(private AccessContext $context) {}

    /** 判定の入力の値の型(入れ物の書き込みの口が受ける) */
    public function accessContext(): AccessContext
    {
        return $this->context;
    }

    /** 判定を求める者 */
    public function requester(): Requester
    {
        return Requester::fromAccessContext($this->context);
    }

    /** 基準日(読み書きする時の東京の当日) */
    public function date(): CarbonImmutable
    {
        return $this->context->date;
    }

    public function localeCode(): string
    {
        return $this->context->localeCode;
    }
}
