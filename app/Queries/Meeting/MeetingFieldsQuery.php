<?php

namespace App\Queries\Meeting;

use App\Enums\Builtin\Meeting;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Queries\Records\Api\RecordViewAccess;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 議事録の材料にする会議の欄を読む 1 か所(作成・再作成・文字起こしの完了が使う = docs の 設計/業務ツール/会議/文字起こしと議事録.md の 5.3)。
 * 依頼者の判定(業務モジュールの read と行の判定)を通してから、判定をしない入れ物の読み取りで表示名つきの値を読む。
 */
class MeetingFieldsQuery
{
    public function __construct(private RecordViewAccess $access, private RecordDisplayQuery $records) {}

    /**
     * @param  Requester  $requester  依頼者
     * @param  DateTimeInterface  $date  基準日(作成・再作成は要求の値、worker の中は書く時の当日 = 処理.md の 3.11)
     *
     * @throws ModelNotFoundException 会議が無い・依頼者が閲覧できないとき(存在を漏らさない)
     */
    public function read(
        Requester $requester,
        int $recordId,
        DateTimeInterface $date,
        string $localeCode,
    ): MeetingFields {
        if (! $this->access->canView($requester, Meeting::CODE, $recordId, $date)) {
            throw new ModelNotFoundException("会議 {$recordId} が無いか、依頼者が閲覧できない");
        }

        $values = $this->records->show($requester, $recordId, $date, $localeCode)['record']['values'];

        return new MeetingFields(
            self::text($values[Meeting::Title->value] ?? null),
            self::text($values[Meeting::HeldAt->value] ?? null),
            self::labels($values[Meeting::ViewerUsers->value] ?? null),
            self::labels($values[Meeting::ViewerDepartments->value] ?? null),
            self::text($values[Meeting::Agenda->value] ?? null),
            self::text($values[Meeting::Notes->value] ?? null),
        );
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * 複数選べる参照の値(相手ごとの {id, code, label, affiliation})の名前。名前の無い相手(基準日に無い)は入れない。
     *
     * @return list<string>
     */
    private static function labels(mixed $value): array
    {
        $labels = [];

        foreach (is_array($value) ? $value : [] as $target) {
            if (is_array($target) && is_string($target['label'] ?? null)) {
                $labels[] = $target['label'];
            }
        }

        return $labels;
    }
}
