import { expect, test } from '../support/test';
import type { Page } from '@playwright/test';
import {
    headingButton,
    HEADING_MODULE,
    openAsAdministrator,
    textField,
} from '../support/records-headings';
import { submitAndExpectUrl } from '../support/submit';

// 見出しのまとまりの畳みと「添付ファイルの追加・削除を許可」(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/見出し.md の 2・3・6 節、
// 業務モジュールの管理.md の 7 節の E2E の行): フォームで畳んだ見出しの中の必須を空にして保存すると、誤りの見出しだけが開いて最初の誤りの欄に
// フォーカスが移る。詳細で見出しを畳み、再読み込みで全部開く。業務モジュール「見出し確認」(見出し「担当情報」「補足事項」)は records-headings-admin が作る。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

const CREATE = '/records/data/heading_check/create';

/**
 * 「見出し確認」の登録のフォーム。
 */
function recordForm(page: Page) {
    return page.getByRole('form', { name: '見出し確認の入力' });
}

/**
 * 一覧から「見出し確認の件」の詳細を開く。
 */
async function openDetail(page: Page) {
    await page.goto('/records/data/heading_check');
    await page.getByRole('link', { name: '見出し確認の件' }).first().click();
    await expect(page).toHaveURL(/\/records\/data\/heading_check\/\d+$/);
}

/**
 * 帯(右端の細い帯)の欄のボタン(読み上げは正式の名前で始まる)。
 */
function railButton(page: Page, name: RegExp) {
    return page
        .getByRole('navigation', { name: 'サイドパネル', exact: true })
        .getByRole('button', { name });
}

test('畳んだ見出しの中の必須を空にして保存すると、誤りの見出しだけが開いて最初の誤りの欄にフォーカスが移る', async ({
    page,
}) => {
    await openAsAdministrator(page, CREATE);
    const form = recordForm(page);
    const owner = headingButton(page, '担当情報');
    const memo = headingButton(page, '補足事項');
    await expect(owner).toHaveAttribute('aria-expanded', 'true');
    await expect(memo).toHaveAttribute('aria-expanded', 'true');

    await textField(form, '件名').fill('見出し確認の件');
    await owner.click();
    await memo.click();
    await expect(textField(form, '担当者')).toBeHidden();
    await expect(textField(form, '備考')).toBeHidden();

    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '登録', exact: true }),
    );
    await expect(owner).toHaveAttribute('aria-expanded', 'true');
    await expect(memo).toHaveAttribute('aria-expanded', 'false');
    await expect(textField(form, '担当者')).toHaveAttribute(
        'aria-invalid',
        'true',
    );
    await expect(textField(form, '担当者')).toBeFocused();
});

test('先頭の見出し「基本情報」(最初の見出しより前の項目)も畳め、その中の必須を空にして保存すると開いて最初の誤りの欄にフォーカスが移る', async ({
    page,
}) => {
    await openAsAdministrator(page, CREATE);
    const form = recordForm(page);
    const basic = headingButton(page, '基本情報');
    await expect(basic).toHaveAttribute('aria-expanded', 'true');

    await basic.click();
    await expect(basic).toHaveAttribute('aria-expanded', 'false');
    await expect(textField(form, '件名')).toBeHidden();

    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '登録', exact: true }),
    );
    await expect(basic).toHaveAttribute('aria-expanded', 'true');
    await expect(textField(form, '件名')).toHaveAttribute(
        'aria-invalid',
        'true',
    );
    await expect(textField(form, '件名')).toBeFocused();
});

test('畳んだ見出しの中の欄の値も送られ、登録した詳細に出る', async ({
    page,
}) => {
    await openAsAdministrator(page, CREATE);
    const form = recordForm(page);
    await textField(form, '件名').fill('見出し確認の件');
    await textField(form, '担当者').fill('担当者の値');
    await textField(form, '備考').fill('畳んだ備考の値');
    await headingButton(page, '補足事項').click();
    await expect(textField(form, '備考')).toBeHidden();

    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '登録', exact: true }),
        /\/records\/data\/heading_check\/\d+$/,
    );
    await expect(
        page.getByText('畳んだ備考の値', { exact: true }),
    ).toBeVisible();
});

test('詳細では見出しごとに畳め、URL に映らず、再読み込みすると全部開く', async ({
    page,
}) => {
    await openAsAdministrator(page, '/');
    await openDetail(page);
    const owner = headingButton(page, '担当情報');
    const value = page.getByText('担当者の値', { exact: true });

    await expect(owner).toHaveAttribute('aria-expanded', 'true');
    await expect(value).toBeVisible();

    await owner.click();
    await expect(owner).toHaveAttribute('aria-expanded', 'false');
    await expect(value).toBeHidden();
    expect(new URL(page.url()).search).toBe('');

    await page.reload();
    await expect(headingButton(page, '担当情報')).toHaveAttribute(
        'aria-expanded',
        'true',
    );
    await expect(value).toBeVisible();
});

test('「添付ファイルの追加・削除を許可」を外すと詳細の添付の欄が出なくなり、戻すと出る', async ({
    page,
}) => {
    await openAsAdministrator(page, `${HEADING_MODULE}/edit`);
    const used = page.getByRole('checkbox', {
        name: '添付ファイルの追加・削除を許可',
        exact: true,
    });
    await expect(used).toBeChecked();
    await expect(
        page.getByText(
            'チェックを解除すると、詳細の画面で添付ファイルを追加・削除できなくなります。すでに付いている添付ファイルは削除されず、表示できます。申請の添付ファイルも、この業務モジュールで許可する場合だけ追加・削除できます。',
            { exact: true },
        ),
    ).toBeVisible();
    await used.click();
    await submitAndExpectUrl(
        page,
        page.getByRole('button', { name: '更新', exact: true }),
        HEADING_MODULE,
    );
    await expect(
        page.getByText('業務モジュールを更新しました', { exact: true }),
    ).toBeVisible();

    await openDetail(page);
    await expect(railButton(page, /^コメント/)).toBeVisible();
    await expect(railButton(page, /^添付ファイル/)).toHaveCount(0);
});

test('「添付ファイルの追加・削除を許可」を戻すと、詳細に添付の欄が出る', async ({
    page,
}) => {
    await openAsAdministrator(page, `${HEADING_MODULE}/edit`);
    const used = page.getByRole('checkbox', {
        name: '添付ファイルの追加・削除を許可',
        exact: true,
    });
    await expect(used).not.toBeChecked();
    await used.click();
    await submitAndExpectUrl(
        page,
        page.getByRole('button', { name: '更新', exact: true }),
        HEADING_MODULE,
    );

    await openDetail(page);
    await expect(railButton(page, /^添付ファイル/)).toBeVisible();
});
