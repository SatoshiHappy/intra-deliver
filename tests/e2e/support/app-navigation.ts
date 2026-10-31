import type { Locator, Page } from '@playwright/test';
import { expect } from './test';

// アプリの骨格(docs の 設計/メニュー/表示.md 3.3)の画面を引く部品。サイドバーは「今のアプリ」の見出し(h2)と区画の見出し(h3)で、
// 管理は歯車から、ほかのアプリはランチャーのタイルから引く(サイドバーをグループの見出し(h2)で探す形と、管理をサイドバーで引く形は使わない)。
// 画面は PC の幅(768px 以上)だけを前提にする。

/**
 * サイドバー(グローバルナビ)。
 */
export function sidebar(page: Page): Locator {
    return page.getByRole('navigation', { name: 'グローバルナビ' });
}

/**
 * サイドバーの先頭の見出し(h2)。今のアプリがあればその名前、無ければ「アプリ」(ランチャーのアプリの一覧の見出し)。
 */
export function sidebarHeading(page: Page): Locator {
    return sidebar(page).getByRole('heading', { level: 2 });
}

/**
 * サイドバーの区画のうち、見出し(h3)が heading のもの(今のアプリの木の、直下のフォルダ)。
 */
export function sidebarSection(page: Page, heading: string): Locator {
    return sidebar(page)
        .locator('[data-sidebar="group"]')
        .filter({
            has: page.getByRole('heading', {
                level: 3,
                name: heading,
                exact: true,
            }),
        });
}

/**
 * サイドバーの見出しなしの区画(今のアプリの木の、直下で続いて並ぶアイテム)。今のアプリがあるときに使う
 * (アプリの一覧の区画は見出し h2 を持つ)。区画が複数あっても、中のリンクは画面の順に並ぶ。
 */
export function untitledSections(page: Page): Locator {
    return sidebar(page)
        .locator('[data-sidebar="group"]')
        .filter({ hasNot: page.getByRole('heading') });
}

/**
 * サイドバーのリンク(名前が name のもの。今のアプリの木のアイテム、または今のアプリが無いときのアプリの行)。
 */
export function sidebarLink(page: Page, name: string): Locator {
    return sidebar(page).getByRole('link', { name, exact: true });
}

/**
 * サイドバーの区画の見出し(h3。見出しなしは null)を画面の順に比べる(区画の規則 = docs の 設計/メニュー/表示.md 3.3.3)。
 */
export async function expectSidebarSectionHeadings(
    page: Page,
    expected: Array<string | null>,
): Promise<void> {
    await expect
        .poll(() =>
            sidebar(page)
                .locator('[data-sidebar="group"]')
                .evaluateAll((groups) =>
                    groups.map(
                        (group) =>
                            group.querySelector('h3')?.textContent?.trim() ??
                            null,
                    ),
                ),
        )
        .toEqual(expected);
}

/**
 * サイドバーの見出しなしの区画の中の、名前が name のリンク。
 */
export function untitledLink(page: Page, name: string): Locator {
    return untitledSections(page).getByRole('link', { name, exact: true });
}

/**
 * 業務のアプリの画面(プロジェクトの一覧)を開く(サイドバーの先頭が「業務」になるまで待つ)。管理の画面のサイドバーは管理のアプリなので、
 * 業務のサイドバー(モジュール分類ごとの区画の見出しと、見出しなしの区画)はここで確かめる。
 */
export async function openWorkApp(page: Page): Promise<void> {
    await page.goto('/records/data/project');
    await expect(sidebarHeading(page)).toHaveText('業務');
}

/**
 * サイドバーの開閉ボタン(ヘッダー。広い幅で帯に畳む)。
 */
export function sidebarToggle(page: Page): Locator {
    return page.getByRole('button', { name: 'メニューの開閉', exact: true });
}

/**
 * ヘッダーの共通マスタの検索欄(ユーザー・組織)。
 */
export function headerSearch(page: Page): Locator {
    return page.getByRole('combobox', { name: 'ユーザー・組織を検索' });
}

