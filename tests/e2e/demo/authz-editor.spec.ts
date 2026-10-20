import { expect, test } from '../support/test';
import type { Locator, Page } from '@playwright/test';
import {
    addColumnButton,
    addUserColumn,
    ADMINISTRATOR,
    BUILTIN_COLUMNS,
    cellButtons,
    chooseType,
    deleteColumn,
    endButton,
    openDeleteDialog,
    openOverviewAs,
    press,
    resourceRow,
    startButton,
    startEditing,
    subjectCell,
    toasts,
} from './authz-editor-support';

// 認可設定の編集(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 8 節、設計/ワークフロー/申請の周辺/画面.md 9 節)のうち、
// セルと列と 422。システム管理者に切り替えて確かめる。セルは押した分を元の設定へ戻し、列は作った列を削除して終える。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

const HINT = 'セルを押すと、その場で保存されます。';

/**
 * 保存の要求を止めた間に、そのセルのボタンが押せないことを確かめてから通す(二重送信を防ぐ)。
 */
async function pressWhileHeld(page: Page, cell: Locator) {
    let release: () => void = () => undefined;
    const gate = new Promise<void>((resolve) => {
        release = resolve;
    });
    await page.route('**/authz/editor/cells', async (route) => {
        await gate;
        await route.continue();
    });

    const button = cell.getByRole('button');
    await button.click();
    await expect(button).toBeDisabled();
    release();
    await expect(button).toBeEnabled();
    await page.unroute('**/authz/editor/cells');
}

/**
 * 読み取りの状態: 開始のボタンだけがあり、編集の操作(案内・列を追加・押せるセル)が出ない。
 */
async function expectReadOnly(page: Page) {
    await expect(startButton(page)).toBeVisible();
    await expect(endButton(page)).toHaveCount(0);
    await expect(page.getByText(HINT)).toHaveCount(0);
    await expect(addColumnButton(page)).toHaveCount(0);
    await expect(cellButtons(page)).toHaveCount(0);
}

/**
 * 編集中の状態: 終了のボタン・案内・列を追加が出て、組み込みの 3 列の見出しには「…」が出ない。
 */
async function expectEditing(page: Page) {
    await expect(endButton(page)).toBeVisible();
    await expect(page.getByText(HINT)).toBeVisible();
    await expect(addColumnButton(page)).toBeVisible();
    await expect(cellButtons(page)).not.toHaveCount(0);
    for (const name of BUILTIN_COLUMNS) {
        await expect(
            page.getByRole('columnheader', { name }).getByRole('button'),
        ).toHaveCount(0);
    }
}

/**
 * システム管理者の列の「組織図」のセルを 許可 → 禁止 → 未設定 と循環させ、元の継承の表示に戻す。
 * 継承のセルは未設定として扱い、押すと直接の許可。成功のトーストは出さない(セルの表示が結果)。
 */
async function cycleAdministratorCell(page: Page) {
    const row = resourceRow(page, '組織図');
    const cell = await subjectCell(page, row, 'システム管理者');
    await expect(cell).toContainText('許可(画面・処理から継承)');
    await pressWhileHeld(page, cell);
    await expect(cell).toHaveText('許可');
    await press(page, cell);
    await expect(cell).toHaveText('禁止');
    await press(page, cell);
    await expect(cell).toContainText('許可(画面・処理から継承)');
    await expect(toasts(page)).toHaveCount(0);
}

test('開始してセルを押すと 許可 → 禁止 → 未設定 と循環し、種類を切り替えても編集中のままで、終了すると操作が出ない', async ({
    page,
}) => {
    await openOverviewAs(page, ADMINISTRATOR);
    await expectReadOnly(page);

    await startEditing(page);
    await expectEditing(page);
    await cycleAdministratorCell(page);

    await chooseType(page, 'メニューグループ');
    await expect(page).toHaveURL(/type=im-menu-group/);
    await expectEditing(page);

    await endButton(page).click();
    await expectReadOnly(page);
});

test('列を追加すると全セルが未設定で、同じ条件の列は追加できず、削除の確かめに件数が出て、削除で列が消える', async ({
    page,
}) => {
    await openOverviewAs(page, ADMINISTRATOR);
    await startEditing(page);

    const added = await addUserColumn(page, '山田', '山田 恵');
    await expect(added).toBeHidden();
    const header = page.getByRole('columnheader', { name: '山田 恵' });
    await expect(header).toBeVisible();
    const cell = await subjectCell(
        page,
        resourceRow(page, '組織図'),
        '山田 恵',
    );
    await expect(cell).toContainText('設定なし');

    // 同じ条件の列(同じユーザー)は追加できない。誤りはダイアログの中に出て、開いたまま
    const duplicate = await addUserColumn(page, '山田', '山田 恵');
    await expect(duplicate).toContainText('同じ条件の列がすでにあります');
    await duplicate
        .getByRole('button', { name: 'キャンセル', exact: true })
        .click();
    await expect(duplicate).toBeHidden();

    await press(page, cell);
    await press(page, cell);
    await expect(cell).toHaveText('禁止');

    const dialog = await openDeleteDialog(page, '山田 恵');
    await expect(dialog).toContainText('列「山田 恵」を削除しますか。');
    await expect(dialog).toContainText(
        'この列に設定された 1 件の許可と禁止が、すべてのリソースの種類から削除されます。この操作は取り消せません。',
    );
    await dialog
        .getByRole('button', { name: 'キャンセル', exact: true })
        .click();
    await expect(header).toBeVisible();

    await deleteColumn(page, '山田 恵');
});
