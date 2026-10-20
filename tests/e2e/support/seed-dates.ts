import type { Locator } from '@playwright/test';

// 初期データの日付は流す日からの相対で入る(docs の 設計/ワークフロー/案件の運用/初期データ.md 1.1・1.3)ので、E2E の期待も流す日から決める。
// E2E は SEED_DATE を置かずに流すので、流す日 = 流した時の Asia/Tokyo の今日。E2E は流した直後に走るので、走らせる時の東京の今日を流す日とみなす
// (流してから走るまでの間に東京の日付が変わると、日付の期待が 1 日ずれる)。

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
 * 基準の日の値で書いた日付(YYYY-MM-DD。seeder の定数と、E2E がそれと前後をそろえて入れる日付)の、流す日からの相対の日付。
 */
export function seededDate(written: string): string {
    const offset = Date.parse(todayInTokyo()) - Date.parse(BASE_DAY);

    return new Date(Date.parse(written) + offset).toISOString().slice(0, 10);
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
 * それが済むまで待つ(利用者と同じ順。古い next で送らない)。
 */
export async function fillVersionTwoBaseDate(form: Locator): Promise<void> {
    await form
        .getByRole('group', { name: '申請基準日', exact: true })
        .locator('input[type="date"]')
        .fill(versionTwoDay());
    await form.page().waitForLoadState('networkidle');
}
