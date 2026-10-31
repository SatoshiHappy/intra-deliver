import { expect } from './test';
import type { Locator, Page } from '@playwright/test';
import { submitAndExpectUrl } from './submit';
import { fillPlanFields, pickReference } from './workflow-operations';
import { APPLY_FORM, FLOW_CODE, recordIdOf } from './workflow-record-apply';

// 申請対象からの申請の画面の筋(名前に target を含む 3 つの spec。docs の 設計/ワークフロー/申請の周辺/画面.md の 9 節の「申請対象から」)が共有する、
// 初期データのプロジェクト 2 件(申請対象)と、申請の画面の確かめ・目視用の写し。

export const APPLY_URL = `/workflow/flows/${FLOW_CODE}/apply`;
/** 初期データのプロジェクト(マイルストーン 4 行・予算 1200・状態「進行中」)と、もう 1 件(マイルストーン 2 行・予算 300・状態「計画中」。新規の画面の状態の既定値は「計画中」) */
export const SOURCE = '社内ポータル刷新';
export const OTHER = '新規顧客開拓キャンペーン';
export const APPLICATION_TARGET_ERROR = '申請対象の値を取得できませんでした。';
export const CONFIRM_TITLE = '申請対象の値を反映しますか？';

/** 申請の画面の入力のフォーム */
export function applyForm(page: Page): Locator {
    return page.getByRole('form', { name: APPLY_FORM });
}

/** 申請対象の欄(参照の選択欄) */
export function applicationTargetField(form: Locator): Locator {
    return form.getByRole('combobox', { name: 'プロジェクト', exact: true });
}

/** 明細「マイルストーン」の表の行(見出しの行を含む) */
export function milestoneRows(form: Locator): Locator {
    return form
        .getByRole('group', { name: 'マイルストーン', exact: true })
        .getByRole('row');
}

/** 申請書の欄「プロジェクト名」「予算(万円)」 */
export function nameField(form: Locator): Locator {
    return form.getByRole('textbox', { name: 'プロジェクト名', exact: true });
}

export function budgetField(form: Locator): Locator {
    return form.getByRole('textbox', { name: '予算(万円)', exact: true });
}

/**
 * 業務データの画面でプロジェクトの一覧から詳細を開く。
 */
export async function openProject(page: Page, name: string): Promise<void> {
    await page.goto('/records/data/project');
    await page
        .getByRole('region', { name: 'プロジェクトの一覧' })
        .getByRole('link', { name, exact: true })
        .click();
    await expect(
        page.getByRole('heading', { level: 1, name, exact: true }),
    ).toBeVisible();
}

/**
 * 申請対象の欄で、検索する語を打って候補のプロジェクトを選ぶ。
 */
export async function pickApplicationTarget(
    page: Page,
    query: string,
    name: string,
): Promise<void> {
    await pickReference(page, 'プロジェクト', query, name);
}

/**
 * 写した値が申請の画面に入っている(プロジェクト名・予算・マイルストーンの行の数。行の数は見出しの行を含めない)。
 */
export async function expectSnapshot(
    form: Locator,
    values: { name: string; budget: string; rows: number },
): Promise<void> {
    await expect(nameField(form)).toHaveValue(values.name);
    await expect(budgetField(form)).toHaveValue(values.budget);
    await expect(milestoneRows(form)).toHaveCount(values.rows + 1);
    await expect(applicationTargetField(form)).toContainText(values.name);
}

/**
 * プロジェクトの業務モジュールの参照制限の既定と、プロジェクトの親の無いレコードのチェックを付ける(関係者の印 = 責任者)tinker の文と、
 * 元に戻す文。作成者と責任者だけが見られる(認可設定の画面と別の筋が、この業務モジュールの参照制限を変えるのはこの 2 つの筋だけ)。
 */
const PROJECT_RESTRICTION = (restricted: string) =>
    [
        '$id = DB::table("record_modules")->where("code", "project")->value("id");',
        `DB::table("record_modules")->where("id", $id)->update(["row_access_restricted_by_default" => ${restricted}]);`,
        'DB::table("records")->where("record_module_id", $id)->whereNull("parent_record_id")',
        `->update(["row_access_restricted" => ${restricted}]);`,
        'DB::table("record_fields")->where("record_module_id", $id)->where("code", "manager")',
        `->update(["grants_row_access" => ${restricted}]);`,
    ].join('');

export const RESTRICT_PROJECTS = PROJECT_RESTRICTION('true');
export const RESTORE_PROJECTS = PROJECT_RESTRICTION('false');

/** 利用者のコードから id を出す tinker の文 */
export const USER_ID = (code: string) =>
    `echo App\\Models\\Master\\User::query()->where("code", "${code}")->value("id");`;

/**
 * 業務データの画面でプロジェクトを 1 件登録して詳細へ移り、その id を返す(作成者は今の利用者。責任者は中村 美咲)。
 */
export async function createProject(page: Page, name: string): Promise<string> {
    await page.goto('/records/data/project/create');
    const form = page.getByRole('form', { name: 'プロジェクトの入力' });
    await fillPlanFields(page, form, name);
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '登録', exact: true }),
        /\/records\/data\/project\/\d+$/,
    );

    return recordIdOf(page.url());
}

/**
 * 申請したあとのプロジェクトの詳細(開いている画面)は、予算が元のまま(1,200)で、押せない形も理由の 1 行も無い。
 */
export async function expectProjectUntouched(page: Page): Promise<void> {
    const main = page.getByRole('main');
    await expect(
        main.getByText('1,200', { exact: true }).first(),
    ).toBeVisible();
    await expect(main.getByText('1,500', { exact: true })).toHaveCount(0);
    await expect(main.getByText('申請済みのため変更できません')).toHaveCount(0);
    await expect(
        main.getByRole('link', { name: '編集', exact: true }),
    ).toBeVisible();
    await expect(
        main.getByRole('link', { name: '申請する', exact: true }),
    ).toBeVisible();
    await expect(
        main.getByRole('button', { name: '削除', exact: true }),
    ).toBeEnabled();
}

/**
 * 写す前の確かめのダイアログの中身(OTHER の値を直した状態から SOURCE を選んだとき): 題の下の文、項目・今の値・反映する値の行、
 * 明細は「今の行数 → 反映する行数」の 1 行で、開くと今の行と反映する行の 2 つの表。
 */
export async function expectConfirmRows(
    page: Page,
    dialog: Locator,
): Promise<void> {
    await expect(dialog).toContainText(
        '次の項目の値が、申請対象の値に変わります。',
    );
    const row = (item: string) =>
        dialog.getByRole('row').filter({
            has: page.getByRole('cell', { name: item, exact: true }),
        });
    await expect(row('プロジェクト名')).toContainText('E2E で直した名前');
    await expect(row('プロジェクト名')).toContainText(SOURCE);
    await expect(row('予算(万円)')).toContainText('300');
    await expect(row('予算(万円)')).toContainText('1,200');
    // 明細は「今の行数 → 反映する行数」の 1 行で、開くと今の行と反映する行の 2 つの表
    const details = row('マイルストーン');
    await expect(details).toContainText('2 行 → 4 行');
    await details.getByRole('button', { name: '2 行 → 4 行' }).click();
    await expect(details).toContainText('今の行');
    await expect(details).toContainText('反映する行');
    await expect(details).toContainText('対象リスト作成');
    await expect(details).toContainText('要件定義');
}
