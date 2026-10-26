import { expect } from './test';
import type { Locator, Page } from '@playwright/test';
import {
    addedProjectStart,
    fillVersionTwoBaseDate,
    seededDate,
    todayInTokyo,
} from './seed-dates';
import { submitAndExpectUrl } from './submit';

// 案件の運用の画面の筋(zz-workflow-operations.spec.ts・zz-workflow-processing.spec.ts。docs の
// 設計/ワークフロー/案件の運用/画面/README.md の 9 節)が共有する、初期データの見本の案件(名指し)と、利用者の切替・表の行の部品。
// 見本の案件は初期データの作り直しで替わるので、案件の名前はここ 1 か所に置く。

/** デモユーザー切替の候補: 役どころ(切替の項目)と、切り替えた後の利用者メニューの表示 */
const USERS = {
    applicant: ['申請者', '佐々木 陽太（東京営業課）'],
    chief: ['上長(課長)', '中村 美咲（東京営業課）'],
    manager: ['部長', '高橋 誠（第一営業部）'],
    concurrent: ['兼務(総務・人事)', '吉田 達也（総務部）'],
    accounting: ['経理', '山田 恵（経理部）'],
} as const;

export type SwitchedUser = keyof typeof USERS;

/** 見本の案件(番号は初期データの申請の順) */
export const NEW_PRODUCT = 'プロジェクト計画の承認 新製品の発売計画'; // WF-000013(部長 = u0003 が保留中。期限 D − 4 で超過)
export const SECOND_QUARTER = 'プロジェクト計画の承認 第二四半期の営業計画'; // WF-000014(部長 = u0003)
export const TRAINING = 'プロジェクト計画の承認 社内研修の刷新'; // WF-000010(合議の承認 1 = u0009)
export const LOGIN_TEXT = '課題の対応の承認 ログイン画面の文言修正'; // WF-000016(課長の承認 = 担当変更の振替先 u0009)
export const INVENTORY_BUG = '課題の対応の承認 在庫システムの表示不具合'; // WF-000015(課長 = u0002 が承認待ち)

/**
 * 利用者メニューを開いて利用者を切り替え、切り替えた後の表示を待つ。from は押す前の利用者メニューの表示。
 */
export async function switchUser(page: Page, from: string, to: SwitchedUser) {
    const [role, label] = USERS[to];
    await page
        .getByRole('button', { name: `利用者メニュー：${from}`, exact: true })
        .click();
    await submitAndExpectUrl(
        page,
        page.getByRole('menu').getByRole('menuitem').filter({ hasText: role }),
    );
    await expect(
        page.getByRole('button', {
            name: `利用者メニュー：${label}`,
            exact: true,
        }),
    ).toBeVisible();
}

/**
 * ゲストから利用者に切り替えて、path を開く。
 */
export async function openAs(page: Page, user: SwitchedUser, path: string) {
    await page.goto('/');
    await switchUser(page, 'ゲスト', user);
    await page.goto(path);
}

/**
 * 申請一覧の表の、申請名の行(行の選択の欄は申請名で名付く)。
 */
export function caseRow(page: Page, table: string, name: string) {
    return page
        .getByRole('table', { name: table })
        .getByRole('row')
        .filter({ has: page.getByRole('link', { name, exact: true }) });
}

/**
 * 流す日の基準の日付(初期データの値で書いた YYYY-MM-DD)から今日までの日数(経過日数の期待)。
 */
export function daysSince(written: string): number {
    return (
        (Date.parse(todayInTokyo()) - Date.parse(seededDate(written))) /
        86_400_000
    );
}

/**
 * 参照の選択欄で、検索する語を打って候補を選び、候補の欄が閉じるのを待つ(閉じる途中の欄が残ったまま次の選択欄を開くと、検索の入力欄が 2 つになる)。
 */
export async function pickReference(
    page: Page,
    combobox: string,
    query: string,
    option: string,
) {
    await page.getByRole('combobox', { name: combobox, exact: true }).click();
    await page.getByPlaceholder('検索する語').fill(query);
    await page.getByRole('option', { name: option, exact: true }).click();
    await expect(page.getByPlaceholder('検索する語')).toHaveCount(0);
}

/**
 * 計画の申請書の業務データの欄(申請の画面の申請内容と、業務データの画面の登録・編集のフォームで共通)を埋める。
 */
export async function fillPlanFields(
    page: Page,
    form: Locator,
    projectName: string,
) {
    await form
        .getByRole('textbox', { name: 'プロジェクト名', exact: true })
        .fill(projectName);
    await pickReference(page, '担当部署', '東京営業', '東京営業課');
    await pickReference(page, '責任者', '中村', '中村 美咲');
    await form
        .getByRole('group', { name: '開始日', exact: true })
        .locator('input')
        .fill(addedProjectStart());
    await form
        .getByRole('textbox', { name: '予算(万円)', exact: true })
        .fill('100');
}

/**
 * 計画の申請の画面(フロー project_plan。申請基準日は版 2 の日 = 課長の承認・部長の承認)の入力を埋める。プロジェクト名がそのまま案件名の後ろに付く。
 */
export async function fillPlanApplication(
    page: Page,
    form: Locator,
    projectName: string,
) {
    await fillPlanFields(page, form, projectName);
    await fillVersionTwoBaseDate(form);
}

/**
 * 申請の画面の「申請」を押し、確かめのダイアログの「決定」で申請して、詳細へ移るまで待つ。
 */
export async function submitApplication(page: Page, form: Locator) {
    await form.getByRole('button', { name: '申請', exact: true }).click();
    await submitAndExpectUrl(
        page,
        page
            .getByRole('dialog', { name: '申請しますか？' })
            .getByRole('button', { name: '決定', exact: true }),
        /\/workflow\/cases\/\d+$/,
    );
}

/**
 * 申請の詳細の基本情報の 1 項目の値(項目名の dt の次の dd)。
 */
export function basicItem(page: Page, label: string) {
    return page
        .getByRole('region', { name: '基本情報' })
        .getByText(label, { exact: true })
        .locator('xpath=following-sibling::dd');
}
