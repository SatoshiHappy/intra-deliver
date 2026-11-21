<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Core\SendKind;
use App\Actions\Ai\Core\SendTarget;
use App\Actions\Ai\Core\ThinkingStart;
use DateTimeImmutable;

/**
 * 思考の文脈の管理の旗と最後の完了の時刻(規則 = docs の 設計/AI/会話の中核.md 13.2 の「思考の文脈の管理」。代わりの版は続きを送らない)。
 * 回の答えは組む時に比べ、送る時に旗へ移す(組んだ後に要約へ進んだとき、要約をそれまでの旗で送るため)。
 */
final class SubstituteThinking
{
    private const IDLE_SECONDS = 3_600;

    private bool $idled;

    private ?DateTimeImmutable $lastCompletedAt;

    /** 組んだ回の答えの比べた旗(送る時に旗へ移す) */
    private ?bool $pending = null;

    public function __construct(ThinkingStart $start)
    {
        $this->idled = $start->idled;
        $this->lastCompletedAt = $start->lastCompletedAt;
    }

    /**
     * 要求に足す指定(付けない送信では空)。回の答えは比べた値を覚える。
     *
     * @return array<string, mixed>
     */
    public function options(SendTarget $target, SendKind $kind, DateTimeImmutable $now): array
    {
        $pattern = $target->traits->contextManagementModels;
        $sends = $kind === SendKind::Turn || $kind === SendKind::Compaction;
        $managed = $pattern !== null && preg_match($pattern, $target->model->id) === 1;

        if (! $sends || ! $managed || ! $target->model->usesThinking) {
            return [];
        }

        $idled = $this->idled;

        if ($kind === SendKind::Turn) {
            $idled = $idled || $this->idleSince($now);
            $this->pending = $idled;
        }

        $keep = $idled ? ['type' => 'thinking_turns', 'value' => 1] : 'all';

        return ['context_management' => ['edits' => [['type' => 'clear_thinking_20251015', 'keep' => $keep]]]];
    }

    /** 組んだ回の答えを送り始めた */
    public function sending(): void
    {
        $this->idled = $this->pending ?? $this->idled;
        $this->pending = null;
    }

    /** 送信が成功した(どの種別も。道具の中の送信を含む) */
    public function completed(DateTimeImmutable $at): void
    {
        if ($this->lastCompletedAt === null || $at > $this->lastCompletedAt) {
            $this->lastCompletedAt = $at;
        }
    }

    /** 要約の境目を書いた */
    public function compacted(): void
    {
        $this->idled = false;
        $this->pending = null;
    }

    private function idleSince(DateTimeImmutable $now): bool
    {
        // 秒に切り捨てて比べる(繋ぎの始めの値が引くステップの行の時刻は、秒の端数を書かずに持つので、実行をまたいでも同じ旗にするため)
        return $this->lastCompletedAt !== null
            && $now->getTimestamp() - $this->lastCompletedAt->getTimestamp() > self::IDLE_SECONDS;
    }
}
