import { execFileSync } from 'node:child_process';
import { expect, test as base } from '@playwright/test';

/** 組の DB の名前。playwright.config.ts が組ごとに use で渡す */
export type GroupOptions = { groupDatabase: string };

/** php artisan を組の DB に掛け、標準出力を返す。終了コードが 0 でないか時間を超えたら、出力を添えて投げる */
export type Artisan = (args: string[]) => string;

// 同期で呼ぶので、止まったままだとテストの時間切れ(60 秒)も効かない。それより短く切る
const artisanTimeoutMs = 30_000;

function runArtisan(database: string, args: string[]): string {
    try {
        return execFileSync('php', ['artisan', ...args], {
            encoding: 'utf8',
            env: { ...process.env, DB_DATABASE: database },
            stdio: ['ignore', 'pipe', 'pipe'],
            timeout: artisanTimeoutMs,
        });
    } catch (error) {
        // tinker の例外は標準出力に出る(終了コード 1)ので、標準出力と標準エラーを添える
        // php が起動しなかったとき(ENOENT など)は stdout・stderr が null
        const { stdout, stderr } = error as {
            stdout?: string | null;
            stderr?: string | null;
        };
        throw new Error(
            `php artisan ${args[0]} が失敗した(DB = ${database}): ${String(error)}\n${stdout ?? ''}${stderr ?? ''}`,
            { cause: error },
        );
    }
}

// DB_DATABASE の差し替えが効かないと(設定キャッシュ・DB_URL など)別の DB に書くので、接続先を結果で確かめる
// (scripts/ci/e2e-server と同じ形)
const connectedDatabaseCheck = [
    '$connected = DB::selectOne("select current_database() as name")->name;',
    'if ($connected !== getenv("DB_DATABASE")) {',
    'throw new RuntimeException("組の DB でなく {$connected} に接続した(DB_DATABASE の差し替えが効かない。設定キャッシュか DB_URL を外す)");',
    '}',
].join('');

export const test = base.extend<
    object,
    GroupOptions & { artisan: Artisan; warmBrowser: void }
>({
    groupDatabase: ['', { scope: 'worker', option: true }],
    // spec から DB を触るときは、この fixture で組の DB に掛ける。worker の環境の DB_DATABASE は組の写し元のテスト用 DB で、
    // 組のサーバーが書いた行が無い(docs の 設計/コード構成/検査.md 6 節の並列(E))
    artisan: [
        async ({ groupDatabase }, use) => {
            if (groupDatabase === '') {
                throw new Error(
                    '組の DB が渡されていない(playwright.config.ts の projects の use の groupDatabase)',
                );
            }
            runArtisan(groupDatabase, [
                'tinker',
                '--execute',
                connectedDatabaseCheck,
            ]);
            await use((args) => runArtisan(groupDatabase, args));
        },
        { scope: 'worker' },
    ],
    // worker のブラウザが最初の画面を描くまでの立ち上げは、ページキャッシュが冷えていると 10〜20 秒かかる。
    // それが 1 件目のテストの時間切れに入らないよう、worker の fixture(テストとは別の時間の枠)で最初の画面を 1 回描いておく。
    warmBrowser: [
        async ({ browser }, use, workerInfo) => {
            const page = await browser.newPage({
                baseURL: workerInfo.project.use.baseURL,
            });
            const response = await page.goto('/');
            const status = response?.status();
            // エラーの画面には main が無いので、待つ前に落とす(main を枠の終わりまで待たず、応答の状態を出す)
            expect(response?.ok(), `GET / の応答 = ${status}`).toBe(true);
            // 最初の描画を 1 回通す: goto は load までしか待たないので、描いた画面(main)とフォントの読み込みを待ち、
            // 操作の前の「安定」の判定と同じく 2 枚のフレームを描かせる
            await page.getByRole('main').waitFor();
            await page.evaluate(async () => {
                await document.fonts.ready;
                await new Promise((resolve) =>
                    requestAnimationFrame(() => requestAnimationFrame(resolve)),
                );
            });
            await page.close();
            await use();
        },
        { scope: 'worker', auto: true },
    ],
});

export { expect };
