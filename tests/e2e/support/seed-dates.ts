import type { Locator } from '@playwright/test';
import { expect } from './test';

// 初期データの日付は流す日からの相対で入る(docs の 設計/ワークフロー/案件の運用/初期データ.md 1.1・1.3)ので、E2E の期待も流す日から決める。
// 流す日は scripts/ci/front が 1 回だけ決めて SEED_DATE で流す側と共有する(流してから走るまでに東京の日付が変わっても、期待がずれない)。
// SEED_DATE を置かずに spec だけ走らせたときは、流した直後とみなして走らせる時の東京の今日にする。

/** 基準の日(Database\Seeders\SeedingDates::BASE と同じ値) */
const BASE_DAY = '2026-10-15';

/**
 * 今日の Asia/Tokyo の日付(YYYY-MM-DD。申請基準日の既定)。
 */
export function todayInTokyo(): string {
    return new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Tokyo' }).format(
        new Date(),
    );
}

/**
 * 初期データを流した日(YYYY-MM-DD)。
 */
function seedingDay(): string {
    return process.env.SEED_DATE ?? todayInTokyo();
}

/**
 * 基準の日の値で書いた日付(YYYY-MM-DD。seeder の定数と、E2E がそれと前後をそろえて入れる日付)の、流す日からの相対の日付。
 */
export function seededDate(written: string): string {
    const offset = Date.parse(seedingDay()) - Date.parse(BASE_DAY);

    return new Date(Date.parse(written) + offset).toISOString().slice(0, 10);
}

/**
 * 流す日の n 日前(初期データの見本の処理の時刻「D − n」= docs の 設計/ワークフロー/案件の運用/初期データ.md 4 節)が、流す日と同じ月か。
 * ホームの今月の処理済みに入る見本の処理は、流す日の日付で変わるので、期待をこれで数える。
 */
export function isInSeedingMonth(daysBefore: number): boolean {
    const day = seedingDay();
    const before = new Date(Date.parse(day) - daysBefore * 86_400_000);

    return before.toISOString().slice(0, 7) === day.slice(0, 7);
}

/**
 * seededDate の画面の表示の形(YYYY/MM/DD。docs の 設計/業務データの入れ物/画面/README.md 1.3)。
 */
export function shownDate(written: string): string {
    return seededDate(written).replaceAll('-', '/');
}

/**
 * E2E の筋が申請で足すプロジェクトの開始日。初期データの 3 件(records.spec.ts の絞り込みの上限)より後に置く。
 */
export function addedProjectStart(): string {
    return seededDate('2027-05-06');
}

/**
 * 計画のフローの版 2 の期間の日(WorkflowSeeder::DATES の stage2_plan_base_date が初期データに入った日。見本の計画の申請基準日)。
 */
export function versionTwoDay(): string {
    return seededDate('2026-09-01');
}

/**
 * 申請の入力の申請基準日を版 2 の日にする。申請基準日を変えると次の処理対象者を部分再読み込みで引き直すので、
 * それが済むまで待つ(利用者と同じ順。古い next で送らない)。済んだ印は URL の base_date(応答で props と一緒に替わる)。
 * waitForLoadState は最初の読み込みで済んだ状態をすぐ返し、部分再読み込みを待たない。
 */
export async function fillVersionTwoBaseDate(form: Locator): Promise<void> {
    await form
        .getByRole('group', { name: '申請基準日', exact: true })
        .locator('input[type="date"]')
        .fill(versionTwoDay());
    await expect(form.page()).toHaveURL(hasBaseDate(versionTwoDay()));
}

/**
 * URL の base_date が day かを見る述語(申請基準日の部分再読み込みの応答が済んだ印。toHaveURL に渡す)。
 */
export function hasBaseDate(day: string): (url: URL) => boolean {
    return (url) => url.searchParams.get('base_date') === day;
}
