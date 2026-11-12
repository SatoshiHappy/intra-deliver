import { expect, test } from '../support/test';
import type { Locator, Page } from '@playwright/test';
import { fillVersionTwoBaseDate } from '../support/seed-dates';
import {
    basicItem,
    openAs,
    submitApplication,
} from '../support/workflow-operations';
import {
    APPLY_URL,
    SOURCE,
    applyForm,
    budgetField,
    expectSnapshot,
    expectProjectUntouched,
    openProject,
} from '../support/workflow-target-apply';

// 申請対象からの申請の画面(業務データの詳細から。入口が 1 つ)。筋の正本 = docs の 設計/ワークフロー/申請の周辺/画面.md の 9 節の「申請対象から」。
// 初期データでプロジェクトを申請対象にするフローは 1 つなので、2 つ目のフローを足す 2-choose より前に置く(流す順は tests/e2e/demo/groups.ts の並び)。
test.use({ viewport: { width: 1280, height: 720 } });

test('プロジェクトの詳細の「申請する」→ 申請の画面に値が写る → 値を直して申請 → プロジェクトの詳細は変わらず、押せない形も無い', async ({
    page,
}) => {
    await openAs(page, 'applicant', '/');
    await openProject(page, SOURCE);
    const main = page.getByRole('main');
    await expect(
        main.getByText('1,200', { exact: true }).first(),
    ).toBeVisible();

    // 入口が 1 つ(初期データ)なので、ダイアログを経ずに申請の画面へ(クエリ target は開いた直後に URL から外れる)
    await main.getByRole('link', { name: '申請する', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`${APPLY_URL}$`));

    // 見出しの下の 1 行は今の申請対象の欄で決める。申請対象の欄にそのレコードが入り、申請内容に写した値が入る
    await expect(
        page.getByText(`${SOURCE}を申請対象にして申請します。`),
    ).toBeVisible();
    const form = applyForm(page);
    await expectSnapshot(form, { name: SOURCE, budget: '1200', rows: 4 });
    await expect(
        form.getByRole('textbox', { name: '名称(1 行目)', exact: true }),
    ).toHaveValue('要件定義');
    // 新規の申請と同じボタン(キャンセルはレコードの詳細へ。一時保存もある)
    await expect(
        form.getByRole('button', { name: '一時保存', exact: true }),
    ).toBeEnabled();

    // 値を直して申請(申請基準日を変える部分の読み直しの後も、申請対象の欄と見出しの 1 行は残る)
    await budgetField(form).fill('1500');
    await fillVersionTwoBaseDate(form);
    await expect(
        page.getByText(`${SOURCE}を申請対象にして申請します。`),
    ).toBeVisible();
    await submitApplication(page, form);
    await expect(page.getByText('申請しました', { exact: true })).toBeVisible();
    await expect(await basicItem(page, '申請者')).toHaveText('佐々木 陽太');

    // 申請対象のプロジェクトは書き換わらず、押せない形も無い(持ち主は申請書のレコードだけ)
    await openProject(page, SOURCE);
    await expectProjectUntouched(page);
});

test('開いた直後に URL から target が外れ、キャンセルはプロジェクトの詳細へ。ブラウザで再読み込みすると写した値と 1 行が消えた新規の申請になる', async ({
    page,
}) => {
    await openAs(page, 'applicant', '/');
    await openProject(page, SOURCE);
    const projectUrl = page.url();
    await page
        .getByRole('main')
        .getByRole('link', { name: '申請する', exact: true })
        .click();
    await expect(page).toHaveURL(new RegExp(`${APPLY_URL}$`));
    const form = applyForm(page);
    await expectSnapshot(form, { name: SOURCE, budget: '1200', rows: 4 });
    await expect(
        form.getByRole('link', { name: 'キャンセル', exact: true }),
    ).toHaveAttribute('href', new URL(projectUrl).pathname);

    await page.reload();

    await expect(
        page.getByText('内容を入力して申請します。', { exact: false }),
    ).toBeVisible();
    await expect(budgetField(form)).toHaveValue('');
    await expect(
        page.getByText(`${SOURCE}を申請対象にして申請します。`),
    ).toHaveCount(0);
});

/** 業務レコードの詳細の「申請する」から、クエリ target 付きで申請の画面を開く(開いた直後に target が外れるのを待つ) */
async function openApplyFromProject(page: Page): Promise<Locator> {
    await openAs(page, 'applicant', '/');
    await openProject(page, SOURCE);
    await page
        .getByRole('main')
        .getByRole('link', { name: '申請する', exact: true })
        .click();
    await expect(page).toHaveURL(new RegExp(`${APPLY_URL}$`));
    const form = applyForm(page);
    await expectSnapshot(form, { name: SOURCE, budget: '1200', rows: 4 });

    return form;
}

test('開いた直後に target を外しても、戻る・進むのために覚えた値(開いた時の props・入力・申請対象の写し)は履歴の項目に残る', async ({
    page,
}) => {
    await openApplyFromProject(page);

    // 鍵の形 = rememberKeyOf(新規の申請・案件なし)
    await expect
        .poll(() =>
            page.evaluate(() =>
                Object.keys(window.history.state?.page?.rememberedState ?? {}),
            ),
        )
        .toEqual(
            expect.arrayContaining([
                'WorkflowApply:new::opened',
                'WorkflowApply:new::data',
                'WorkflowApply:new::target',
            ]),
        );
});

test('開いた時に空にした項目がある画面で欄を変えて離れ、戻ると、変えた値と空にした欄の知らせが残る', async ({
    page,
}) => {
    // 開いた時の写しで空にした項目がある応答(写す時の確かめの結果。サーバーの確かめは Feature テスト)
    await page.route(
        (url) => url.pathname === APPLY_URL && url.searchParams.has('target'),
        async (route) => {
            const response = await route.fetch();
            const body = await response.json();
            body.props.targetValues.values.manager = null;
            body.props.targetValues.clearedFields = [
                {
                    kind: 'field',
                    field: 'manager',
                    detail: null,
                    row: null,
                    name: '責任者',
                },
            ];
            await route.fulfill({ response, json: body });
        },
    );
    const line = page.getByText(
        '今は使えない値を空にした項目が 1 件あります。⚠ の付いた欄を入力してください。',
        { exact: true },
    );
    const reason = page.getByText(
        '申請対象の今の値は、今は使えないため空にしました。',
        { exact: true },
    );
    const form = await openApplyFromProject(page);
    await expect(line).toBeVisible();
    await expect(reason).toBeVisible();

    await budgetField(form).fill('1500');
    await form.getByRole('link', { name: 'キャンセル', exact: true }).click();
    await expect(page).not.toHaveURL(new RegExp(`${APPLY_URL}$`));
    await page.goBack();

    await expect(budgetField(form)).toHaveValue('1500');
    await expect(line).toBeVisible();
});
