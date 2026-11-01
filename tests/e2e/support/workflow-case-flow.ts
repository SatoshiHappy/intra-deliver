import { expect } from './test';
import type { Page } from '@playwright/test';
import { openApplicationTab } from './workflow-operations';

// 申請の詳細の左のタブ「申請」の面のうち、フロー情報の図・合議の進み具合・現在のステップを引く手助け
// (workflow-route.spec.ts。docs の 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md の 10 節)。

/**
 * 申請情報の「現在のステップ」の値(左のタブ「申請」を開いてから引く)。
 */
export async function currentSteps(page: Page) {
    const basic = await openApplicationTab(page);

    return basic
        .locator('div')
        .filter({ has: page.getByText('現在のステップ', { exact: true }) })
        .locator('dd');
}

/**
 * 詳細のフロー図(既定の表示)。
 */
export function diagram(page: Page) {
    return page.getByRole('group', { name: 'フロー図', exact: true });
}

/**
 * フロー図の中の、名前が name のステップの箱(箱の名前の行は名前だけの文字)。
 */
export function diagramStep(page: Page, name: string) {
    return diagram(page)
        .locator('.react-flow__node')
        .filter({ has: page.getByText(name, { exact: true }) });
}

/**
 * フロー図の点線の辺(通らなかった枝とその続き = 画面/図と意匠とE2E.md 8 節。辺の線に破線の書式が付く)。
 */
export function dashedEdges(page: Page) {
    return diagram(page).locator(
        'path.react-flow__edge-path[style*="stroke-dasharray"]',
    );
}

/**
 * ステップの箱の境界の箱が、図の面の境界の箱の内にある(はみ出して切れていない)。
 */
export async function expectInsideDiagram(page: Page, name: string) {
    await expect
        .poll(async () => {
            const outer = await diagram(page).boundingBox();
            const inner = await diagramStep(page, name).boundingBox();

            return (
                outer !== null &&
                inner !== null &&
                inner.x >= outer.x &&
                inner.y >= outer.y &&
                inner.x + inner.width <= outer.x + outer.width &&
                inner.y + inner.height <= outer.y + outer.height
            );
        })
        .toBe(true);
}

/**
 * 合議の進み具合の面(見出し = 縦配置の名前)。
 */
export function councilProgress(page: Page) {
    return page.getByRole('region', { name: '関係部署の合議', exact: true });
}
