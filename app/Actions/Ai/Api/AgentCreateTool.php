<?php

namespace App\Actions\Ai\Api;

/**
 * 作るの性質の書く道具の約束(docs の 設計/AI/道具と権限/README.md 10.1 の「確認の内容を作る口」。作るは 1 段)。呼ばれた時に判定の関数が、
 * 登録の権限を見た後に呼ぶ(設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の B5)。道具のクラスは共通マスタの口を使わない(10.4)。
 */
interface AgentCreateTool extends AgentRecordTool
{
    /**
     * 確認の内容(10.5 の中身。前の件を指す参照の k は ③ の共通の部品が足す = 道具は同じステップの前の呼び出しを受けないため)。
     *
     * @param  array<string, mixed>  $arguments  形の検証と ③ の共通の確かめと形の外の検証を通った引数(相手は id に直した後)
     * @param  AgentToolScope  $scope  実行の始めの範囲(定義と参照制限の既定)
     * @param  AgentToolParties  $parties  書く値の相手(③ の共通の部品が確かめて読んだ物)
     */
    public function confirmation(
        array $arguments,
        AgentToolSubject $subject,
        AgentToolScope $scope,
        AgentToolParties $parties,
    ): RecordConfirmation;
}
