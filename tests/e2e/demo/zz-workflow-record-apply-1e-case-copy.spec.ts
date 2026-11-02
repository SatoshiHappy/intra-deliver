import { expect, test } from '../support/test';
import type { Artisan } from '../support/test';
import type { Page } from '@playwright/test';
import { fillVersionTwoBaseDate } from '../support/seed-dates';
import {
    caseIdOf,
    caseStateOf,
    rewriteOpened,
} from '../support/workflow-case-copy';
import { pane, railButton } from '../support/workflow-case-show';
import {
    openApplicationTab,
    openAs,
    submitApplication,
} from '../support/workflow-operations';
import {
    APPLY_URL,
    CONFIRM_TITLE,
    applyForm,
    budgetField,
} from '../support/workflow-target-apply';

// 案件からの複製で開いた申請の画面(クエリ copy)。筋の正本 = docs の 設計/ワークフロー/申請の周辺/画面.md の 9 節の「案件からの複製」。
// 申請の詳細の「複製」から開く筋(フローの参照者 = 経理部の利用者が完了した WF-000001 を写す)は最後の 1 本で、ほかは移り先の URL を直に開く。
// 見本の案件 WF-000013(新製品の発売計画。申請者 = u0001)を写す。空にした欄の知らせと申請対象の確かめは、開いた時の応答を書き換えて作る
// (サーバーの確かめは Feature テスト)。プロジェクトを申請対象にするフローが 1 つのうちに流す(tests/e2e/demo/groups.ts の並び)。
test.use({ viewport: { width: 1280, height: 720 } });

const HEADING =
    'プロジェクト計画の承認 新製品の発売計画(申請番号 WF-000013)を複製して申請します。';
const CLEARED_LINE =
    '今は使えない値を空にした項目が 1 件あります。⚠ の付いた欄を入力してください。';
const CLEARED_REASON = '元の申請の値は、今は使えないため空にしました。';

/** 見本の案件 WF-000013 の id */
function sourceIdOf(artisan: Artisan): string {
    return caseIdOf(artisan, 13);
}

/** 申請対象の写しを出さず、元の申請の値を空にした欄(責任者)の知らせを出す形で開く */
async function openCopy(page: Page, artisan: Artisan) {
    const id = sourceIdOf(artisan);
    await rewriteOpened(page, (props) => {
        props.targetValues = null;

        if (props.copy !== null) {
            props.initial.values.manager = null;
            props.copy.clearedFields = [
                {
                    kind: 'field',
                    field: 'manager',
                    detail: null,
                    row: null,
                    name: '責任者',
                },
            ];
        }
    });
    await openAs(page, 'applicant', `${APPLY_URL}?copy=${id}`);
    await expect(page).toHaveURL(new RegExp(`${APPLY_URL}$`));
    await expect(page.getByText(HEADING, { exact: false })).toBeVisible();

    return id;
}

test('開いた直後に URL から copy が外れ、申請名・値と、見出しの下の 1 行と空にした欄の知らせが入る。申請基準日を変えた後も残り、再読み込みで消える', async ({
    page,
    artisan,
}) => {
    const id = await openCopy(page, artisan);
    const form = applyForm(page);
    const line = page.getByText(CLEARED_LINE, { exact: true });
    await expect(line).toBeVisible();
    await expect(
        form.getByRole('textbox', { name: '申請名', exact: true }),
    ).toHaveValue('プロジェクト計画の承認 新製品の発売計画');
    const budget = await budgetField(form).inputValue();
    expect(budget).not.toBe('');

    // 戻る・進むのために覚えた値(開いた時の props の copy・入力)が、copy を外した後の履歴の項目に残る
    await expect
        .poll(() =>
            page.evaluate((sourceId) => {
                const remembered =
                    window.history.state?.page?.rememberedState ?? {};

                return [
                    Object.keys(remembered).sort(),
                    remembered['WorkflowApply:new::opened']?.copy?.caseId ===
                        sourceId,
                ];
            }, Number(id)),
        )
        .toEqual([
            expect.arrayContaining([
                'WorkflowApply:new::data',
                'WorkflowApply:new::opened',
            ]),
            true,
        ]);

    await fillVersionTwoBaseDate(form);
    await expect(page.getByText(HEADING, { exact: false })).toBeVisible();
    await expect(line).toBeVisible();
    await expect(budgetField(form)).toHaveValue(budget);

    await page.reload();

    await expect(
        page.getByText('内容を入力して申請します。', { exact: false }),
    ).toBeVisible();
    await expect(line).toHaveCount(0);
    await expect(budgetField(form)).toHaveValue('');
});

