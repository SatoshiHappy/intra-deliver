import { expect } from './test';
import type { Page } from '@playwright/test';
import { escapeRegExp } from './app-navigation';

/**
 * 詳細タブの明細の帯(見出し heading の値の中。帯の文は「<name> <rows> 件」)と、その区画(帯と表)を返す(画面/業務の画面.md 2.3)。
 * 帯は既定で開いている。
 */
export async function detailBand(
    page: Page,
    heading: string,
    name: string,
    rows: number,
) {
    const values = page.getByRole('region', { name: `${heading}の値` });
    const band = values.getByRole('button', {
        name: new RegExp(`^${escapeRegExp(name)}\\s*${rows} 件$`),
    });
    await expect(band).toHaveAttribute('aria-expanded', 'true');

    // 区画の名前は帯の文と同じ「<name> <rows> 件」(見出しのまとまりと同じ名前でも分かれる)
    const section = values.getByRole('region', {
        name: `${name} ${rows} 件`,
        exact: true,
    });

    return { band, section };
}

/**
 * 明細の項目「マイルストーン」の区画(帯「マイルストーン <rows> 件」が開いている)。
 */
export async function milestonesSection(
    page: Page,
    heading: string,
    rows: number,
) {
    return (await detailBand(page, heading, 'マイルストーン', rows)).section;
}
