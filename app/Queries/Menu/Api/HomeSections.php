<?php

namespace App\Queries\Menu\Api;

use App\Support\AccessContext;

/**
 * ホームに、モジュールが足す節を返す口(依存の逆転 = docs の 設計/コード構成/README.md 3 節、形の正本 = 設計/メニュー/ホーム.md 6.2)。
 * 節を足すモジュールが自分の読み取りの層にこれを実装し(1 節 1 クラス)、起動(App\Providers)がタグ {@see self::TAG} で登録する。
 * ホームの入口(App\Http\Controllers\Web\Menu\HomeController)が、タグで束ねた実装を登録の順に呼び、null でない節をページの props sections に渡す。
 *
 * 節の形 = 種類(kind)で判別する共用体(下の型の別名。実装は @phpstan-import-type で使う)。描き方の種類は Menu が持ち、各モジュールはデータだけを返す。
 * lead はあいさつ欄(hero)にだけ、ほかの種類はあいさつ欄の外(top・main・side)にだけ置く。code は全節で一意の <モジュール名の小文字>-<節の名前>、
 * 行・項目の code は節の中で一意。名前・コード・日時の値は実装が文字列にする(表の date は YYYY-MM-DD、datetime は ISO 8601)。
 *
 * Menu の公開の口(保証の正本 = docs の 設計/ワークフロー/業務データとの接続/公開の口.md 8.1 の表)。実装は鍵を取らず、例外を投げない
 * (節を作れないときは null。投げればホーム全体が 500 になる)。
 *
 * @phpstan-type HomeAction array{label: string, url: string, method: 'get'|'post', data: array<string, string|int>}
 * @phpstan-type HomeLeadSection array{code: string, kind: 'lead', area: 'hero', text: string, action: HomeAction|null}
 * @phpstan-type HomeCountersSection array{code: string, kind: 'counters', area: 'top'|'main'|'side', heading: string, items: list<array{code: string, label: string, icon: string, count: int, note: string|null, url: string}>}
 * @phpstan-type HomeTableSection array{code: string, kind: 'table', area: 'top'|'main'|'side', heading: string, columns: list<array{code: string, label: string, type: 'title'|'text'|'date'|'datetime'|'status'}>, rows: list<array{code: string, url: string, cells: list<array{text: string, tone: 'pending'|'returned'|'settled'|'rejected'|null, mark: '✓'|'×'|null}>}>, emptyText: string, more: array{label: string, url: string}|null}
 * @phpstan-type HomeTilesSection array{code: string, kind: 'tiles', area: 'top'|'main'|'side', heading: string, items: list<array{code: string, label: string, icon: string|null, url: string, isExternal: bool}>}
 * @phpstan-type HomeRoutesSection array{code: string, kind: 'routes', area: 'top'|'main'|'side', heading: string, items: list<array{code: string, title: string, url: string, note: string, steps: list<array{code: string, label: string, state: 'done'|'current'|'returned'|'upcoming'|'skipped', stateLabel: string}>, action: HomeAction|null}>, emptyText: string, more: array{label: string, url: string}|null}
 * @phpstan-type HomePeopleSection array{code: string, kind: 'people', area: 'top'|'main'|'side', heading: string, noteLabel: string, items: list<array{code: string, name: string|null, affiliation: string|null, role: string, note: string, action: HomeAction}>}
 */
interface HomeSections
{
    /** 実装を束ねるコンテナのタグ */
    public const TAG = 'menu.home-sections';

    /**
     * ホームに出す節 1 つ。出さないときは null。形は kind で判別する共用体。
     *
     * @return HomeLeadSection|HomeCountersSection|HomeTableSection|HomeTilesSection|HomeRoutesSection|HomePeopleSection|null
     */
    public function section(AccessContext $context): ?array;
}
