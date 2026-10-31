<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRowAccessViewer;
use DateTimeInterface;

/**
 * レコードの参照制限(チェックと参照者)の今の値を読む 1 か所(docs の 設計/業務データの入れ物/認可とメニュー/行の権限.md 2.4 の
 * 「レコードの参照制限」、ADR 0026)。参照者の行はチェックが付いているときだけ読み、外れなら空(書き込みの元の値・版の写し・詳細・編集の初期値・
 * 複製が同じ読み方を使う)。判定はしない(呼び手が済ませる)。明細の行は持たない(呼び手が親の無いレコードを渡す)。
 *
 * @phpstan-import-type Party from RecordParty
 * @phpstan-import-type PartyValue from RecordValueReader
 *
 * @phpstan-type StoredRowAccess array{restricted: bool, viewers: list<Party>}
 * @phpstan-type ShownRowAccess array{restricted: bool, viewers: list<PartyValue>}
 */
class RecordRowAccessValues
{
    public function __construct(private RecordValueReader $reader) {}

    /**
     * 登録の初期値(チェック = 業務モジュールの参照制限の既定、参照者 = 空 = 業務の画面.md 2.2。登録の画面と関連レコードのダイアログ)。
     *
     * @param  RecordModule  $module  親の無い業務モジュール
     * @return array{restricted: bool, viewers: list<never>}
     */
    public function initial(RecordModule $module): array
    {
        return ['restricted' => $module->row_access_restricted_by_default, 'viewers' => []];
    }

    /**
     * 保存された形(参照者は相手の {type, id} を sort_key の順)。
     *
     * @return StoredRowAccess
     */
    public function stored(Record $record): array
    {
        if (! $record->row_access_restricted) {
            return ['restricted' => false, 'viewers' => []];
        }

        $viewers = RecordRowAccessViewer::query()->where('record_id', $record->id)->orderBy('sort_key')->get();

        return [
            'restricted' => true,
            'viewers' => array_values($viewers->map(fn (RecordRowAccessViewer $viewer) => $viewer->party())->all()),
        ];
    }

    /**
     * 画面の値(参照者は相手の種類と参照の値 = 型「ユーザーと組織」の値と同じ読み方。今は無い相手は id を残して code・label が null)。
     *
     * @return ShownRowAccess
     */
    public function shown(Record $record, DateTimeInterface $date, string $localeCode): array
    {
        $stored = $this->stored($record);

        return [
            'restricted' => $stored['restricted'],
            'viewers' => $this->parties($stored['viewers'], $date, $localeCode),
        ];
    }

    /**
     * 相手の並びを画面の値にする(更新履歴の差分の参照者も同じ形にする)。
     *
     * @param  list<Party>  $parties
     * @return list<PartyValue>
     */
    public function parties(array $parties, DateTimeInterface $date, string $localeCode): array
    {
        $labels = $this->reader->targetValues($parties, null, $date, $localeCode);

        return array_map(function (array $party) use ($labels): array {
            $value = $labels[$this->reader->targetKey($party)]
                ?? ['id' => $party['id'], 'code' => null, 'label' => null, 'affiliation' => null];
            /** @var PartyValue $shown */
            $shown = $this->reader->withKind(RecordFieldType::UserOrDepartment, $party, $value);

            return $shown;
        }, $parties);
    }
}
