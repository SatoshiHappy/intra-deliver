import { expect } from './test';
import type { Locator, Page } from '@playwright/test';

// 承認のダイアログの指定するステップの欄の部品(workflow-route.spec.ts が使う。docs の 設計/ワークフロー/ルートの深さ/画面.md の 6 節)。

/**
 * 指定するステップの欄で、検索する語を打って候補を選ぶ(候補の名前は「名前 主所属」なので先頭で引く)。
 */
export async function addAssignee(
    page: Page,
    section: Locator,
    query: string,
    name: string,
) {
    await section
        .getByRole('button', { name: 'ユーザーを検索して追加', exact: true })
        .click();
    await page.getByPlaceholder('名前・コードで検索').fill(query);
    await page.getByRole('option', { name: new RegExp(`^${name}`) }).click();
    await expect(page.getByPlaceholder('名前・コードで検索')).toHaveCount(0);
}

/** 指定の欄の検索を開き、候補の一覧の読み上げ名が「{指定されるステップの名前}の処理担当者の候補」であることを確かめて閉じる */
export async function expectCandidateLabel(
    page: Page,
    section: Locator,
    nodeName: string,
) {
    await section
        .getByRole('button', { name: 'ユーザーを検索して追加', exact: true })
        .click();
    await expect(
        page.getByRole('combobox', {
            name: `${nodeName}の処理担当者の候補`,
            exact: true,
        }),
    ).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByPlaceholder('名前・コードで検索')).toHaveCount(0);
}