/**
 * サイトマップの本文の、見出し(h2)が name のアプリの区画。
 */
export function sitemapGroup(page: Page, name: string): Locator {
    return page
        .getByRole('main')
        .locator('section')
        .filter({
            has: page.getByRole('heading', { level: 2, name, exact: true }),
        });
}

/**
 * ヘッダーのランチャーのボタン(名前「アプリ」)。
 */
export function launcherButton(page: Page): Locator {
    return page
        .getByRole('banner')
        .getByRole('button', { name: 'アプリ', exact: true });
}

/**
 * ランチャーのパネル(ボタンの下に開く Popover。名前「アプリ」)。
 */
export function launcherPanel(page: Page): Locator {
    return page.getByRole('dialog', { name: 'アプリ', exact: true });
}

/**
 * ランチャーのパネルの検索欄。
 */
export function launcherSearch(page: Page): Locator {
    return launcherPanel(page).getByPlaceholder('アプリと画面を検索');
}

/**
 * ランチャーを開く(パネルが出るまで待つ)。
 */
export async function openLauncher(page: Page): Promise<Locator> {
    await launcherButton(page).click();
    await expect(launcherPanel(page)).toBeVisible();

    return launcherPanel(page);
}

/**
 * ランチャーのタイルの並び(パネルの ul の中のリンク。下端のサイトマップのリンクは ul の外)。
 */
export function launcherTiles(page: Page): Locator {
    return launcherPanel(page).locator('ul').getByRole('link');
}

/**
 * 名前が name のアプリのタイル(名前は先頭に来て、説明が続く。今のアプリのタイルは名前の後に「(現在のアプリ)」が続く)。
 */
export function launcherTile(page: Page, name: string): Locator {
    return launcherTiles(page).filter({ hasText: startsWith(name) });
}

/**
 * ランチャーのタイルでアプリへ移る(先頭の画面へ移り、パネルが閉じるまで待つ)。
 */
export async function openApp(page: Page, name: string): Promise<void> {
    await openLauncher(page);
    await launcherTile(page, name).click();
    await expect(launcherPanel(page)).toHaveCount(0);
    await expect(sidebarHeading(page)).toHaveText(name);
}

/**
 * ヘッダーの歯車のボタン(名前「管理」。歯車のアプリが表示されるときだけ出る)。
 */
export function adminMenuButton(page: Page): Locator {
    return page
        .getByRole('banner')
        .getByRole('button', { name: '管理', exact: true });
}

/**
 * 歯車のメニュー(歯車のアプリごとの見出しと、その木)。
 */
export function adminMenuDropdown(page: Page): Locator {
    return page.getByRole('menu');
}

/**
 * 歯車を開く(メニューが出るまで待つ)。
 */
export async function openAdminMenu(page: Page): Promise<Locator> {
    await adminMenuButton(page).click();
    await expect(adminMenuDropdown(page)).toBeVisible();

    return adminMenuDropdown(page);
}

/**
 * 歯車から、管理の画面を開く(item は管理のアプリのアイテムの表示名。移り先の URL は呼び手が確かめる)。
 */
export async function openFromAdminMenu(
    page: Page,
    item: string,
): Promise<void> {
    const menu = await openAdminMenu(page);
    await menu.getByRole('menuitem', { name: item, exact: true }).click();
    await expect(adminMenuDropdown(page)).toHaveCount(0);
}

/**
 * パンくず。
 */
export function breadcrumbs(page: Page): Locator {
    return page.getByRole('navigation', { name: 'パンくず' });
}

/**
 * 名前が name で始まる、を表す正規表現(アクセシブルネームやテキストの先頭一致)。
 */
function startsWith(name: string): RegExp {
    return new RegExp(`^${escapeRegExp(name)}`);
}

/**
 * 正規表現の記号を逃がす(画面の名前を正規表現に入れるとき)。
 */
export function escapeRegExp(text: string): string {
    return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
