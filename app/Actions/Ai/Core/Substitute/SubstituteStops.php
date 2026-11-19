<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\StopKind;
use App\Enums\Ai\AiInstructionTemplate;

/**
 * 止めたときの書き方(決める者が中核の分。表の正本 = docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.5)。
 */
final readonly class SubstituteStops
{
    public function __construct(private SubstituteState $state) {}

    /** 送る前(どの種別の送信も): 利用者なら道具の途中でない中断の印、ほかは書かない */
    public function beforeSend(StopKind $stop): void
    {
        if ($stop === StopKind::User) {
            $this->state->append([ConversationItem::interruption(false)]);
        }
    }

    /**
     * 応答を読む途中・読み終えた後(利用者): 回の答えの結果の無い呼び出しに中断の短い結果、道具の途中でない中断の印。
     *
     * @param  list<string>  $callIds  結果の無い呼び出し(要約の応答では空 = 呼び出しは捨てる)
     */
    public function whileReading(array $callIds): void
    {
        $results = self::results($callIds, AiInstructionTemplate::StoppedWithoutResult);
        $this->state->append([...$results, ConversationItem::interruption(false)]);
    }

    /**
     * 道具を呼ぶ前: 利用者なら取り消しの結果と道具の途中の中断の印、時間の上限なら上限で止めた旨の失敗の結果だけ。
     *
     * @param  non-empty-list<string>  $callIds  その呼び出しと残りの呼び出し
     */
    public function beforeTool(StopKind $stop, array $callIds): void
    {
        if ($stop === StopKind::User) {
            $cancelled = self::results($callIds, AiInstructionTemplate::UserCancelled);
            $this->state->append([...$cancelled, ConversationItem::interruption(true)]);

            return;
        }

        $limited = self::results($callIds, AiInstructionTemplate::StoppedByTimeLimit);

        if ($limited !== []) {
            $this->state->append($limited);
        }
    }

    /**
     * 道具の後(利用者): 問うの呼び出しに取り消しの結果、道具の途中の中断の印(次の回の番号が回数の上限も超えるなら回数の上限の印)。
     *
     * @param  list<string>  $askingCallIds
     */
    public function afterTools(array $askingCallIds, bool $overTurns): void
    {
        $cancelled = self::results($askingCallIds, AiInstructionTemplate::UserCancelled);
        $items = [...$cancelled, ConversationItem::interruption(true)];
        $this->state->append($overTurns ? [...$items, ConversationItem::turnLimit()] : $items);
    }

    /**
     * @param  list<string>  $callIds
     * @return list<ConversationItem>
     */
    private static function results(array $callIds, AiInstructionTemplate $template): array
    {
        return array_map(
            static fn (string $id): ConversationItem => ConversationItem::toolResult($id, $template, [], null, true),
            $callIds,
        );
    }
}
