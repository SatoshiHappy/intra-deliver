<?php

namespace App\Actions\Records;

use App\Actions\Records\Api\RecordInput;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Records\RecordParty;
use App\Queries\Records\RecordRowAccessValues;

/**
 * 参照制限の入力(項目でない 2 つのキー)を読み、確かめ、合わせた値を作る。項目の値と同じ要求で確かめて一緒に返すため、書く部品
 * {@see RecordRowAccessWriter} と分けて保存の前に置く(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md の 9.2、
 * 結果の表 = 設計/業務データの入れ物/認可とメニュー/行の権限.md の 2.4)。
 *
 * @phpstan-import-type Party from RecordParty
 * @phpstan-import-type StoredRowAccess from RecordRowAccessValues
 */
class RecordRowAccessInput
{
    /** 参照制限の無いレコードの参照者の文言(W3・W8 = 失敗と定義の変更.md 10 節) */
    public const NOT_RESTRICTED = '参照を制限しないレコードには、参照者を設定できません';

    /** 参照者の誤りの文言の {項目名}(失敗と定義の変更.md 10 節) */
    private const NAME = '参照者';

    public function __construct(
        private RecordRowAccessValues $values,
        private ReferenceTargets $targets,
    ) {}

    /**
     * 元の値(入力に無いキーの値)。
     *
     * @param  RecordModule  $module  親の無い業務モジュール(登録の初期値の既定は共有の鍵の後に読んだもの)
     * @param  Record|null  $record  更新する今のレコード(行の鍵を取ったもの。null = 登録)
     * @return StoredRowAccess
     */
    public function original(RecordModule $module, ?Record $record): array
    {
        return $record === null ? $this->values->initial($module) : $this->values->stored($record);
    }

    /**
     * 参照者の入力のうち、元の参照者に無い相手(形と数の確かめを通るときだけ。通らない入力は問い合わせに使わない)。
     *
     * @param  StoredRowAccess  $original
     * @return list<Party>
     */
    public function newViewers(array $original, RecordInput $input): array
    {
        $viewers = $this->viewers($input->rowAccessViewers);

        if ($viewers === null || RecordInputReader::countError(self::NAME, self::keys($viewers)) !== null) {
            return [];
        }

        $originalKeys = self::keys($original['viewers']);

        return array_values(array_filter(
            $viewers,
            fn (array $viewer) => ! in_array(RecordParty::key($viewer), $originalKeys, true),
        ));
    }

    /**
     * 3・4 の誤りを $errors に足し(キー → 最初の文言)、5 の合わせた値を返す(誤りがあれば呼び手が書かずに返す)。
     * 呼び手が {@see self::newViewers()} の相手を {@see ReferenceTargets::prepareParties()} で確かめておく。
     *
     * @param  StoredRowAccess  $original
     * @param  array<string, string>  $errors
     * @return StoredRowAccess
     */
    public function read(array $original, RecordInput $input, ReferenceCheck $check, array &$errors): array
    {
        $restricted = $input->rowAccessRestricted;

        if ($restricted !== null && ! is_bool($restricted)) {
            $errors['row_access_restricted'] ??= '参照制限の値が正しくありません';
        }

        $viewers = $this->checkedViewers($original, $input, $check, $errors);
        $merged = is_bool($restricted) ? $restricted : $original['restricted'];

        return ['restricted' => $merged, 'viewers' => match (true) {
            ! $merged => [],
            $viewers !== null => $viewers,
            $original['restricted'] => $original['viewers'],
            default => [],
        }];
    }

    /**
     * 参照者の入力の確かめ(未指定・形の誤りは null を返す)。
     *
     * @param  StoredRowAccess  $original
     * @param  array<string, string>  $errors
     * @return list<Party>|null
     */
    private function checkedViewers(array $original, RecordInput $input, ReferenceCheck $check, array &$errors): ?array
    {
        $raw = $input->rowAccessViewers;

        if ($raw === null) {
            return null;
        }

        $viewers = $this->viewers($raw);

        if ($viewers === null) {
            $errors += $this->shapeErrors($raw);

            return null;
        }

        $restricted = $input->rowAccessRestricted ?? $original['restricted'];

        if ($restricted === false && $viewers !== []) {
            $errors['row_access_viewers'] ??= self::NOT_RESTRICTED;
        }

        $countError = RecordInputReader::countError(self::NAME, self::keys($viewers));

        if ($countError !== null) {
            $errors['row_access_viewers'] ??= $countError;

            return $viewers;
        }

        $this->rejectInvalidNew($original, $viewers, $check, $errors);

        return $viewers;
    }

    /**
     * 新しく足す相手のうち、d に有効でない(無い相手を含む)ものの誤りを添字のキーで足す(今の相手は確かめ直さない)。
     *
     * @param  StoredRowAccess  $original
     * @param  list<Party>  $viewers
     * @param  array<string, string>  $errors
     */
    private function rejectInvalidNew(array $original, array $viewers, ReferenceCheck $check, array &$errors): void
    {
        $originalKeys = self::keys($original['viewers']);

        foreach ($viewers as $index => $viewer) {
            $isNew = ! in_array(RecordParty::key($viewer), $originalKeys, true);

            if ($isNew && ! $this->targets->acceptsParty($viewer, $check)) {
                $errors["row_access_viewers.{$index}"] ??= self::NAME.'の値が正しくありません';
            }
        }
    }

    /**
     * 参照者の入力を相手の並びに読む(配列でない・相手の形でない要素があれば null)。
     *
     * @return list<Party>|null
     */
    private function viewers(mixed $raw): ?array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            return null;
        }

        $viewers = array_map(RecordParty::of(...), $raw);

        return in_array(null, $viewers, true) ? null : $viewers;
    }

    /**
     * 形の誤り(配列でない = row_access_viewers、相手の形でない要素 = row_access_viewers.<添字>)。
     *
     * @return array<string, string>
     */
    private function shapeErrors(mixed $raw): array
    {
        $message = self::NAME.'の値が正しくありません';

        if (! is_array($raw) || ! array_is_list($raw)) {
            return ['row_access_viewers' => $message];
        }

        $errors = [];

        foreach ($raw as $index => $element) {
            if (RecordParty::of($element) === null) {
                $errors["row_access_viewers.{$index}"] = $message;
            }
        }

        return $errors;
    }

    /**
     * 相手の鍵の並び({@see RecordParty::key()})。
     *
     * @param  list<Party>  $parties
     * @return list<string>
     */
    private static function keys(array $parties): array
    {
        return array_map(RecordParty::key(...), $parties);
    }
}
