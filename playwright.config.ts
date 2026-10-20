/// <reference types="node" />
import { defineConfig, devices } from '@playwright/test';

// E2E は本番ビルド(npm run build)の資産で、テスト用 DB(初期データを入れたもの)のサーバーに掛ける。
// DB の名前とポートは scripts/ci/front が DB_DATABASE・E2E_PORT で渡す(docs の 設計/コード構成/検査.md 6 節)。
// 既定のポートは設定を読むだけの道具(knip など)のため。直接の npm run test:e2e もこのポートを使う(使用中なら Playwright が止まる)。
// 既に動いているサーバーは使い回さない。
const port = Number(process.env.E2E_PORT ?? 8021);
if (!Number.isInteger(port) || port < 1 || port > 65535) {
    throw new Error(
        `E2E_PORT が 1〜65535 の整数でない(値 = ${process.env.E2E_PORT})。E2E は scripts/ci/front から走らせる`,
    );
}
const baseURL = `http://127.0.0.1:${port}`;

export default defineConfig({
    testDir: './tests/e2e',
    forbidOnly: true,
    // 送信を 2〜3 回含むテストが、遅い回に tests/e2e/support/submit.ts の失敗の文より先に既定の 30 秒で落ちないようにする
    timeout: 60_000,
    // 直列で走らせる: webServer の php artisan serve は単一プロセスで、並列に当てると Inertia の遷移が待たされて
    // toHaveURL などが 5 秒で落ちる(2026-10-11 に 3 回再現。落ちる検査は毎回違った)
    workers: 1,
    reporter: 'list',
    use: {
        baseURL,
        trace: 'retain-on-failure',
        // locale は navigator.language と Accept-Language のため(日本語のブラウザと同じにする)。type="date" などの入力欄の表示の形は
        // ブラウザの OS のロケール(起動の環境変数 LANGUAGE など)で決まり、locale では変わらない。timezoneId は表示のタイムゾーンを利用者と同じにする
        locale: 'ja-JP',
        timezoneId: 'Asia/Tokyo',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    webServer: {
        // DB_DATABASE がテスト用 DB(_test で終わる)でなければ起動しない(npm run test:e2e を直接走らせて開発用 DB に掛けないように)。
        // --no-reload: 環境変数(DB_DATABASE ほか)をそのまま PHP のサーバーに渡す(reload では一部の環境変数だけが渡る)
        command: `case "$DB_DATABASE" in *_test) exec php artisan serve --host=127.0.0.1 --port=${port} --no-reload ;; *) echo "E2E は scripts/ci/front から走らせる(DB_DATABASE がテスト用 DB でない)" >&2; exit 1 ;; esac`,
        url: baseURL,
        reuseExistingServer: false,
        timeout: 60 * 1000,
    },
});
