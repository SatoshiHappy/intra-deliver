import { expect, test } from '../support/test';
import type { Page } from '@playwright/test';
import { submitAndExpectUrl } from '../support/submit';

// 業務データの入れ物のリッチテキストの表のセルの見え方(docs の 設計/業務データの入れ物/画面/README.md 6 節の管理の分の「複数選べる参照」の
// 最後の文、1.4 の表)。システム管理者に切り替えて項目を足すので demo の持ち物。業務モジュール「備品」(コード equipment)は
// records-admin.spec.ts の筋が作る(名前の順で先に走る。名前は後の筋が変えるので、コードと URL で引く)。
// 足した項目とレコードは同じ筋の中で消して戻す(ほかの筋の件数を変えない)。
test.use({ viewport: { width: 1280, height: 720 } });

const FIELD = { code: 'rich_note', name: '備考' };
const ITEM_NAME = 'E2E のリッチテキスト';

/** 記法を入れた値と、一覧の列に出る記法を除いた平文の先頭(リンクは文字だけ・見出しと箇条書きの記号は除く) */
const MARKDOWN =
    '# 見出し\n\n[公式](https://example.com) と **太字**\n\n- 項目';
const EXCERPT = '見出し 公式 と 太字 項目';

/** 項目の既定値(札が 2 行を超える長さ。空白の無い長い URL を含め、折り返しの位置が無くても切れることを見る) */
const DEFAULT_VALUE = `# 既定の見出し\n\nhttps://example.com/${'a'.repeat(300)}`;

/**
 * ゲストからシステム管理者に切り替えて、path を開く。
 */
async function openAsAdministrator(page: Page, path: string) {
    await page.goto('/');
    await page
        .getByRole('button', { name: '利用者メニュー：ゲスト', exact: true })
        .click();
    await submitAndExpectUrl(
        page,
        page
            .getByRole('menu')
            .getByRole('menuitem')
            .filter({ hasText: 'システム管理者' }),
    );
    await expect(
        page.getByRole('button', {
            name: '利用者メニュー：伊藤 涼子（経営企画室）',
            exact: true,
        }),
    ).toBeVisible();
    await page.goto(path);
}

/**
 * 管理の画面で、型「文字列(複数行)」の項目(一覧に表示)を足す。
 */
async function addMultilineField(page: Page) {
    await page.getByRole('link', { name: '項目を追加', exact: true }).click();
    const form = page.getByRole('form', { name: '項目の入力' });
    await form
        .getByRole('textbox', { name: 'コード', exact: true })
        .fill(FIELD.code);
    await form
        .getByRole('textbox', { name: '名前', exact: true })
        .fill(FIELD.name);
    await form.getByRole('combobox', { name: '型', exact: true }).click();
    await page
        .getByRole('option', { name: '文字列(複数行)', exact: true })
        .click();
    await form
        .getByRole('checkbox', { name: '一覧に表示', exact: true })
        .click();
    await form
        .getByRole('group', { name: '既定値', exact: true })
        .getByRole('textbox')
        .fill(DEFAULT_VALUE);
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '追加', exact: true }),
        '/records/admin/modules/equipment',
    );
    await expect(
        page.getByText('項目を追加しました', { exact: true }),
    ).toBeVisible();
}

/**
 * 項目の表の既定値の札は、記法を除いた平文の先頭を札の中で 2 行で切り、横にはみ出さない(1.4)。
 */
async function expectDefaultBadgeClamped(page: Page) {
    const badge = page.getByText(/^既定値: 既定の見出し https:/);
    await expect(badge).toBeVisible();
    const shape = await badge.evaluate((element) => ({
        lines: Math.round(
            element.clientHeight /
                Number.parseFloat(getComputedStyle(element).lineHeight),
        ),
        clipped: element.scrollHeight > element.clientHeight,
        overflowsSideways: element.scrollWidth > element.clientWidth,
    }));
    expect(shape).toEqual({
        lines: 2,
        clipped: true,
        overflowsSideways: false,
    });
}

/**
 * 足したレコード(詳細の URL)と項目を消して戻す。
 */
async function removeRecordAndField(page: Page, detailUrl: string) {
    await page.goto(detailUrl);
    await page.getByRole('button', { name: '削除', exact: true }).click();
    await submitAndExpectUrl(
        page,
        page
            .getByRole('dialog', { name: 'レコードの削除' })
            .getByRole('button', { name: '削除', exact: true }),
    );
    await expect(page.getByText('削除しました', { exact: true })).toBeVisible();
    await page.goto('/records/admin/modules/equipment');
    await page
        .getByRole('button', { name: `${FIELD.name}を削除`, exact: true })
        .click();
    await submitAndExpectUrl(
        page,
        page
            .getByRole('dialog', { name: '項目の削除' })
            .getByRole('button', { name: '削除', exact: true }),
    );
    await expect(
        page.getByText('項目を削除しました', { exact: true }),
    ).toBeVisible();
}

test('「文字列(複数行)」の項目に記法を入れると、一覧の列と項目の表の既定値の札は記法を除いた平文の先頭になる', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/admin/modules/equipment');
    await addMultilineField(page);
    await expectDefaultBadgeClamped(page);

    await page.goto('/records/data/equipment/create');
    const form = page.getByRole('form', { name: /の入力$/ });
    await form
        .getByRole('textbox', { name: '品名', exact: true })
        .fill(ITEM_NAME);
    await form
        .getByRole('textbox', { name: FIELD.name, exact: true })
        .fill(MARKDOWN);
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '登録', exact: true }),
    );
    await expect(page.getByText('登録しました', { exact: true })).toBeVisible();
    const detailUrl = page.url();

    // 一覧の列は記法を除いた平文の先頭(リンクは文字だけで URL を出さない。見出し・強調・箇条書きの記号は出さない)
    await page.goto('/records/data/equipment');
    const row = page
        .getByRole('region', { name: /の一覧$/ })
        .getByRole('row')
        .filter({ has: page.getByRole('link', { name: ITEM_NAME }) });
    await expect(row).toContainText(EXCERPT);
    await expect(row).not.toContainText('https://example.com');
    await expect(row).not.toContainText('**');
    await expect(row.getByRole('link', { name: '公式' })).toHaveCount(0);

    await removeRecordAndField(page, detailUrl);
});
