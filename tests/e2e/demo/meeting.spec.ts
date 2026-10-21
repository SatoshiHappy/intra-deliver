import { expect, test } from '../support/test';
import type { Artisan } from '../support/test';
import type { Locator, Page } from '@playwright/test';
import { submitAndExpectUrl } from '../support/submit';

// 会議(docs の 設計/業務ツール/会議/公開デモと画面.md の 9 節): 利用者を選び、会議の一覧の「音声から作成」で貼り付けから作る → 札「処理中」→
// 議事録の実行を AI を呼ばずに終える → 鈴の通知から詳細へ → 議事録の欄・注・書き起こしの節 → 「再作成」で処理中になる。加えて見本の会議の詳細。
// 利用者の切替を使うので demo の持ち物。作る会議は件名に時刻を入れて区別し、件数に依る確かめを置かない。画面は PC の幅だけを前提にする。
test.use({ viewport: { width: 1280, height: 800 } });

const APPLICANT = '佐々木 陽太（東京営業課）';

const MINUTES_NOTE =
    '議事録の 5 欄は、再作成で上書きされます。前の値は更新履歴で確認できます。';

/**
 * ゲストから申請者(見本の定例の閲覧者)に切り替える。
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

function meetingList(page: Page): Locator {
    return page.getByRole('region', { name: '会議の一覧' });
}

function meetingRow(page: Page, title: string): Locator {
    return meetingList(page)
        .getByRole('row')
        .filter({ has: page.getByRole('link', { name: title, exact: true }) });
}

/** 「音声から作成」を開き、件名(欄の中の薄い字つき)を入れる */
async function openCreateDialog(page: Page, title: string): Promise<Locator> {
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

/** 「作成」を押し、会議の一覧へ戻るのを待つ */
async function submitCreate(page: Page, dialog: Locator) {
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '作成', exact: true }),
        /\/records\/data\/meeting$/,
    );
}

/** 「音声から作成」の貼り付けで、件名を入れて会議を作る */
async function createFromPaste(page: Page, title: string) {
    const dialog = await openCreateDialog(page, title);
    await expect(dialog).toContainText(
        '録音した全員の同意を得た音声だけを取り込んでください。',
    );
    await dialog.getByRole('tab', { name: '貼り付け', exact: true }).click();
    await dialog
        .getByRole('group', { name: 'テキスト', exact: true })
        .locator('textarea')
        .fill('最初の発言\n次の発言\n最後の発言');
    await submitCreate(page, dialog);
}

/** 一覧の行のリンクから会議の id を読む */
async function meetingIdOf(page: Page, title: string): Promise<number> {
    const href = await meetingList(page)
        .getByRole('link', { name: title, exact: true })
        .getAttribute('href');

    return Number(/\/records\/data\/meeting\/(\d+)/.exec(href ?? '')?.[1]);
}

/**
 * 会議の最後の対応の実行のジョブだけを、AI の応答の差し替え(PromptAgent::fake)で動かして消す(組の DB に掛ける。
 * 組の DB を共有するほかの spec の待ちのジョブには触れない)。
 */
function completeLastRun(artisan: Artisan, meetingId: number) {
    const output =
        '{"title": "AI の件名", "summary": "E2E の要約です。", "topics": "## 決めたこと\\n- 要点です",' +
        ' "decisions": "なし", "open_issues": "なし", "next_actions": "なし"}';
    const code = [
        `App\\Actions\\Ai\\PromptAgent::fake([json_decode(${JSON.stringify(output)}, true)]);`,
        `$runId = App\\Models\\Meeting\\MeetingRun::query()->where("record_id", ${meetingId})`,
        '->orderByDesc("id")->value("ai_run_id");',
        '$found = null;',
        'foreach (DB::table("jobs")->orderBy("id")->get() as $row) {',
        '$command = unserialize(json_decode($row->payload, true)["data"]["command"]);',
        'if ($command instanceof App\\Jobs\\Ai\\RunPromptJob && $command->runId === $runId) {',
        '$found = [$row->id, $command]; break; } }',
        'if ($found === null) { throw new RuntimeException("実行 {$runId} の待ちのジョブが無い"); }',
        'app()->call([$found[1], "handle"]);',
        'DB::table("jobs")->where("id", $found[0])->delete();',
    ].join('');
    artisan(['tinker', '--execute', code]);
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

/**
 * 詳細の議事録の欄(リッチテキスト)・要約の上の注と、書き起こしの帯を開いた中身(発言の行)。
 */
async function expectMinutesAndTranscript(page: Page, title: string) {
    await expect(
        page.getByRole('heading', { level: 1, name: title }),
    ).toBeVisible();
    await expect(
        page.getByText('E2E の要約です。', { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole('heading', { name: '決めたこと' }),
    ).toBeVisible();
    await expect(page.getByText(MINUTES_NOTE, { exact: true })).toBeVisible();

    await page
        .getByRole('navigation', { name: 'サイドパネル', exact: true })
        .getByRole('button', { name: '書き起こし　3 件', exact: true })
        .click();
    await expect(page).toHaveURL(/\?panel=meeting-transcript$/);
    await expect(
        page.getByRole('region', { name: '書き起こし' }).getByRole('listitem'),
    ).toHaveText(['最初の発言', '次の発言', '最後の発言']);
}

/**
 * 「再作成」に追加の指示を入れて送り、処理中の札と押せない「再作成」(Tooltip に理由)を確かめる。
 */
async function regenerateAndExpectProcessing(page: Page) {
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
    await expectMinutesAndTranscript(page, title);
    await regenerateAndExpectProcessing(page);
    // 再作成のジョブも終え、組の DB に待ちのジョブを残さない
    completeLastRun(artisan, meetingId);
});

test('ファイルの札で閲覧者とプロジェクトを選んで作ると、参照の値が一覧に出る', async ({
    page,
    artisan,
}) => {
    const title = `E2E のファイル ${Date.now()}`;
    await switchToApplicant(page);
    await page.goto('/records/data/meeting');
    const dialog = await openCreateDialog(page, title);
    const file = dialog.getByRole('group', { name: 'ファイル', exact: true });
    await expect(file).toContainText('1 ファイル 1MB までです。');
    await file.locator('input[type="file"]').setInputFiles({
        name: '定例.txt',
        mimeType: 'text/plain',
        buffer: Buffer.from('最初の発言\n次の発言'),
    });
    const viewers = dialog.getByRole('group', { name: '閲覧者', exact: true });
    await pickReference(page, viewers.getByRole('combobox'), [
        '中村',
        '中村 美咲',
    ]);
    await pickReference(
        page,
        dialog.getByRole('combobox', { name: 'プロジェクト', exact: true }),
        ['社内ポータル', '社内ポータル刷新'],
    );
    await submitCreate(page, dialog);

    const row = meetingRow(page, title);
    await expect(row).toContainText('中村 美咲');
    await expect(
        row.getByRole('cell', { name: '社内ポータル刷新', exact: true }),
    ).toBeVisible();
    completeLastRun(artisan, await meetingIdOf(page, title));
});

test('見本の会議は閲覧者の一覧に出て、詳細に議事録と書き起こしの節がある', async ({
    page,
}) => {
    await switchToApplicant(page);
    await page.goto('/records/data/meeting');

    // 申請者は定例の閲覧者で、キックオフの閲覧者ではない(行の権限)。プロジェクトの列は初期データのプロジェクト
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
