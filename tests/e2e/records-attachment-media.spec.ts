import { execFileSync } from 'node:child_process';
import { expect, test } from './support/test';
import type { Locator, Page } from '@playwright/test';
import { submitAndExpectUrl } from './support/submit';

// 添付の音声の再生と期限で消した実体の行(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の
// 9 節・10 節の E2E)。ゲストだけで成り立つ。筋の中で課題を 1 件登録し、最後に消す(ほかの筋の件数を変えない)。
// 音声の見本はテストの中でバイト列から組む(再生できる音ではなく、形式の判定を通る最小の MP3)。
// 送っている間の進み具合は E2E で大きいファイルを送らず、画面の単体テストと目視で確かめる。
test.use({ viewport: { width: 1280, height: 720 } });

const ISSUE = 'E2E の音声の添付';

/** 中身から audio/mpeg と推される最小のバイト列(ID3 の見出しと MPEG のフレームの頭) */
const MP3 = Buffer.concat([
    Buffer.from([
        0x49, 0x44, 0x33, 0x03, 0, 0, 0, 0, 0, 0, 0xff, 0xfb, 0x90, 0x44,
    ]),
    Buffer.alloc(64),
]);

function panel(page: Page): Locator {
    return page.getByRole('region', { name: '添付ファイル', exact: true });
}

function attachmentRow(page: Page, name: string): Locator {
    return panel(page).getByRole('listitem').filter({ hasText: name });
}

/**
 * 課題を 1 件登録して詳細を開き、添付の欄を開く(プロジェクトは初期データの「社内ポータル刷新」)。
 */
async function createIssueWithPanel(page: Page) {
    await page.goto('/records/data/project_issue/create');
    const form = page.getByRole('form', { name: '課題の入力' });
    await form
        .getByRole('textbox', { name: '課題名', exact: true })
        .fill(ISSUE);
    await form
        .getByRole('combobox', { name: 'プロジェクト', exact: true })
        .click();
    await page.getByPlaceholder('検索する語').fill('社内');
    await page
        .getByRole('option', { name: '社内ポータル刷新', exact: true })
        .click();
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '登録', exact: true }),
        /\/records\/data\/project_issue\/\d+$/,
    );
    await page
        .getByRole('navigation', { name: 'サイドパネル', exact: true })
        .getByRole('button', { name: /^添付ファイル/ })
        .click();
}

/**
 * 音声のファイルを追加して、一覧にその行が出るまで待つ。
 */
async function addAudio(page: Page, name: string) {
    await panel(page)
        .locator('input[type="file"]')
        .setInputFiles({ name, mimeType: 'audio/mpeg', buffer: MP3 });
    await expect(attachmentRow(page, name)).toBeVisible({ timeout: 15_000 });
}

/**
 * 期限の削除の口(PurgeUploads)を、名前で見つけた添付の実体に呼ぶ(期限の削除を動かす定期の処理はまだ無いので、
 * E2E の DB(環境変数で渡される)に artisan の中から口を直接呼ぶ)。
 */
function purgeAttachment(name: string) {
    const code = [
        `$id = App\\Models\\Records\\RecordAttachment::query()->where('name', ${JSON.stringify(name)})`,
        '->orderByDesc("id")->firstOrFail()->upload_id;',
        'app(App\\Actions\\Upload\\Api\\PurgeUploads::class)->execute([$id], new DateTimeImmutable("2026-10-01"));',
    ].join('');
    const output = execFileSync(
        'php',
        ['artisan', 'tinker', '--execute', code],
        {
            encoding: 'utf8',
        },
    );
    // tinker は例外を出力して 0 で終わるので、出力で見分ける(消せていないまま次の確かめに進まない)
    if (output.includes('Exception')) {
        throw new Error(`期限の削除の呼び出しが失敗した: ${output}`);
    }
}

async function deleteIssue(page: Page) {
    await page.getByRole('button', { name: '削除', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'レコードの削除' });
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '削除', exact: true }),
        '/records/data/project_issue',
    );
}

test('音声を添付すると「プレビュー」で Dialog の中に再生の部品が出て(自動再生なし)、範囲の要求に 206 を返し、閉じると消える', async ({
    page,
}) => {
    await createIssueWithPanel(page);
    await addAudio(page, 'memo.mp3');

    await attachmentRow(page, 'memo.mp3')
        .getByRole('button', { name: 'プレビュー', exact: true })
        .click();
    const dialog = page.getByRole('dialog', { name: 'memo.mp3' });
    const audio = dialog.locator('audio');
    await expect(audio).toBeVisible();
    await expect(audio).toHaveAttribute('controls', '');
    await expect(audio).not.toHaveAttribute('autoplay', /.*/);
    expect(
        await audio.evaluate((element: HTMLAudioElement) => element.paused),
    ).toBe(true);

    const src = (await audio.getAttribute('src')) ?? '';
    expect(src).toMatch(/\/attachments\/\d+\/preview$/);
    const partial = await page.request.get(src, {
        headers: { Range: 'bytes=0-9' },
    });
    expect(partial.status()).toBe(206);
    expect(partial.headers()['content-type']).toBe('audio/mpeg');
    expect(partial.headers()['content-disposition']).toBe('inline');

    await dialog.getByRole('button', { name: '閉じる' }).click();
    await expect(dialog).toHaveCount(0);
    await expect(page.locator('audio')).toHaveCount(0);

    await deleteIssue(page);
});

test('期限で実体を消した添付は名前と 1 行の文だけを残し、プレビューとダウンロードのボタンが無く、そのルートは 404', async ({
    page,
}) => {
    await createIssueWithPanel(page);
    await addAudio(page, 'old.mp3');
    const row = attachmentRow(page, 'old.mp3');
    const downloadUrl =
        (await row
            .getByRole('link', { name: 'ダウンロード' })
            .getAttribute('href')) ?? '';
    expect((await page.request.get(downloadUrl)).status()).toBe(200);

    purgeAttachment('old.mp3');
    await page.reload();

    await expect(row).toContainText(
        '削除予定日(2026/10/01)を過ぎたため削除しました。',
    );
    await expect(row.getByRole('button', { name: 'プレビュー' })).toHaveCount(
        0,
    );
    await expect(row.getByRole('link', { name: 'ダウンロード' })).toHaveCount(
        0,
    );
    await expect(
        row.getByRole('button', { name: 'old.mp3を削除' }),
    ).toBeVisible();
    expect((await page.request.get(downloadUrl)).status()).toBe(404);
    expect((await page.request.get(`${downloadUrl}/preview`)).status()).toBe(
        404,
    );

    await deleteIssue(page);
});
