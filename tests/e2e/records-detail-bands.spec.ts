import { expect, test } from './support/test';
import type { Page } from '@playwright/test';
import { detailBand } from './support/record-detail-bands';

// 業務データの詳細の明細の帯とタブ「参照制限」(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 3・4・9 節)。
// 初期データ(RecordsSeeder)のプロジェクト「社内ポータル刷新」(明細 4 行・参照制限なし)を読むだけで、ゲストだけで成り立つ。
test.use({ viewport: { width: 1280, height: 720 } });

const PROJECT = '社内ポータル刷新';

/**
 * 一覧から「社内ポータル刷新」の詳細を開き、値の面を返す。
 */
async function openProject(page: Page) {
    await page.goto('/records/data/project');
    await page
        .getByRole('region', { name: 'プロジェクトの一覧' })
        .getByRole('link', { name: PROJECT, exact: true })
        .click();

    return page.getByRole('region', { name: `${PROJECT}の値` });
}

test('明細は左のタブの札ではなく詳細タブの帯で、帯を押すと畳み、再読み込みで開く', async ({
    page,
}) => {
    const values = await openProject(page);

    await expect(
        values.getByRole('tab', { name: /^マイルストーン/ }),
    ).toHaveCount(0);
    await expect(values.getByRole('button', { name: 'その他' })).toHaveCount(0);
    const { band, section } = await detailBand(
        page,
        PROJECT,
        'マイルストーン',
        4,
    );
    await expect(section.getByRole('row')).toHaveCount(5);
    await expect(page).not.toHaveURL(/tab=/);

    // 畳みは保たない(詳細の画面/README.md の 3 節)
    await band.click();
    await expect(band).toHaveAttribute('aria-expanded', 'false');
    await expect(section.getByRole('row')).toHaveCount(0);
    await page.reload();
    await expect(band).toHaveAttribute('aria-expanded', 'true');
    await expect(section.getByRole('row')).toHaveCount(5);
});

test('タブ「参照制限」は URL が tab=access になり、参照制限の値が出て、詳細タブの末尾に区画は無い', async ({
    page,
}) => {
    const values = await openProject(page);
    await expect(values.getByRole('region', { name: '参照制限' })).toHaveCount(
        0,
    );

    await values.getByRole('tab', { name: '参照制限', exact: true }).click();

    await expect(page).toHaveURL(/\?tab=access$/);
    const panel = values.getByRole('tabpanel', { name: '参照制限' });
    await expect(panel.getByText('制限しない', { exact: true })).toBeVisible();
    await expect(values.getByText('開発部', { exact: true })).toBeHidden();
    await page.reload();
    await expect(
        values.getByRole('tab', { name: '参照制限', exact: true }),
    ).toHaveAttribute('aria-selected', 'true');
});
