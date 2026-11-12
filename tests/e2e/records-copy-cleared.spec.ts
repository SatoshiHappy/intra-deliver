import { expect, test } from './support/test';
import type { Locator, Page } from '@playwright/test';
import { rewriteProps } from './support/rewrite-props';

// 業務データの画面の複製の「空にした項目の知らせ」(docs の 設計/業務データの入れ物/レコードの共通機能/複製.md の 2 節と、
// 設計/ワークフロー/申請の周辺/画面.md の 5 節の「空にした項目の知らせ」)。ゲストだけで成り立ち、読むだけでレコードを作らない。
// 初期データのプロジェクトは使えない値を持たないので、空にされる値の知らせは、開いた時の応答の clearedFields と値を書き換えて作る
// (空にする確かめは Feature テスト)。
test.use({ viewport: { width: 1280, height: 720 } });

const NOTICE_START = '今は使えない値を空にした項目が';
const NOTICE =
    '今は使えない値を空にした項目が 2 件あります。⚠ の付いた欄を入力してください。';
const REASON = '元のレコードの値は、今は使えないため空にしました。';

type CopyProps = {
    copySource: {
        values: Record<string, unknown>;
        details: Record<string, { values: Record<string, unknown> }[]>;
        clearedFields: unknown[];
    };
};

/** 初期データのプロジェクトの詳細から「複製」を押し、登録の画面(クエリ copy 付き)の URL を返す */
async function openCopyOfFirstProject(page: Page): Promise<string> {
    await page.goto('/records/data/project');
    await page
        .getByRole('main')
        .getByRole('link', { name: '社内ポータル刷新', exact: true })
        .first()
        .click();
    await page.getByRole('link', { name: '複製', exact: true }).click();
    await expect(page).toHaveURL(/\/records\/data\/project\/create\?copy=\d+$/);

    return page.url();
}

/** 責任者(親の欄)とマイルストーンの 2 行目の工数(明細のセル)を空にした形で開く応答に書き換える */
async function rewriteWithClearedValues(page: Page) {
    await rewriteProps<CopyProps>(
        page,
        (url) =>
            url.pathname === '/records/data/project/create' &&
            url.searchParams.has('copy'),
        ({ copySource }) => {
            copySource.values.manager = null;
            copySource.details.milestones[1].values.effort = null;
            copySource.clearedFields = [
                {
                    kind: 'field',
                    field: 'manager',
                    detail: null,
                    row: null,
                    name: '責任者',
                },
                {
                    kind: 'cell',
                    field: 'effort',
                    detail: 'milestones',
                    row: 1,
                    name: 'マイルストーンの工数(人日)',
                },
            ];
        },
    );
}

/** 理由の 1 行(⚠ 付き)が、親の欄の下と明細の表の上に 1 行ずつ出る */
async function expectReasonLines(page: Page) {
    const reasons = page.getByText(REASON, { exact: true });
    await expect(reasons).toHaveCount(2);
    await expect(reasons.first()).toBeVisible();
    await expect(reasons.last()).toBeVisible();
    await expect(reasons.locator('svg')).toHaveCount(2);
}

/** 空にした欄(責任者)と明細のセル(2 行目の工数)が、理由を入力の説明として持つ */
async function expectClearedInputs(form: Locator) {
    await expect(
        form.getByRole('combobox', { name: '責任者', exact: true }),
    ).toHaveAccessibleDescription(REASON);
    await expect(
        form.getByRole('textbox', { name: '工数(人日)(2 行目)', exact: true }),
    ).toHaveAccessibleDescription(REASON);
    await expect(
        form.getByRole('textbox', { name: '工数(人日)(1 行目)', exact: true }),
    ).not.toHaveAccessibleDescription(REASON);
}

test('複製の登録の画面は、空にされる値が無ければ知らせを出さず、空にした値があれば件数の 1 行と欄のそばの理由を出す', async ({
    page,
}) => {
    const url = await openCopyOfFirstProject(page);
    const form = page.getByRole('form', { name: 'プロジェクトの入力' });
    await expect(
        form.getByRole('textbox', {
            name: 'プロジェクト名',
            exact: true,
        }),
    ).toHaveValue('社内ポータル刷新');
    await expect(page.getByText(NOTICE_START, { exact: false })).toHaveCount(0);
    await expect(page.getByText(REASON, { exact: true })).toHaveCount(0);

    await rewriteWithClearedValues(page);
    await page.goto(url);

    await expect(page.getByText(NOTICE, { exact: true })).toBeVisible();
    await expect(
        page.getByText(NOTICE, { exact: true }).locator('svg'),
    ).toHaveCount(1);
    await expectReasonLines(page);
    await expectClearedInputs(form);
});
