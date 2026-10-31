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
    await expect(
        page.getByText('承認者を、基準にする人、組織、役職で指定します。', {
            exact: true,
        }),
    ).toBeVisible();

    await page
        .getByRole('button', { name: '基準にする人の説明', exact: true })
        .focus();
    await expect(page.getByRole('tooltip')).toContainText(
        '前の承認者は、直前のステップを処理した人です。前処理者とも呼びます。',
    );
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

test('システム管理者: 縦配置「関係部署の合議」を選ぶとカード 4 が出て、最小・最大は共有され、可決の条件で入力できる欄が切り替わる', async ({
    page,
}) => {
    await openAsAdministrator(page, PLAN);
    await stepButton(page, '関係部署の合議').click();

    await expect(page).toHaveURL(/node=council/);
    await expect(
        page.getByRole('heading', { level: 2, name: '関係部署の合議 縦配置' }),
    ).toBeVisible();
    await expect(cardHeading(page, '合議の人数と可決の条件')).toBeVisible();
    await expect(
        page.getByText(
            'オンにすると、申請者本人を合議の処理対象者に指定できません。',
            { exact: true },
        ),
    ).toBeVisible();
    await expect(
        page.getByRole('checkbox', { name: '申請', exact: true }),
    ).toBeChecked();
    await expect(
        page.getByRole('checkbox', { name: '部長', exact: true }),
    ).not.toBeChecked();

    // 人数の最小・最大は、カード 1 とカード 4 で同じ値を共有する
    const min = page.getByRole('spinbutton', { name: '人数の最小' });
    await expect(min).toHaveCount(2);
    await min.first().fill('1');
    await expect(min.last()).toHaveValue('1');

    // 可決の条件で選んだ欄だけを入力でき、必要な人数の下に状態の文が出る
    const count = page.getByRole('spinbutton', { name: '必要な人数' });
    const ratio = page.getByRole('spinbutton', { name: '必要な割合' });
    await expect(count).toBeEnabled();
    await expect(ratio).toBeDisabled();
    await count.fill('3');
    await expect(
        page.getByText('3 人が承認したら次へ進みます。', { exact: true }),
    ).toBeVisible();
    await page.getByRole('combobox', { name: '可決の条件' }).click();
    await page.getByRole('option', { name: '割合', exact: true }).click();
    await expect(count).toBeDisabled();
    await expect(ratio).toBeEnabled();
    await expect(page.getByText(/人が承認したら次へ進みます。/)).toHaveCount(0);
});

test('システム管理者: 規則に合わない入力で保存すると欄の下に誤りが出て、何も保存されない', async ({
    page,
}) => {
    await openAsAdministrator(page, `${PLAN}?node=council`);
    await page
        .getByRole('spinbutton', { name: '人数の最小' })
        .first()
        .fill('0');
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
        page.getByRole('spinbutton', { name: '人数の最小' }).first(),
    ).toHaveValue('2');
});
