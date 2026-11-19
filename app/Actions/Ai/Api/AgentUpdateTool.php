<?php

namespace App\Actions\Ai\Api;

/**
 * 直すの性質の書く道具の約束(docs の 設計/AI/道具と権限/README.md 10.1 の「確認の内容を作る口」。直すは 2 段で、今の値を使う検証が無くても
 * 2 段に分ける = 一様にする)。呼ばれた時に判定の関数が、閲覧と更新の権限を見た後に 1 段目を呼び、③ の共通の部品が今の値を使う検証(B19)を
 * 通した後に 2 段目を呼ぶ(設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の B6・B19・B7)。道具のクラスは共通マスタの口を使わない(10.4)。
 */
interface AgentUpdateTool extends AgentRecordTool
{
    /**
     * 1 段目: 閲覧の判定の後に、今の値・参照制限の今の値・版を読む。見つからない(閲覧の判定を通らない・無い・判定の後に消えた)は null(B6)。
     *
     * @param  array<string, mixed>  $arguments  形の検証と ③ の共通の確かめと形の外の検証を通った引数(相手は id に直した後)
     */
    public function current(array $arguments, AgentToolSubject $subject, AgentToolScope $scope): ?RecordCurrentValues;

    /**
     * 2 段目: 確認の内容(10.5 の中身。前の件を指す参照の k は ③ の共通の部品が足す)。
     *
     * @param  array<string, mixed>  $arguments  1 段目と同じ引数
     * @param  AgentToolParties  $parties  書く値の相手のうち、今の値(欄ごと)に無かった相手(今の値に残す相手は $current から出す = 10.2)
     */
    public function confirmation(
        RecordCurrentValues $current,
        array $arguments,
        AgentToolSubject $subject,
        AgentToolScope $scope,
        AgentToolParties $parties,
    ): RecordConfirmation;
}
