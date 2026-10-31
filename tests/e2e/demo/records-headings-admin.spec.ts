import { expect, test } from '../support/test';
import type { Page } from '@playwright/test';
import {
    HEADING_MODULE,
    openAsAdministrator,
} from '../support/records-headings';
import { submitAndExpectUrl } from '../support/submit';

// 見出しの管理(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/見出し.md の 5・6 節、業務モジュールの管理.md の 7 節の E2E の行):
// 管理で見出しを足し・取っ手で並べ替え・名前を変え・消す。システム管理者に切り替えて業務モジュールを作る筋があるので demo の持ち物。
// 業務モジュールは消せないので、自分の業務モジュール「見出し確認」を最初の筋で作り(次の records-headings-show が使う)、ほかの spec の DB に頼らない。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

/**
 * 管理の項目の追加の画面で、コード・名前を入れ(型は文字列 1 行)、必須か・表示の項目か(一覧にも出す)を選んで追加する。
 */
async function addField(
    page: Page,
    code: string,
    name: string,
    isRequired: boolean,
    isTitle = false,
) {
    await page.goto(HEADING_MODULE);
    await page.getByRole('link', { name: '項目を追加', exact: true }).click();
    const form = page.getByRole('form', { name: '項目の入力' });
    await form.getByRole('textbox', { name: 'コード', exact: true }).fill(code);
    await form.getByRole('textbox', { name: '名前', exact: true }).fill(name);
    await form.getByRole('combobox', { name: '型', exact: true }).click();
    await page
        .getByRole('option', { name: '文字列(1 行)', exact: true })
        .click();
    if (isRequired) {
        await form.getByRole('checkbox', { name: '必須', exact: true }).click();
    }
    if (isTitle) {
        // 一覧と詳細の見出しに出す(詳細を件名で開けるように)
        for (const flag of ['一覧に表示', '表示の項目']) {
            await form
                .getByRole('checkbox', { name: flag, exact: true })
                .click();
        }
    }
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '追加', exact: true }),
        HEADING_MODULE,
    );
}

/**
 * 項目の表(管理の定義の画面の面「項目」)の行(先頭 = 表の見出し行)。
 */
function tableRows(page: Page) {
    return page
        .getByRole('region', { name: '項目', exact: true })
        .getByRole('row');
}

/**
 * 「見出しを追加」の Dialog で名前を入れて追加する。
 */
async function addHeading(page: Page, name: string) {
    // 前に足した見出しのトースト(時間で消える)が残っていると、新しいトーストと同じ文で 2 つに当たるので、消えてから足す
    const added = page.getByText('見出しを追加しました', { exact: true });
    await expect(added).toHaveCount(0, { timeout: 10_000 });
    await page
        .getByRole('button', { name: '見出しを追加', exact: true })
        .click();
    const dialog = page.getByRole('dialog', { name: '見出しを追加' });
    await dialog.getByRole('textbox', { name: '名前', exact: true }).fill(name);
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '追加', exact: true }),
    );
    await expect(added).toBeVisible();
    await expect(dialog).toBeHidden();
}

/**
 * 見出しの行の取っ手にフォーカスして Space → 上矢印 → Space で 1 つ上へ動かし、並びの送信の応答まで待つ(取っ手が使えるようになるまでは、次の呼び出しの頭で待つ)。キーを続けて押すと前のキーの
 * 処理より先に届くので、つかんだ(自分の位置の読み上げ)・つかんだ処理のタイマーの後・動いた(読み上げ)を待ってから次を押す。
 */
async function moveHeadingUp(page: Page, name: string, over: string) {
    const saved = page.waitForResponse(
        (response) =>
            response.request().method() === 'PUT' &&
            response.url().endsWith(`${HEADING_MODULE}/fields/reorder`),
    );
    const handle = page.getByRole('button', {
        name: `見出し「${name}」の並び順を変更`,
        exact: true,
    });
    const liveRegion = page.locator('[id^="DndLiveRegion"]');
    // 前の並び替えの送信が終わる(取っ手が aria-disabled="true" の間はつかめない)のを待ってからつかむ
    await expect(handle).toHaveAttribute('aria-disabled', 'false');
    await handle.focus();
    await page.keyboard.press('Space');
    await expect(handle).toHaveAttribute('aria-pressed', 'true');
    await expect(liveRegion).toHaveText(`${name}を${name}の位置へ移動しました`);
    await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 0)));
    await page.keyboard.press('ArrowUp');
    await expect(liveRegion).toHaveText(`${name}を${over}の位置へ移動しました`);
    await page.keyboard.press('Space');
    await saved;
}

