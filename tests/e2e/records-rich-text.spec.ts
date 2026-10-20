import { expect, test } from './support/test';
import type { Locator, Page } from '@playwright/test';
import { submitAndExpectUrl } from './support/submit';

// 業務データの入れ物のリッチテキスト(docs の 設計/業務データの入れ物/画面/README.md 6 節の main のリッチテキストの行、1.4)。
// 初期データの課題の「解決内容」(文字列(複数行))を使い、ゲストだけで成り立つ。画面は PC の幅だけを前提にする。
// ファイルの名前の順で records.spec.ts より前に走るので、作った課題は筋の最後に削除して戻す(後の spec の件数に依らせない)。
test.use({ viewport: { width: 1280, height: 720 } });

const TITLE = 'E2E のリッチテキスト';

/**
 * 太字・単独の改行・箇条書き・3 から始まる番号付きリスト・右寄せの列の表・自動リンク・危ないリンクの記法・www・タスクリストの記法
 * (ブロックごとに空行で分ける。1 以外から始まる番号付きリストは段落に続けると番号付きリストにならないので、独立の塊にする)
 */
const MARKDOWN = [
    '**太字**',
    '一行目\n二行目',
    '- 項目一\n- 項目二',
    '3. 手順三\n4. 手順四',
    '| 金額 |\n|---:|\n| 1,000 |',
    'https://example.com',
    '[危険](javascript:alert(1))',
    'www.example.com',
    '- [ ] 確認',
].join('\n\n');

/**
 * 登録のフォームの、課題の解決内容の欄(札「編集」「プレビュー」・入力・文字数)。
 */
function resolutionField(page: Page) {
    return page
        .getByRole('form', { name: '課題の入力' })
        .getByRole('group', { name: '解決内容', exact: true });
}

/**
 * 登録後の詳細の、解決内容の値(同じ面のほかの項目の値 = プロジェクトの参照のリンクを含めない)。
 */
function resolutionValue(page: Page) {
    return page
        .getByRole('region', { name: `${TITLE}の値` })
        .locator('dt:text-is("解決内容") + dd');
}

/**
 * 課題の登録の画面で、課題名とプロジェクトを入れ、状態を「完了」にする(解決内容が必須になる)。
 */
async function startIssue(page: Page) {
    await page.goto('/records/data/project_issue/create');
    const form = page.getByRole('form', { name: '課題の入力' });
    await form
        .getByRole('textbox', { name: '課題名', exact: true })
        .fill(TITLE);
    await form
        .getByRole('combobox', { name: 'プロジェクト', exact: true })
        .click();
    await page.getByPlaceholder('検索する語').fill('社内');
    await page
        .getByRole('option', { name: '社内ポータル刷新', exact: true })
        .click();
    await form.getByRole('combobox', { name: '状態', exact: true }).click();
    await page.getByRole('option', { name: '完了', exact: true }).click();
}

/**
 * 記法のうちブロックの描かれ方(太字・改行・リスト・表)。scope = 描いた面。
 */
async function expectBlocks(scope: Locator) {
    await expect(scope.locator('strong')).toHaveText('太字');
    // 段落の中の単独の改行は改行になる(remark-breaks)
    await expect(
        scope.locator('p').filter({ hasText: '一行目' }).locator('br'),
    ).toHaveCount(1);
    // タスクリストは描かず、箇条書きの「[ ] 確認」のまま(チェックボックスにならない)
    await expect(scope.locator('ul').getByRole('listitem')).toHaveText([
        '項目一',
        '項目二',
        '[ ] 確認',
    ]);
    await expect(scope.getByRole('checkbox')).toHaveCount(0);
    // 番号付きリストは書いた番号から数え、表の列の揃えが効く
    await expect(scope.locator('ol')).toHaveAttribute('start', '3');
    await expect(scope.locator('ol').getByRole('listitem')).toHaveText([
        '手順三',
        '手順四',
    ]);
    await expect(
        scope.getByRole('cell', { name: '1,000', exact: true }),
    ).toHaveCSS('text-align', 'right');
}

/**
 * 記法が描かれた結果(プレビューの面と登録後の詳細で同じ = 1.4)。scope = 描いた面。
 */