test('入力の誤りで戻った後に別のページへ移り、戻るで来ると、打ち直した値と空にした欄の知らせが残り、申請の POST に暗号化した値が載る', async ({
    page,
    artisan,
}) => {
    const id = await openCopy(page, artisan);
    const form = applyForm(page);
    await budgetField(form).fill('abc');
    await form.getByRole('button', { name: '申請', exact: true }).click();
    await page
        .getByRole('dialog', { name: '申請しますか？' })
        .getByRole('button', { name: '決定', exact: true })
        .click();
    await expect(
        form.getByText('予算(万円)は整数で入力してください', { exact: true }),
    ).toBeVisible();

    // キャンセルは元の申請の詳細へ。戻ると欄・空にした欄の知らせが残り、確かめのダイアログは出ない
    const cancel = form.getByRole('link', { name: 'キャンセル', exact: true });
    await expect(cancel).toHaveAttribute('href', `/workflow/cases/${id}`);
    await budgetField(form).fill('1500');
    await cancel.click();
    await expect(page).toHaveURL(new RegExp(`/workflow/cases/${id}$`));
    await page.goBack();

    await expect(budgetField(form)).toHaveValue('1500');
    await expect(page.getByText(HEADING, { exact: false })).toBeVisible();
    await expect(page.getByText(CLEARED_LINE, { exact: true })).toBeVisible();
    await expect(page.getByText(CLEARED_REASON, { exact: true })).toBeVisible();
    await expect(page.getByRole('dialog', { name: CONFIRM_TITLE })).toHaveCount(
        0,
    );

    const sent = page.waitForRequest(
        (request) =>
            request.method() === 'POST' &&
            new URL(request.url()).pathname === APPLY_URL,
    );
    await submitApplication(page, form);
    expect((await sent).postData() ?? '').toContain('copy_row_access');
    await expect(page.getByText('申請しました', { exact: true })).toBeVisible();
    await expect(page).not.toHaveURL(new RegExp(`/workflow/cases/${id}$`));
});

test('申請対象の今の値が写した値と違うと、開いた時に確かめのダイアログ(「キャンセル」で開いた時の値のまま)', async ({
    page,
    artisan,
}) => {
    const id = sourceIdOf(artisan);
    let copied = '';
    await rewriteOpened(page, (props) => {
        const field = props.targetField ?? 'project';
        const raw = props.initial.values.budget;
        const budget = typeof raw === 'number' ? raw : 0;
        copied = typeof raw === 'number' ? String(raw) : '';
        props.targetValues = {
            values: {
                ...props.targetValues?.values,
                [field]: props.initial.values[field],
                budget: budget + 1,
            },
            details: props.targetValues?.details ?? {},
            clearedFields: [],
        };
    });
    await openAs(page, 'applicant', `${APPLY_URL}?copy=${id}`);
    const dialog = page.getByRole('dialog', { name: CONFIRM_TITLE });
    await expect(dialog).toBeVisible();

    await dialog
        .getByRole('button', { name: 'キャンセル', exact: true })
        .click();

    await expect(dialog).toHaveCount(0);
    await expect(budgetField(applyForm(page))).toHaveValue(copied);
});

test('申請の詳細のサイドパネルを開いた(部分の再読み込みの)後も「複製」が残る', async ({
    page,
    artisan,
}) => {
    await openAs(page, 'applicant', `/workflow/cases/${sourceIdOf(artisan)}`);
    await openApplicationTab(page);
    const button = page
        .getByRole('main')
        .getByRole('link', { name: '複製', exact: true });
    await expect(button).toBeVisible();

    await railButton(page, '更新履歴').click();

    await expect(pane(page, '更新履歴')).toBeVisible();
    await expect(button).toBeVisible();
});

test('フローの参照者が完了した申請の詳細の「複製」から開き、値を直して申請すると新しい申請の詳細が開き、元の申請は変わらない', async ({
    page,
    artisan,
}) => {
    const id = caseIdOf(artisan, 1);
    const before = caseStateOf(artisan, id);
    await openAs(page, 'accounting', `/workflow/cases/${id}`);
    await openApplicationTab(page);
    const button = page
        .getByRole('main')
        .getByRole('link', { name: '複製', exact: true });
    await button.click();
    await expect(page).toHaveURL(new RegExp(`${APPLY_URL}$`));
    await expect(
        page.getByText('(申請番号 WF-000001)を複製して申請します。', {
            exact: false,
        }),
    ).toBeVisible();
    // 申請対象の今の値が写した値と違えば開いた時に確かめが出る(この筋では写した値のまま申請する)
    const confirm = page.getByRole('dialog', { name: CONFIRM_TITLE });
    if ((await confirm.count()) > 0) {
        await confirm
            .getByRole('button', { name: 'キャンセル', exact: true })
            .click();
    }
    const form = applyForm(page);
    await budgetField(form).fill('777');
    await submitApplication(page, form);

    await expect(page).not.toHaveURL(new RegExp(`/workflow/cases/${id}$`));
    expect(caseStateOf(artisan, id)).toBe(before);
});
