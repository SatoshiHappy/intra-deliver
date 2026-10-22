<?php

namespace App\Queries\Meeting;

use App\Enums\Meeting\MeetingAiKind;
use App\Enums\Meeting\MeetingRunOrigin;
use App\Queries\Ai\Api\RunBudgetQuery;
use DateTimeInterface;
use LogicException;

/**
 * 音声の取り込みの先の止め方と、議事録の区分の先の確かめの文を選ぶ 1 か所(docs の 設計/業務ツール/会議/処理.md の 3.9、公開デモと画面.md の 9.2・9.3)。
 * 区分の状態は AI の予算の区分の状態の口で、種類のコード(議事録・文字起こし)から読む(区分の名前を持たない)。鍵を取らずに読む。
 * 文は超えた期間で言い換える(日 =「今日の」のまま、月 =「今月の」、累計 =「今日の」を外す)。
 */
class MeetingAudioBudget
{
    /** 議事録の区分が受けられない(両方のときもこちら) */
    private const MINUTES_NEAR = '今日の AI の利用の上限に近いため、';

    /** 文字起こしの区分だけが受けられない */
    private const TRANSCRIPTION_NEAR = '今日の音声の取り込みの上限に近いため、';

    /** 作成の先の止め方の文の後ろ */
    private const CANNOT_CHOOSE = '音声は選べません。';

    /** 作成で文字起こしの区分が受けられないときに添える文(先の止め方と、文字起こしの依頼が上限で断られたときの文が使う) */
    public const TEXT_STILL_WORKS = 'テキストの取り込みは使用できます。';

    /** 再作成の先の止め方と議事録の区分の確かめの文の後ろ */
    private const CANNOT_REGENERATE = '音声から作り直せません。';

    /** 作成の議事録の区分の確かめの文の後ろ */
    private const CANNOT_IMPORT = '音声は取り込めません。';

    public function __construct(private RunBudgetQuery $budgets) {}

    /**
     * 音声から文字起こしを依頼する前の、議事録の区分の確かめの 422 の文(処理.md の 3.9)。受けられれば null。
     *
     * @throws LogicException 種類が設定に無いとき
     */
    public function minutesDeclineMessage(MeetingRunOrigin $origin, DateTimeInterface $at): ?string
    {
        $period = $this->budgets->exceededPeriodOfKind(MeetingAiKind::Minutes->value, $at);
        $tail = $origin === MeetingRunOrigin::Create ? self::CANNOT_IMPORT : self::CANNOT_REGENERATE;

        return $period === null ? null : self::inPeriod(self::MINUTES_NEAR.$tail, $period);
    }

    /**
     * 画面の先の止め方の文(公開デモと画面.md の 9.2・9.3)。議事録の区分を先に見る。どちらの区分も受けられれば null。
     *
     * @throws LogicException 種類が設定に無いとき
     */
    public function blockedMessage(MeetingRunOrigin $origin, DateTimeInterface $at): ?string
    {
        $create = $origin === MeetingRunOrigin::Create;
        $minutes = $this->budgets->exceededPeriodOfKind(MeetingAiKind::Minutes->value, $at);

        if ($minutes !== null) {
            $tail = $create ? self::CANNOT_CHOOSE : self::CANNOT_REGENERATE;

            return self::inPeriod(self::MINUTES_NEAR.$tail, $minutes);
        }

        $transcription = $this->budgets->exceededPeriodOfKind(MeetingAiKind::Transcription->value, $at);
        $tail = $create ? self::CANNOT_CHOOSE.self::TEXT_STILL_WORKS : self::CANNOT_REGENERATE;

        return $transcription === null ? null : self::inPeriod(self::TRANSCRIPTION_NEAR.$tail, $transcription);
    }

    /**
     * 「今日の」で書いた文を、超えた期間で言い換える。
     */
    private static function inPeriod(string $message, string $period): string
    {
        return match ($period) {
            RunBudgetQuery::MONTH => str_replace('今日の', '今月の', $message),
            RunBudgetQuery::TOTAL => ltrim(str_replace('今日の', '', $message)),
            default => $message,
        };
    }
}
