<?php

namespace App\Queries\Records\Api;

use App\Support\AccessContext;

/**
 * レコードの詳細のサイドパネルに、後ろのモジュールが足す節(1 節 = 帯の 1 つの欄)を返す口(依存の逆転。形の正本 = docs の
 * 設計/業務データの入れ物/レコードの共通機能/詳細の画面/節の口.md の 1 節)。節を足すモジュールが自分の読み取りの層にこれを実装し
 * (1 節 1 クラス)、起動がタグ {@see self::TAG} で登録する。Records は登録の順に呼び、名札と中身の形を確かめる。
 *
 * 予期する失敗は実装が狭い型で捕まえて報告し、presence() は件数なし・section() は failed を返す。予期しない例外は投げたまま
 * (Records は握らない = 節の口.md の 2 節)。
 *
 * @phpstan-type RecordShowSectionLink array{label: string, url: string}
 * @phpstan-type RecordShowSectionDescriptor array{code: string, heading: string, shortHeading: string}
 * @phpstan-type RecordShowTableSection array{kind: 'table', count: int|null,
 *     columns: list<array{code: string, label: string, type: 'title'|'text'|'date'|'datetime'|'status'}>,
 *     rows: list<array{code: string, url: string|null,
 *         cells: list<array{text: string, tone: 'pending'|'returned'|'settled'|'rejected'|null}>}>,
 *     emptyText: string, more: RecordShowSectionLink|null}
 * @phpstan-type RecordShowListSection array{kind: 'list', count: int|null,
 *     items: list<array{code: string, title: string, url: string|null, note: string|null, at: string|null}>,
 *     emptyText: string, more: RecordShowSectionLink|null}
 * @phpstan-type RecordShowLinesSection array{kind: 'lines', count: int|null, note: string|null,
 *     items: list<array{time: string|null, label: string|null, text: string}>, emptyText: string}
 * @phpstan-type RecordShowFailedSection array{kind: 'failed'}
 * @phpstan-type RecordShowSectionPresence array{count: int|null}
 */
interface RecordShowSections
{
    /** 実装を束ねるコンテナのタグ */
    public const TAG = 'records.record-show-sections';

    /**
     * 節の名札(キー・正式の名前・帯の名前)。定数を返すだけで、DB を読まない(節の失敗の時も帯に名前を出すため)。
     *
     * @return RecordShowSectionDescriptor
     */
    public function descriptor(): array;

    /**
     * 節を帯に出すかと件数。出さないときは null。詳細の最初の応答で全実装について呼ぶので、件数を数えるだけにする。
     *
     * @return RecordShowSectionPresence|null
     */
    public function presence(AccessContext $context, string $moduleCode, int $recordId): ?array;

    /**
     * 節 1 つの中身。欄を開いた時(クエリ panel = この節の code)にだけ呼ぶ。出さないときは null。
     * Records はレコードの閲覧を確かめてから呼ぶ。実装は $context の主体が見てよいものだけを返す。
     *
     * @return RecordShowTableSection|RecordShowListSection|RecordShowLinesSection|RecordShowFailedSection|null
     */
    public function section(AccessContext $context, string $moduleCode, int $recordId): ?array;
}