async function expectRendered(scope: Locator) {
    await expectBlocks(scope);

    // http・https のリンクは別のタブで開く
    const link = scope.getByRole('link', {
        name: 'https://example.com',
        exact: true,
    });
    await expect(link).toHaveAttribute('href', 'https://example.com');
    await expect(link).toHaveAttribute('target', '_blank');
    await expect(link).toHaveAttribute('rel', 'noopener noreferrer');

    // javascript: のリンクと www. は、リンクの要素にならず文字のまま
    await expect(scope.getByRole('link')).toHaveCount(1);
    await expect(scope.locator('a[href^="javascript"]')).toHaveCount(0);
    await expect(
        scope.getByText('[危険](javascript:alert(1))', { exact: true }),
    ).toBeVisible();
    await expect(
        scope.getByText('www.example.com', { exact: true }),
    ).toBeVisible();
}

/**
 * 登録の画面で解決内容が空のまま「完了」を送ると、解決内容のエラーが出る。送った後は札が「編集」に戻り、入力にフォーカスが移る
 * (プレビューの札を開いていても)。
 */
async function expectErrorBringsBackEdit(page: Page) {
    const field = resolutionField(page);
    await field.getByRole('tab', { name: 'プレビュー', exact: true }).click();
    await expect(
        field.getByRole('tabpanel', { name: 'プレビュー', exact: true }),
    ).toHaveText('—');

    await submitAndExpectUrl(
        page,
        page
            .getByRole('form', { name: '課題の入力' })
            .getByRole('button', { name: '登録', exact: true }),
    );

    await expect(
        field.getByText('状態が完了のときは解決内容を入力してください'),
    ).toBeVisible();
    await expect(
        field.getByRole('tab', { name: '編集', exact: true }),
    ).toHaveAttribute('aria-selected', 'true');
    await expect(field.getByRole('textbox')).toBeFocused();
}

/**
 * 誤りが残る間も、札「プレビュー」から Tab で面に入ればプレビューのまま読め(札の外から来たフォーカスだけを送った後の移動とみなす)、
 * 面の説明に誤りの文言が入る。
 */
async function expectPreviewReadableWhileInvalid(page: Page) {
    const field = resolutionField(page);
    const preview = field.getByRole('tab', { name: 'プレビュー', exact: true });
    await preview.click();
    await page.keyboard.press('Tab');

    const panel = field.getByRole('tabpanel', {
        name: 'プレビュー',
        exact: true,
    });
    await expect(panel).toBeFocused();
    await expect(preview).toHaveAttribute('aria-selected', 'true');
    await expect(panel).toHaveAccessibleDescription(
        /状態が完了のときは解決内容を入力してください/,
    );
}

/**
 * 詳細の「削除」で、足した課題を消して一覧へ戻す。
 */
async function deleteIssue(page: Page) {
    await page.getByRole('button', { name: '削除', exact: true }).click();
    await submitAndExpectUrl(
        page,
        page
            .getByRole('dialog', { name: 'レコードの削除' })
            .getByRole('button', { name: '削除', exact: true }),
        '/records/data/project_issue',
    );
    await expect(page.getByText('削除しました', { exact: true })).toBeVisible();
}

test('課題の解決内容は、札「プレビュー」と登録後の詳細で記法が描かれ、危ないリンク・www.・タスクリストは文字のまま', async ({
    page,
}) => {
    await startIssue(page);
    const field = resolutionField(page);

    // 札は「編集」が既定で、文字数はどちらの札でも欄の下に 1 つ出る
    await expect(
        field.getByRole('tab', { name: '編集', exact: true }),
    ).toHaveAttribute('aria-selected', 'true');
    await expect(field.getByText(/^\d+ \/ \d+$/)).toHaveCount(1);

    // プレビューの札を開いたまま空で送る → 札が「編集」に戻り、入力にフォーカス。誤りの間も札から入ればプレビューを読め、
    // 同じ誤りでもう一度送ると同じに戻る
    await expectErrorBringsBackEdit(page);
    await expectPreviewReadableWhileInvalid(page);
    await expectErrorBringsBackEdit(page);

    await field.getByRole('textbox').fill(MARKDOWN);
    await field.getByRole('tab', { name: 'プレビュー', exact: true }).click();
    await expect(field.getByText(/^\d+ \/ \d+$/)).toHaveCount(1);
    await expectRendered(
        field.getByRole('tabpanel', { name: 'プレビュー', exact: true }),
    );

    await submitAndExpectUrl(
        page,
        page
            .getByRole('form', { name: '課題の入力' })
            .getByRole('button', { name: '登録', exact: true }),
        /\/records\/data\/project_issue\/\d+$/,
    );
    await expect(page.getByText('登録しました', { exact: true })).toBeVisible();
    await expectRendered(resolutionValue(page));

    await deleteIssue(page);
});
