import { expect, test } from '../support/test';
import type { Page } from '@playwright/test';
import { openAsAdministrator } from '../support/records-headings';
import { submitAndExpectUrl } from '../support/submit';

// 定義の管理の画面(docs の 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md の 10 節)の筋のうち、システム管理者のフロー図での選択と
// ステップの設定。zz-workflow-admin.spec.ts と同じ組で、名前の順にその後に流れる(この筋は版を足さない)。設定を変えた筋は元に戻す。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

const PLAN = '/workflow/admin/flows/project_plan';

/** 版 3 のフロー図(定義の図。ステップの箱は押せる) */
const diagramOf = (page: Page) =>
    page.getByRole('group', { name: '版 3 のフロー図' });

/** 図の下のステップの名前のボタン */
const stepButton = (page: Page, name: string) =>
    page
        .getByRole('group', { name: 'ステップの選択' })
        .getByRole('button', { name, exact: true });

const cardHeading = (page: Page, name: string) =>
    page.getByRole('heading', { level: 3, name, exact: true });

/**
 * 「保存」を押して、トーストが出るのを待つ。
 */
async function save(page: Page, node: string) {
    await submitAndExpectUrl(
        page,
        page.getByRole('button', { name: '保存', exact: true }),
        new RegExp(`node=${node}`),
    );
    // 続けて保存すると前のトーストがまだ残るので、最後に出たものを見る
    await expect(
        page.getByText('ステップの設定を保存しました', { exact: true }).last(),
    ).toBeVisible();
}

