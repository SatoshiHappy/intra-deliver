import type { Locator, Page } from '@playwright/test';
import {
    AUDIO_SAMPLE,
    createFromAudio,
    createFromPaste,
    expectMinutesAndTranscript,
    FILE_GUIDE,
    fileField,
    meetingIdOf,
    meetingList,
    meetingRow,
    MINUTES_NOTE,
    openCreateDialog,
    openMeeting,
    regenerateAndExpectProcessing,
    submitCreate,
    TEXT_LINES,
} from '../support/meeting';
import {
    completeLastRun,
    refundMinutesBudget,
    spendMinutesBudget,
    transcribeLastRun,
} from '../support/meeting-runs';
import { submitAndExpectUrl } from '../support/submit';
import { expect, test } from '../support/test';

// 会議(docs の 設計/業務ツール/会議/公開デモと画面.md の 9 節): 利用者を選び、会議の一覧の「音声から作成」で貼り付けから作る → 札「処理中」→
// 議事録の実行を AI を呼ばずに終える → 鈴の通知から詳細へ → 議事録の欄・注・書き起こしの節 → 「再作成」で処理中になる。加えて見本の会議の詳細と、
// 音声から作る筋(文字起こしの成功・失敗)と、音声の取り込みの先の止め方。
// 利用者の切替を使うので demo の持ち物。作る会議は件名に時刻を入れて区別し、件数に依る確かめを置かない。画面は PC の幅だけを前提にする。
test.use({ viewport: { width: 1280, height: 800 } });

// 議事録の区分の費用を日の上限まで足す(止め方の筋だけが使う。片付けは時間切れでも走る = 組の DB のほかの筋が AI に依頼できなくならないように)
const budgetSpentTest = test.extend<{ minutesBudgetSpent: void }>({
    minutesBudgetSpent: [
        async ({ artisan }, use) => {
            const runId = spendMinutesBudget(artisan);
            try {
                await use();
            } finally {
                refundMinutesBudget(artisan, runId);
            }
        },
        { auto: true },
    ],
});

const APPLICANT = '佐々木 陽太（東京営業課）';

/**
 * ゲストから申請者(見本の定例の関係者)に切り替える。
 */
async function switchToApplicant(page: Page) {
    await page.goto('/');
    await page
        .getByRole('button', { name: '利用者メニュー：ゲスト', exact: true })
        .click();
    await submitAndExpectUrl(
        page,
        page
            .getByRole('menu')
            .getByRole('menuitem')
            .filter({ hasText: '申請者' }),
    );
    await expect(
        page.getByRole('button', {
            name: `利用者メニュー：${APPLICANT}`,
            exact: true,
        }),
    ).toBeVisible();
}

/** 参照の選択欄を開き、語で絞った候補を選ぶ(pick = [語, 候補の名前]) */
async function pickReference(page: Page, combobox: Locator, pick: string[]) {
    const [query, name] = pick;
    await combobox.click();
    await page.getByPlaceholder('検索する語').fill(query);
    await page.getByRole('option', { name, exact: true }).click();
    await expect(page.getByPlaceholder('検索する語')).toHaveCount(0);
}

/** 鈴を開き、文の通知を押して移る */
async function openNotice(page: Page, sentence: string) {
    await page.getByRole('button', { name: /^通知/ }).click();
    await page
        .locator('[data-slot="popover-content"]')
        .getByRole('button', { name: sentence })
        .click();
    await expect(page).toHaveURL(/\/records\/data\/meeting\/\d+$/);
}

test('貼り付けで作った会議が処理中になり、議事録ができると鈴から詳細へ移れて、再作成で処理中に戻る', async ({
    page,
    artisan,
}) => {
    const title = `E2E の定例 ${Date.now()}`;
    await switchToApplicant(page);
    await page.goto('/records/data/meeting');

    await createFromPaste(page, title);
    await expect(
        page.getByText('会議を作成しました。完了すると通知します。', {
            exact: true,
        }),
    ).toBeVisible();
    await expect(meetingRow(page, title)).toContainText('処理中');

    const meetingId = await meetingIdOf(page, title);
    completeLastRun(artisan, meetingId);
    await page.reload();
    await expect(meetingRow(page, title)).toBeVisible();
    await expect(meetingRow(page, title)).not.toContainText('処理中');

    await openNotice(page, `${title}　議事録を作成しました。`);
    await expectMinutesAndTranscript(page, title, TEXT_LINES);
    await regenerateAndExpectProcessing(page);
    // 再作成のジョブも終え、組の DB に待ちのジョブを残さない
    completeLastRun(artisan, meetingId);
});

test('ファイルの札で関係者(人と組織)とプロジェクトを選んで作ると、参照の値が一覧に出る', async ({
    page,
    artisan,
}) => {
    const title = `E2E のファイル ${Date.now()}`;
    await switchToApplicant(page);
    await page.goto('/records/data/meeting');
    const dialog = await openCreateDialog(page, title);
    const file = fileField(dialog);
    await expect(file).toContainText(FILE_GUIDE);
    await file.locator('input[type="file"]').setInputFiles({
        name: '定例.txt',
        mimeType: 'text/plain',
        buffer: Buffer.from('最初の発言\n次の発言'),
    });
    // 関係者は人と組織を 1 つの候補の一覧(群「ユーザー」「組織」)から選ぶ
    const parties = dialog.getByRole('group', { name: '関係者', exact: true });
    await pickReference(page, parties.getByRole('combobox'), [
        '中村',
        '中村 美咲',
    ]);
    await pickReference(page, parties.getByRole('combobox'), [
        '開発部',
        '開発部',
    ]);
    await pickReference(
        page,
        dialog.getByRole('combobox', { name: 'プロジェクト', exact: true }),
        ['社内ポータル', '社内ポータル刷新'],
    );
    await submitCreate(page, dialog);

    const row = meetingRow(page, title);
    await expect(row).toContainText('中村 美咲');
    await expect(row).toContainText('開発部');
    await expect(
        row.getByRole('img', { name: '組織', exact: true }),
    ).toHaveCount(1);
    await expect(
        row.getByRole('cell', { name: '社内ポータル刷新', exact: true }),
    ).toBeVisible();
    completeLastRun(artisan, await meetingIdOf(page, title));
});