test('準備: 業務モジュール「見出し確認」を作り、件名(必須)・担当者(必須)・備考の項目を足す', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/admin/modules/create');
    const form = page.getByRole('form', { name: '業務モジュールの入力' });
    await form
        .getByRole('textbox', { name: 'コード', exact: true })
        .fill('heading_check');
    await form
        .getByRole('textbox', { name: '名前', exact: true })
        .fill('見出し確認');
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '作成', exact: true }),
        HEADING_MODULE,
    );
    // 項目が無くても、固定の行「基本情報」は「0 項目」で出る(見出し.md の 1 節)
    await expect(tableRows(page).nth(1)).toContainText('基本情報');
    await expect(tableRows(page).nth(1)).toContainText('0 項目');

    await addField(page, 'title', '件名', true, true);
    await addField(page, 'owner', '担当者', true);
    await addField(page, 'memo', '備考', false);
});

test('見出しを足して取っ手で並べ替えると、再読み込みしても並びが同じで、「<n> 項目」が数え直される', async ({
    page,
}) => {
    await openAsAdministrator(page, HEADING_MODULE);
    const rows = tableRows(page);

    // 末尾に足した見出しを、担当者の前(1 つ目)と備考の前(2 つ目)へ動かす
    await addHeading(page, '担当情報');
    await expect(rows.nth(5)).toContainText('担当情報');
    await expect(rows.nth(5)).toContainText('0 項目');
    await moveHeadingUp(page, '担当情報', '備考');
    await moveHeadingUp(page, '担当情報', '担当者');
    await addHeading(page, '補足');
    await moveHeadingUp(page, '補足', '備考');

    await page.reload();
    // 先頭に固定の行「基本情報」(最初の見出しより前の項目 = 件名の 1 項目。取っ手も操作も無い)が出る
    await expect(rows.nth(1)).toContainText('基本情報');
    await expect(rows.nth(1)).toContainText('1 項目');
    await expect(rows.nth(1).getByRole('button')).toHaveCount(0);
    await expect(rows.nth(2)).toContainText('件名');
    await expect(rows.nth(3)).toContainText('担当情報');
    await expect(rows.nth(3)).toContainText('1 項目');
    await expect(rows.nth(4)).toContainText('担当者');
    await expect(rows.nth(5)).toContainText('補足');
    await expect(rows.nth(5)).toContainText('1 項目');
    await expect(rows.nth(6)).toContainText('備考');
});

test('見出しの名前を変えられ、消しても見出しの中の項目は消えない', async ({
    page,
}) => {
    await openAsAdministrator(page, HEADING_MODULE);
    await page
        .getByRole('button', { name: '見出し「補足」を編集', exact: true })
        .click();
    const dialog = page.getByRole('dialog', { name: '見出しの編集' });
    await dialog
        .getByRole('textbox', { name: '名前', exact: true })
        .fill('補足事項');
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '更新', exact: true }),
    );
    await expect(
        page.getByText('見出しを更新しました', { exact: true }),
    ).toBeVisible();
    await expect(tableRows(page).nth(5)).toContainText('補足事項');

    await addHeading(page, '不要');
    await page
        .getByRole('button', { name: '見出し「不要」を削除', exact: true })
        .click();
    const confirm = page.getByRole('dialog', { name: '見出しの削除' });
    await expect(confirm).toContainText(
        '見出し「不要」を削除しますか。見出しの中の項目は削除されず、前の見出しのまとまりに入ります。',
    );
    await submitAndExpectUrl(
        page,
        confirm.getByRole('button', { name: '削除', exact: true }),
    );
    await expect(
        page.getByText('見出しを削除しました', { exact: true }),
    ).toBeVisible();
    await expect(tableRows(page)).toHaveCount(7);
});

test('見出しの名前は、ほかの見出しと同じ名前と「基本情報」にできない', async ({
    page,
}) => {
    await openAsAdministrator(page, HEADING_MODULE);
    await page
        .getByRole('button', { name: '見出しを追加', exact: true })
        .click();
    const dialog = page.getByRole('dialog', { name: '見出しを追加' });

    // 見出し.md の 4 節。固定の「基本情報」も、ほかの見出し(前の筋の「担当情報」)と同じく使われている名前に数える
    for (const used of ['基本情報', '担当情報']) {
        await dialog
            .getByRole('textbox', { name: '名前', exact: true })
            .fill(used);
        await dialog.getByRole('button', { name: '追加', exact: true }).click();
        await expect(dialog).toContainText(
            'ほかの見出しと同じ名前と「基本情報」は設定できません',
        );
    }
    await dialog
        .getByRole('button', { name: 'キャンセル', exact: true })
        .click();
    await expect(tableRows(page)).toHaveCount(7);
});
