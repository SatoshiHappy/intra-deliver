import { isInSeedingMonth } from './seed-dates';

// ホームの今月の処理済み(docs の 設計/ワークフロー/画面.md 7 節)の期待の組み立て。初期データの見本の処理の時刻は流す日からの相対
// (設計/ワークフロー/案件の運用/初期データ.md 4.2・4.3)なので、今月に入る処理は流す日の日付で変わり、期待もそこから数える。

/** 今月の処理済みの種別(内訳の並び)と、表の結果の列の札(印と語) */
export const RESULT_BADGES = {
    承認: /^✓\s*承認$/,
    承認終了: /^✓\s*承認終了$/,
    否認: /^×\s*否認$/,
    差戻し: /^差戻し$/,
} as const;

export type ResultKind = keyof typeof RESULT_BADGES;

/**
 * 上長(u0002)の見本の処理のうち今月の処理済みに数える種別。[流す日の何日前, 種別] を表の並び = 処理日時の降順、同じ秒は履歴の
 * id の降順(docs の 設計/ワークフロー/画面.md 7 節)に並べる(担当変更は数えない。WF-000013 と WF-000003 は同じ D − 9 10:30 で、
 * seeder が後に入れた WF-000013 が先)。
 */
const CHIEF_SAMPLE_PROCESSES: ReadonlyArray<readonly [number, ResultKind]> = [
    [2, '承認'], // WF-000014
    [3, '承認'], // WF-000010
    [4, '差戻し'], // WF-000004
    [7, '承認'], // WF-000009
    [9, '承認'], // WF-000013
    [9, '承認'], // WF-000003
    [11, '差戻し'], // WF-000005
    [14, '承認'], // WF-000011
    [19, '差戻し'], // WF-000008
    [24, '承認終了'], // WF-000007
    [29, '否認'], // WF-000006
    [34, '承認'], // WF-000012
    [39, '承認'], // WF-000001
];

/**
 * 上長の今月の処理済みの種別(表の並び)。名前の順で先に走る workflow-route.spec.ts の上長の承認 1 件(今日)と、
 * 見本の処理のうち流す日と同じ月のもの。
 */
export function chiefProcessedThisMonth(): ResultKind[] {
    const inMonth = CHIEF_SAMPLE_PROCESSES.filter(([n]) => isInSeedingMonth(n));

    return ['承認', ...inMonth.map(([, kind]) => kind)];
}

/**
 * 今月の処理済みの表に出る先頭の 5 行の、結果の列の札。
 */
export function latestBadges(kinds: ResultKind[]): RegExp[] {
    return kinds.slice(0, 5).map((kind) => RESULT_BADGES[kind]);
}

/**
 * 流す日の n 日前の見本の処理の行が今月の処理済みの表に出るなら、その結果の列の札(出ないなら空)。
 */
export function badgesIfThisMonth(days: number, kind: ResultKind): RegExp[] {
    return isInSeedingMonth(days) ? [RESULT_BADGES[kind]] : [];
}

/**
 * 今月の処理済みの件数カードの全文(件数と、0 の種別を出さない内訳の 1 行)。
 */
export function processedCardText(kinds: ResultKind[]): RegExp {
    const words = (Object.keys(RESULT_BADGES) as ResultKind[])
        .map((kind) => [kind, kinds.filter((k) => k === kind).length] as const)
        .filter(([, count]) => count > 0)
        .map(([kind, count]) => `${kind}\\s*${count}`);

    return new RegExp(`^今月の処理済み${kinds.length}件${words.join('・')}$`);
}
