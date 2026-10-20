import { expect, test as base } from '@playwright/test';

// worker のブラウザが最初の画面を描くまでの立ち上げは、ページキャッシュが冷えていると 10〜20 秒かかる。
// それが 1 件目のテストの時間切れに入らないよう、worker の fixture(テストとは別の時間の枠)で最初の画面を 1 回描いておく。
export const test = base.extend<object, { warmBrowser: void }>({
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
