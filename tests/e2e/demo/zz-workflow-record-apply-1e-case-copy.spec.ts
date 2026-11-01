import { expect, test } from '../support/test';
import type { Artisan } from '../support/test';
import type { Page, Route } from '@playwright/test';
import { fillVersionTwoBaseDate } from '../support/seed-dates';
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
// 申請の詳細の「複製して申請」から開く筋は最後の 1 本で、ほかは移り先の URL を直に開く。
// 見本の案件 WF-000013(新製品の発売計画。申請者 = u0001)を写す。使えない値の 1 行と申請対象の確かめは、開いた時の応答を書き換えて作る
// (サーバーの確かめは Feature テスト)。プロジェクトを申請対象にするフローが 1 つのうちに流す(tests/e2e/demo/groups.ts の並び)。
test.use({ viewport: { width: 1280, height: 720 } });

const HEADING =
    'プロジェクト計画の承認 新製品の発売計画(申請番号 WF-000013)を複製して申請します。';
const CLEARED_LINE =
    '元の申請の値のうち、責任者は、現在使用できない値を外しました。';

/** 見本の案件 WF-000013 の id */
function sourceIdOf(artisan: Artisan): string {
    const output = artisan([
        'tinker',
        '--execute',
        'echo App\\Models\\Workflow\\WorkflowCase::query()->where("case_number", 13)->value("id");',
    ]);
    const id = /(\d+)\s*$/.exec(output.trim())?.[1];

    if (id === undefined) {
        throw new Error(`WF-000013 の id を読めなかった: ${output}`);
    }

    return id;
}

type OpenedProps = {
    initial: { values: Record<string, unknown> };
    targetField: string | null;
    targetValues: {
        values: Record<string, unknown>;
        details: Record<string, unknown>;
        clearedFields: string[];
    } | null;
    copy: { clearedFields: string[] } | null;
};

/** 最初の読み込みの HTML がページの props を持つ要素 */
const PAGE_DATA_OPEN = '<script data-page="app" type="application/json">';

/** サーバーと同じく、HTML に埋める JSON の < と > を逃がす(JSON_HEX_TAG) */
function hexTagged(json: string): string {
    return json.replaceAll('<', '\\u003C').replaceAll('>', '\\u003E');
}

/** 最初の読み込みの HTML の、ページの props を書き換える */
function rewrittenHtml(html: string, change: (props: OpenedProps) => void) {
    const start = html.indexOf(PAGE_DATA_OPEN) + PAGE_DATA_OPEN.length;
    const end = html.indexOf('</script>', start);

    if (start < PAGE_DATA_OPEN.length || end < 0) {
        throw new Error('ページの props を HTML に見つけられなかった');
    }

    const page = JSON.parse(html.slice(start, end));
    change(page.props as OpenedProps);

    return (
        html.slice(0, start) + hexTagged(JSON.stringify(page)) + html.slice(end)
    );
}

/**
 * クエリ copy の付いた申請の画面の GET の応答を書き換える(開いた後の GET には copy が無いので当たらない)。
 * 直に開く最初の読み込みは HTML、Inertia の移動は JSON で返る。
 */
async function rewriteOpened(page: Page, change: (props: OpenedProps) => void) {
    await page.route(
        (url) => url.pathname === APPLY_URL && url.searchParams.has('copy'),
        async (route: Route) => {
            const response = await route.fetch();

            if (route.request().headers()['x-inertia'] !== undefined) {
                const body = await response.json();
                change(body.props as OpenedProps);
                await route.fulfill({ response, json: body });

                return;
            }

            const html = rewrittenHtml(await response.text(), change);
            await route.fulfill({ response, body: html });
        },
    );
}

/** 申請対象の写しを出さず、元の申請の値を空にした 1 行を出す形で開く */
async function openCopy(page: Page, artisan: Artisan) {
    const id = sourceIdOf(artisan);
    await rewriteOpened(page, (props) => {
        props.targetValues = null;

        if (props.copy !== null) {
            props.copy.clearedFields = ['責任者'];
        }
    });
    await openAs(page, 'applicant', `${APPLY_URL}?copy=${id}`);
    await expect(page).toHaveURL(new RegExp(`${APPLY_URL}$`));
    await expect(page.getByText(HEADING, { exact: false })).toBeVisible();

    return id;
}

test('開いた直後に URL から copy が外れ、申請名・値と 2 つの 1 行が入る。申請基準日を変えた後も残り、再読み込みで消える', async ({
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

test('入力の誤りで戻った後に別のページへ移り、戻るで来ると、打ち直した値と 1 行が残り、申請の POST に暗号化した値が載る', async ({
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

    // キャンセルは元の申請の詳細へ。戻ると欄・1 行が残り、確かめのダイアログは出ない
    const cancel = form.getByRole('link', { name: 'キャンセル', exact: true });
    await expect(cancel).toHaveAttribute('href', `/workflow/cases/${id}`);
    await budgetField(form).fill('1500');
    await cancel.click();
    await expect(page).toHaveURL(new RegExp(`/workflow/cases/${id}$`));
    await page.goBack();

    await expect(budgetField(form)).toHaveValue('1500');
    await expect(page.getByText(HEADING, { exact: false })).toBeVisible();
    await expect(page.getByText(CLEARED_LINE, { exact: true })).toBeVisible();
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

test('申請の詳細の「複製して申請」から開く。サイドパネルを開いた後もボタンが残る', async ({
    page,
    artisan,
}) => {
    const id = sourceIdOf(artisan);
    await rewriteOpened(page, (props) => {
        props.targetValues = null;
    });
    await openAs(page, 'applicant', `/workflow/cases/${id}`);
    await openApplicationTab(page);
    const button = page.getByRole('link', {
        name: '複製して申請',
        exact: true,
    });
    await expect(button).toBeVisible();

    await railButton(page, '更新履歴').click();
    await expect(pane(page, '更新履歴')).toBeVisible();
    await expect(button).toBeVisible();

    await button.click();

    await expect(page).toHaveURL(new RegExp(`${APPLY_URL}$`));
    await expect(page.getByText(HEADING, { exact: false })).toBeVisible();
    await expect(
        applyForm(page).getByRole('textbox', { name: '申請名', exact: true }),
    ).toHaveValue('プロジェクト計画の承認 新製品の発売計画');
});