test('見本の会議は関係者の一覧に出て、詳細に議事録と書き起こしの節がある', async ({
    page,
}) => {
    await switchToApplicant(page);
    await page.goto('/records/data/meeting');

    // 申請者は定例の関係者で、キックオフの関係者ではない(行の権限)。プロジェクトの列は初期データのプロジェクト
    await expect(
        meetingRow(page, '社内ポータル刷新 定例').getByRole('cell', {
            name: '社内ポータル刷新',
            exact: true,
        }),
    ).toBeVisible();
    await expect(meetingRow(page, '社内ポータル刷新 キックオフ')).toHaveCount(
        0,
    );
    await meetingList(page)
        .getByRole('link', { name: '社内ポータル刷新 定例', exact: true })
        .click();

    await expect(page).toHaveURL(/\/records\/data\/meeting\/\d+$/);
    await expect(page.getByText(MINUTES_NOTE, { exact: true })).toBeVisible();
    await expect(
        page.getByRole('heading', { name: '検索の応答が遅い件' }),
    ).toBeVisible();
    await page
        .getByRole('navigation', { name: 'サイドパネル', exact: true })
        .getByRole('button', { name: '書き起こし　39 件', exact: true })
        .click();
    const transcript = page.getByRole('region', { name: '書き起こし' });
    await expect(transcript.getByRole('listitem').first()).toHaveText(
        '00:00:04話者 伊藤 涼子定例を始めます。今日は設計の完了の確認と、課題の対応方針を決めます。',
    );
});

test('音声で作った会議が処理中になり、文字起こしと議事録を終えると、詳細に議事録と書き起こしの発言の行が出る', async ({
    page,
    artisan,
}) => {
    const title = `E2E の音声 ${Date.now()}`;
    await switchToApplicant(page);
    await page.goto('/records/data/meeting');
    await createFromAudio(page, title);
    await expect(meetingRow(page, title)).toContainText('処理中');

    const meetingId = await meetingIdOf(page, title);
    transcribeLastRun(artisan, meetingId, [
        ['音声の最初の発言', 'A', 0, 2.5],
        ['音声の次の発言', 'B', 2.5, 4],
    ]);
    completeLastRun(artisan, meetingId);
    await page.reload();
    await expect(meetingRow(page, title)).toBeVisible();
    await expect(meetingRow(page, title)).not.toContainText('処理中');

    await openMeeting(page, title);
    await expectMinutesAndTranscript(page, title, [
        '00:00:00話者 A音声の最初の発言',
        '00:00:02話者 B音声の次の発言',
    ]);
});

test('文字起こしが失敗した書き起こしの無い会議の「再作成」は、追加の指示の欄が無く、音声から作り直す文を出す', async ({
    page,
    artisan,
}) => {
    const title = `E2E の音声の作り直し ${Date.now()}`;
    await switchToApplicant(page);
    await page.goto('/records/data/meeting');
    await createFromAudio(page, title);
    transcribeLastRun(artisan, await meetingIdOf(page, title), []);
    await page.reload();
    await expect(meetingRow(page, title)).toContainText('失敗');

    await openMeeting(page, title);
    await page.getByRole('button', { name: '再作成', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: '再作成' });
    await expect(
        dialog.getByText('書き起こしが無いため、音声から作り直します。', {
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        dialog.getByRole('group', { name: '追加の指示', exact: true }),
    ).toHaveCount(0);
    await expect(
        dialog.getByRole('button', { name: '再作成', exact: true }),
    ).toBeEnabled();
    // 送らずに閉じる(組の DB に待ちのジョブを残さない)
    await dialog
        .getByRole('button', { name: 'キャンセル', exact: true })
        .click();
    await expect(dialog).toHaveCount(0);
});

budgetSpentTest(
    '議事録の区分が受けられないと、作成のダイアログに先の止め方の文が出て、音声を選ぶと誤りになり送れない',
    async ({ page }) => {
        await switchToApplicant(page);
        await page.goto('/records/data/meeting');
        const dialog = await openCreateDialog(
            page,
            `E2E の止め方 ${Date.now()}`,
        );
        // 日の上限と同じ額を足すので、必ず日で超える
        const blocked = '今日の AI の利用の上限に近いため、音声は選べません。';
        const notice = dialog.getByText(blocked, { exact: true });
        await expect(notice).toHaveCount(1);
        const input = fileField(dialog).locator('input[type="file"]');
        await expect(input).toHaveAttribute('accept', '.txt,.vtt,.md');

        await input.setInputFiles(AUDIO_SAMPLE);
        await expect(input).toHaveAttribute('aria-invalid', 'true');
        await expect(
            fileField(dialog).getByText(blocked, { exact: true }),
        ).toBeVisible();
        const sent: string[] = [];
        page.on('request', (request) => {
            if (request.method() !== 'GET') {
                sent.push(request.url());
            }
        });
        await dialog.getByRole('button', { name: '作成', exact: true }).click();
        await expect(input).toBeFocused();
        expect(sent).toEqual([]);
        await expect(dialog).toBeVisible();
    },
);
