<?php

namespace App\Actions\Meeting;

use App\Actions\Ai\Api\PromptRequest;
use App\Queries\Meeting\MeetingFields;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;

/**
 * 議事録の依頼の材料を組む(利用者の文の形 = docs の 設計/業務ツール/会議/文字起こしと議事録.md の 5.3・5.4)。システムの文は種類の実装
 * {@see MeetingMinutesKind::SYSTEM_TEXT} に置く。出典は読んだ会議だけ。
 * 材料は囲みに入れ、材料の中で囲みの印が働かないようにする(材料の中の指示に AI を従わせないため = 同じ文書の 5.5)。
 */
class MinutesPrompt
{
    /** 材料の囲みの始まりの印 */
    public const MATERIAL_OPEN = '<資料>';

    /** 材料の囲みの終わりの印 */
    public const MATERIAL_CLOSE = '</資料>';

    /** 件名を渡さないとき(仮の件名のまま)の値 */
    public const UNTITLED = '(未入力)';

    /** 値の無い見出しの値 */
    private const NONE = 'なし';

    /** 閲覧者・閲覧組織の名前のつなぎ */
    private const NAME_SEPARATOR = '、';

    /**
     * @param  string|null  $title  渡す件名(null = 仮の件名のままなので渡さない)
     * @param  iterable<TranscriptLine>  $lines  発言の行(並び = seq の順)
     * @param  string|null  $instructions  再作成の追加の指示(無ければ null)
     */
    public function request(
        int $recordId,
        MeetingFields $fields,
        ?string $title,
        iterable $lines,
        ?string $instructions = null,
    ): PromptRequest {
        $material = implode("\n\n", [
            implode("\n", [
                '会議の情報:',
                '件名: '.($title === null ? self::UNTITLED : self::inert($title)),
                '日時: '.self::heldAt($fields->heldAt),
                '閲覧者: '.self::names($fields->viewerNames),
                '閲覧組織: '.self::names($fields->departmentNames),
            ]),
            "アジェンダ:\n".($fields->agenda === null ? self::NONE : self::inert($fields->agenda)),
            "議事メモ:\n".($fields->notes === null ? self::NONE : self::inert($fields->notes)),
            "書き起こし:\n".self::transcript($lines),
        ]);
        $sections = [self::MATERIAL_OPEN."\n".$material."\n".self::MATERIAL_CLOSE];

        if ($instructions !== null) {
            $sections[] = "追加の指示:\n".$instructions;
        }

        $userText = implode("\n\n", $sections);

        $systemText = MeetingMinutesKind::SYSTEM_TEXT;

        return new PromptRequest(MeetingMinutesKind::CODE, $recordId, [$recordId], $systemText, $userText);
    }

    /**
     * 日時(保存形 = UTC)を会議の地域の時刻 YYYY-MM-DD HH:MM にする。
     */
    private static function heldAt(?string $heldAt): string
    {
        return $heldAt === null
            ? self::NONE
            : CarbonImmutable::parse($heldAt)->setTimezone(AccessContext::TIMEZONE)->format('Y-m-d H:i');
    }

    /**
     * @param  list<string>  $names
     */
    private static function names(array $names): string
    {
        return $names === [] ? self::NONE : self::inert(implode(self::NAME_SEPARATOR, $names));
    }

    /**
     * 材料の半角の < を全角にする(囲みの印はどちらも < で始まるので、印を作れなくなる。字数は変わらない)。
     */
    private static function inert(string $text): string
    {
        return strtr($text, ['<' => '＜']);
    }

    /**
     * 発言の行ごとに「[HH:MM:SS] 話者 {ラベル}: {発言}」(時刻・話者の無い行はその部分を省く)。
     *
     * @param  iterable<TranscriptLine>  $lines
     */
    private static function transcript(iterable $lines): string
    {
        $texts = [];

        foreach ($lines as $line) {
            $time = $line->startedAtSec === null ? '' : '['.self::clock($line->startedAtSec).'] ';
            $speaker = $line->speakerLabel === null ? '' : '話者 '.self::inert($line->speakerLabel).': ';
            $texts[] = $time.$speaker.self::inert($line->text);
        }

        return implode("\n", $texts);
    }

    /**
     * 秒(小数 3 桁までの 10 進の文字列)を HH:MM:SS にする(秒の端数は切り捨てる)。
     */
    private static function clock(string $seconds): string
    {
        $total = (int) $seconds;

        return sprintf('%02d:%02d:%02d', intdiv($total, 3600), intdiv($total % 3600, 60), $total % 60);
    }
}
