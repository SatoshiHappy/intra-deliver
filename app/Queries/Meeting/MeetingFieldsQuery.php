<?php

namespace App\Queries\Meeting;

use App\Enums\Builtin\Meeting;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Queries\Records\Api\RecordViewAccess;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 議事録の材料にする会議の欄を作る 1 か所(docs の 設計/業務ツール/会議/文字起こしと議事録.md の 5.3)。作成・再作成は {@see self::read()} で
 * 依頼者の判定(業務モジュールの read と行の判定)を通してから読む。文字起こしの完了の中(worker)は依頼者を判定せず、AI の読む口の値から
 * {@see self::fromValues()} で作る(依頼の時点に確かめた = 設計/業務ツール/会議/処理.md の 3.11)。
 */
class MeetingFieldsQuery
{
    public function __construct(private RecordViewAccess $access, private RecordDisplayQuery $records) {}

    /**
     * @param  Requester  $requester  依頼者
     * @param  DateTimeInterface  $date  基準日(要求の値)
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

        return self::fromValues($this->records->show($requester, $recordId, $date, $localeCode)['record']['values']);
    }

    /**
     * 会議の値(入れ物の詳細の record.values の形 = コード → 値)から材料の欄を作る。
     *
     * @param  array<string, mixed>  $values
     */
    public static function fromValues(array $values): MeetingFields
    {
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
