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

    /**
     * 書く(確認の後。判定する登録 = 10.5 の表 2 の P3・P2・P7 は入れ物が断る)。結果の書いたレコードは作ったレコード 1 件。
     * 入れ物の断りは投げたまま返す(表 2 の行に直すのは ③ の書く時の部品)。
     *
     * @param  array<string, mixed>  $written  書く値の引数(問うの時に残した物。前の件を指す参照は作った id に直した後)
     * @param  RecordConfirmation  $confirmation  問うの時に残した確認の内容
     */
    public function write(
        array $written,
        AgentToolSubject $subject,
        AgentToolScope $scope,
        RecordConfirmation $confirmation,
    ): AgentToolResult;
}