test('システム管理者: 図で「部長」を押すと右の欄が「部長 承認」になり、要約とカード 1〜3 が出て、ラベルの横の「?」に説明が出る', async ({
    page,
}) => {
    await openAsAdministrator(page, PLAN);
    const box = diagramOf(page).getByRole('button', { name: /^部長/ });
    await expect(box).toHaveAttribute('aria-pressed', 'false');
    await box.click();

    await expect(page).toHaveURL(/node=manager/);
    await expect(box).toHaveAttribute('aria-pressed', 'true');
    await expect(stepButton(page, '部長')).toHaveAttribute(
        'aria-pressed',
        'true',
    );
    await expect(
        page.getByRole('heading', { level: 2, name: '部長 承認' }),
    ).toBeVisible();
    await expect(
        page.getByText('申請者の上位組織の部長が承認します。', { exact: true }),
    ).toBeVisible();
    await expect(cardHeading(page, '誰が承認するか')).toBeVisible();
    await expect(cardHeading(page, '申請者本人の承認を防ぐ')).toBeVisible();
    await expect(cardHeading(page, '差戻しできる先')).toBeVisible();
    await expect(cardHeading(page, '合議の人数と可決の条件')).toHaveCount(0);
    // カードの見出しの説明は見出しの横の「?」の中(カード 1 は指定の方法で変わる文)
    await page
        .getByRole('button', { name: '誰が承認するかの説明', exact: true })
        .focus();
    await expect(page.getByRole('tooltip')).toContainText(
        '承認者を、基準にする人、組織、役職で指定します。',
    );
    // 承認のステップは指定の方法を選べ、基準にする人は切り替えボタン(申請者を選んでいる)
    await expect(
        page.getByRole('combobox', { name: '指定の方法', exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole('radio', { name: '申請者', exact: true }),
    ).toHaveAttribute('aria-checked', 'true');

    await page
        .getByRole('button', { name: '基準にする人の説明', exact: true })
        .focus();
    // 前の「?」の Tooltip が閉じる途中で残ることがあるので、出す文で選ぶ
    await expect(
        page.getByRole('tooltip').filter({
            hasText:
                '前の承認者は、直前のステップを処理した人です。前処理者とも呼びます。',
        }),
    ).toBeVisible();
});

test('システム管理者: 部長の組織を替えて保存するとトーストが出て要約と図が描き直され、元に戻して保存できる', async ({
    page,
}) => {
    await openAsAdministrator(page, `${PLAN}?node=manager`);
    const scope = page.getByRole('combobox', { name: '組織', exact: true });
    const summary = (text: string) => page.getByText(text, { exact: true });
    const box = diagramOf(page).getByRole('button', { name: /^部長/ });

    await scope.click();
    await page.getByRole('option', { name: '所属組織', exact: true }).click();
    await save(page, 'manager');
    await expect(summary('申請者の所属組織の部長が承認します。')).toBeVisible();
    await expect(box).toContainText('申請者の所属組織 + 部長');

    await scope.click();
    await page.getByRole('option', { name: '上位組織', exact: true }).click();
    await save(page, 'manager');
    await expect(summary('申請者の上位組織の部長が承認します。')).toBeVisible();
    await expect(box).toContainText('申請者の上位組織 + 部長');
});

test('システム管理者: 並列承認「関係部署の合議」を選ぶとカード 4 が出て、指定の方法は出さず、人数の最小・最大はカード 4 にだけ出る', async ({
    page,
}) => {
    await openAsAdministrator(page, PLAN);
    await stepButton(page, '関係部署の合議').click();

    await expect(page).toHaveURL(/node=council/);
    await expect(
        page.getByRole('heading', {
            level: 2,
            name: '関係部署の合議 並列承認',
        }),
    ).toBeVisible();
    await expect(cardHeading(page, '合議の人数と可決の条件')).toBeVisible();
    await page
        .getByRole('button', {
            name: '申請者本人の承認を防ぐの説明',
            exact: true,
        })
        .focus();
    await expect(page.getByRole('tooltip')).toContainText(
        'オンにすると、申請者本人を合議の承認者に選択できません。',
    );
    await expect(
        page.getByRole('checkbox', { name: '申請', exact: true }),
    ).toBeChecked();
    await expect(
        page.getByRole('checkbox', { name: '部長', exact: true }),
    ).not.toBeChecked();

    // 選べるのが 1 つの指定の方法は出さず、人数の最小・最大はカード 4 にだけ出す
    await expect(
        page.getByRole('combobox', { name: '指定の方法', exact: true }),
    ).toHaveCount(0);
    await expect(
        page.getByRole('combobox', { name: '選択する人', exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole('spinbutton', { name: '人数の最小' }),
    ).toHaveCount(1);
});

test('システム管理者: 縦配置の可決の条件は切り替えボタンで、キーボードでも選べ、選んだ条件の欄だけが出る(全員 = 欄なし)', async ({
    page,
}) => {
    await openAsAdministrator(page, `${PLAN}?node=council`);
    const rule = (name: string) =>
        page.getByRole('radio', { name, exact: true });
    const count = page.getByRole('spinbutton', { name: '必要な人数' });
    const ratio = page.getByRole('spinbutton', { name: '必要な割合' });
    await expect(rule('人数')).toHaveAttribute('aria-checked', 'true');
    await expect(count).toHaveValue('2');
    await expect(ratio).toHaveCount(0);
    await rule('人数').focus();
    await page.keyboard.press('ArrowRight');
    await expect(rule('割合')).toBeFocused();
    await page.keyboard.press('Space');
    await expect(rule('割合')).toHaveAttribute('aria-checked', 'true');
    await expect(count).toHaveCount(0);
    await expect(ratio).toBeVisible();
    await rule('全員').click();
    await expect(count).toHaveCount(0);
    await expect(ratio).toHaveCount(0);
    // 選んだボタンをもう一度押しても外れない
    await rule('全員').click();
    await expect(rule('全員')).toHaveAttribute('aria-checked', 'true');
});

test('システム管理者: 規則に合わない入力で保存すると欄の下に誤りが出て、何も保存されない', async ({
    page,
}) => {
    await openAsAdministrator(page, `${PLAN}?node=council`);
    await page.getByRole('spinbutton', { name: '人数の最小' }).fill('0');
    await submitAndExpectUrl(
        page,
        page.getByRole('button', { name: '保存', exact: true }),
    );
    await expect(
        page
            .getByText(
                '可決の条件を人数にする場合は、人数の最小を 1 以上にしてください',
                { exact: true },
            )
            .first(),
    ).toBeVisible();
    await expect(
        page.getByText('ステップの設定を保存しました', { exact: true }),
    ).toHaveCount(0);

    // 開き直すと、保存されていない(最小は 2 のまま)
    await page.goto(`${PLAN}?node=council`);
    await expect(
        page.getByRole('spinbutton', { name: '人数の最小' }),
    ).toHaveValue('2');
});

test('システム管理者: 申請のステップの「対象者で指定」も参照者と同じ欄で、選んだ相手が札になって一覧から消え、× で外せる(保存しない)', async ({
    page,
}) => {
    await openAsAdministrator(page, `${PLAN}?node=apply`);
    const chips = page
        .getByRole('list', { name: '対象者の選択' })
        .getByRole('listitem');
    await expect(chips).toHaveCount(1);
    await expect(chips.first()).toContainText('一般利用者');
    await expect(
        chips.first().getByRole('img', { name: 'ロール', exact: true }),
    ).toBeVisible();

    const search = page.getByRole('combobox', { name: '対象者を追加' });
    await search.click();
    await page.getByPlaceholder('検索する語').fill('経理');
    await page.getByRole('option', { name: /^経理部$/ }).click();
    await expect(chips).toHaveCount(2);
    await expect(
        chips.nth(1).getByRole('img', { name: '組織', exact: true }),
    ).toBeVisible();
    await search.click();
    await page.getByPlaceholder('検索する語').fill('経理');
    await expect(page.getByText('検索中…')).toHaveCount(0);
    await expect(page.getByRole('option', { name: /^経理部$/ })).toHaveCount(0);
    await page.keyboard.press('Escape');

    await page
        .getByRole('button', { name: '経理部を外す', exact: true })
        .click();
    await expect(chips).toHaveCount(1);
});
