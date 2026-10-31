import { resolve } from 'node:path';
import type { Locator, Page } from '@playwright/test';
import { submitAndExpectUrl } from './submit';
import { expect } from './test';

// 会議の E2E(デモユーザー切替の持ち物の会議の spec)の部品: 会議の一覧・「音声から作成」のダイアログ・詳細の議事録と書き起こし・「再作成」の引き方。

/** 音声の見本(リポジトリの ffmpeg の確かめの見本。短い mp3) */
export const AUDIO_SAMPLE = resolve(
    import.meta.dirname,
    '../../../docker/app/ffmpeg-check/samples/mp3float.mp3',
);

/** ファイルの欄の案内(音声 1 本の上限 = 添付の土台の既定値) */
export const FILE_GUIDE =
    '音声(m4a・mp3・wav)・テキスト(.txt・.vtt・.md)を 1 つ選択できます。1 ファイル 95MB までです。';

export const TEXT_LINES = ['最初の発言', '次の発言', '最後の発言'];

const MINUTES_NOTE =
    '議事録の 5 欄は、再作成で上書きされます。前の値は更新履歴で確認できます。';

export function meetingList(page: Page): Locator {
    return page.getByRole('region', { name: '会議の一覧' });
}

export function meetingRow(page: Page, title: string): Locator {
    return meetingList(page)
        .getByRole('row')
        .filter({ has: page.getByRole('link', { name: title, exact: true }) });
}

/** 「音声から作成」を開き、件名(欄の中の薄い字つき)を入れる */
export async function openCreateDialog(
    page: Page,
    title: string,
): Promise<Locator> {
    await page
        .getByRole('button', { name: '音声から作成', exact: true })
        .click();
    const dialog = page.getByRole('dialog', { name: '音声から作成' });
    const titleInput = dialog
        .getByRole('group', { name: '件名', exact: true })
        .getByRole('textbox');
    await expect(titleInput).toHaveAttribute('placeholder', 'AI が設定します');
    await titleInput.fill(title);

    return dialog;
}

/** ダイアログの「ファイル」の欄 */
export function fileField(dialog: Locator): Locator {
    return dialog.getByRole('group', { name: 'ファイル', exact: true });
}

/** 「作成」を押し、会議の一覧へ戻るのを待つ */
export async function submitCreate(page: Page, dialog: Locator) {
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '作成', exact: true }),
        /\/records\/data\/meeting$/,
    );
}

/** 一覧の行のリンクから会議の id を読む */
export async function meetingIdOf(page: Page, title: string): Promise<number> {
    const href = await meetingList(page)
        .getByRole('link', { name: title, exact: true })
        .getAttribute('href');

    return Number(/\/records\/data\/meeting\/(\d+)/.exec(href ?? '')?.[1]);
}

/** 一覧の件名のリンクから会議の詳細へ移る */
export async function openMeeting(page: Page, title: string) {
    await meetingList(page)
        .getByRole('link', { name: title, exact: true })
        .click();
    await expect(page).toHaveURL(/\/records\/data\/meeting\/\d+$/);
}

/** 「音声から作成」の貼り付けで、件名を入れて会議を作る */
export async function createFromPaste(page: Page, title: string) {
    const dialog = await openCreateDialog(page, title);
    await expect(dialog).toContainText(
        '録音した全員の同意を得た音声だけを取り込んでください。',
    );
    await dialog.getByRole('tab', { name: '貼り付け', exact: true }).click();
    await dialog
        .getByRole('group', { name: 'テキスト', exact: true })
        .locator('textarea')
        .fill(TEXT_LINES.join('\n'));
    await submitCreate(page, dialog);
}

/** 「音声から作成」のファイルの札で音声の見本を選び、件名を入れて会議を作る(案内に上限の数が出る) */
export async function createFromAudio(page: Page, title: string) {
    const dialog = await openCreateDialog(page, title);
    const file = fileField(dialog);
    await expect(file).toContainText(FILE_GUIDE);
    await file.locator('input[type="file"]').setInputFiles(AUDIO_SAMPLE);
    await submitCreate(page, dialog);
}

/**
 * 要約の名前の横の注のボタン(i)にマウスを乗せ、Tooltip に注の文が出ることと、読み上げ用の注の文が値の後ろの見えない dd に
 * あること(名前 → 値 → 注の順)を確かめる。
 */
export async function expectMinutesNote(page: Page) {
    await page.getByRole('button', { name: '要約の注', exact: true }).hover();
    await expect(page.getByRole('tooltip')).toHaveText(MINUTES_NOTE);
    // 読み上げ用の注の文は値の後ろの dd(名前 → 値 → 注の順)
    const values = page.locator(
        'div:has(> dt button[aria-label="要約の注"]) > dd',
    );
    await expect(values).toHaveCount(2);
    await expect(values.last()).toHaveText(MINUTES_NOTE);
}

/**
 * 詳細の議事録の欄(リッチテキスト)・要約の名前の横の注と、書き起こしの帯を開いた中身(発言の行 = lines)。
 */
export async function expectMinutesAndTranscript(
    page: Page,
    title: string,
    lines: string[],
) {
    await expect(
        page.getByRole('heading', { level: 1, name: title }),
    ).toBeVisible();
    await expect(
        page.getByText('E2E の要約です。', { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole('heading', { name: '決めたこと' }),
    ).toBeVisible();
    await expectMinutesNote(page);

    await page
        .getByRole('navigation', { name: 'サイドパネル', exact: true })
        .getByRole('button', {
            name: `書き起こし　${lines.length} 件`,
            exact: true,
        })
        .click();
    await expect(page).toHaveURL(/\?panel=meeting-transcript$/);
    await expect(
        page.getByRole('region', { name: '書き起こし' }).getByRole('listitem'),
    ).toHaveText(lines);
}

/**
 * 「再作成」に追加の指示を入れて送り、処理中の札と押せない「再作成」(Tooltip に理由)を確かめる。
 */
export async function regenerateAndExpectProcessing(page: Page) {
    await page.getByRole('button', { name: '再作成', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: '再作成' });
    await dialog
        .getByRole('group', { name: '追加の指示', exact: true })
        .locator('textarea')
        .fill('決定事項を先に書いてください');
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '再作成', exact: true }),
        /\/records\/data\/meeting\/\d+$/,
    );
    await expect(
        page.getByText('議事録の再作成を依頼しました。完了すると通知します。', {
            exact: true,
        }),
    ).toBeVisible();
    const regenerate = page.getByRole('button', {
        name: '再作成',
        exact: true,
    });
    await expect(regenerate).toBeDisabled();
    await expect(page.getByRole('main')).toContainText('処理中');
    await regenerate.locator('xpath=..').focus();
    await expect(page.getByRole('tooltip')).toHaveText('処理中です');
}
