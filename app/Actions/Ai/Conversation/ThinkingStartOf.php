<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\ThinkingStart;
use App\Actions\Ai\Core\TransientText;
use App\Enums\Ai\ConversationItemType;
use App\Queries\Ai\RunChain;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 思考の文脈の管理の始めの値を、会話の実行の鎖のステップの行から引く(実行をまたいで同じ旗にするため。引き方 = docs の
 * 設計/AI/会話の中核.md 13.2 の「思考の文脈の管理」の実行の始めの値)。
 */
final class ThinkingStartOf
{
    private const STEPS = ', steps AS (SELECT s.* FROM ai_run_steps AS s JOIN chain ON s.ai_run_id = chain.id)';

    private const DONE = "SELECT max(p.finished_at) FROM steps AS p WHERE p.status = 'succeeded'";

    /**
     * @param  list<ConversationItem|TransientText>  $history  送る履歴(最後の要約の境目を探す)
     */
    public function of(int $runId, array $history): ThinkingStart
    {
        $boundary = self::boundaryStepId($history);
        $row = DB::selectOne(
            RunChain::cte().self::STEPS.' SELECT ('.self::DONE.') AS last_completed_at,'
            .' EXISTS (SELECT 1 FROM steps AS t'
            ." WHERE t.send_kind IN ('turn', 'continuation') AND t.retried_from_step_id IS NULL"
            .' AND (?::bigint IS NULL OR t.started_at > (SELECT b.started_at FROM steps AS b WHERE b.id = ?))'
            .' AND t.started_at - ('.self::DONE." AND p.finished_at <= t.started_at) > interval '1 hour') AS idled",
            [$runId, $boundary, $boundary],
        );
        $last = is_object($row) && is_string($row->last_completed_at ?? null) ? $row->last_completed_at : null;

        return new ThinkingStart(
            is_object($row) && ($row->idled ?? false) === true,
            $last === null ? null : new DateTimeImmutable($last),
        );
    }

    /**
     * @param  list<ConversationItem|TransientText>  $history
     */
    private static function boundaryStepId(array $history): ?int
    {
        $stepId = null;

        foreach ($history as $item) {
            if ($item instanceof ConversationItem && $item->type === ConversationItemType::CompactBoundary) {
                $stepId = $item->content['step_id'];
            }
        }

        return is_int($stepId) ? $stepId : null;
    }
}
