/// <reference types="node" />
import { defineConfig, devices } from '@playwright/test';
import { existsSync } from 'node:fs';
import { relative, resolve } from 'node:path';
import { demoGroups } from './tests/e2e/demo/groups';
import type { GroupOptions } from './tests/e2e/support/test';

// E2E は本番ビルド(npm run build)の資産で、テスト用 DB(初期データを入れたもの)のサーバーに掛ける。
// テスト用 DB の名前とポートは scripts/ci/front が DB_DATABASE・E2E_PORT で渡す(docs の 設計/コード構成/検査/一括検査の段.md 6.5)。
// 既定のポートは設定を読むだけの道具(knip など)のため。直接の npm run test:e2e もこのポートを使う(使用中なら Playwright が止まる)。
// 既に動いているサーバーは使い回さない。
const port = Number(process.env.E2E_PORT ?? 8021);
if (!Number.isInteger(port) || port < 1) {
    throw new Error(
        `E2E_PORT が 1 以上の整数でない(値 = ${process.env.E2E_PORT})。E2E は scripts/ci/front から走らせる`,
    );
}
const testDatabase = process.env.DB_DATABASE ?? '';

// ほかの spec が変えた DB を前提にする spec があり、ファイルを自由に配れないので組に分ける(docs の 設計/コード構成/検査/一括検査の段.md 6.5 の並列(E))。
const groups: { name: string; files: string[] }[] = [
    // 組を足すときは、ここに { name, files } で書く
    ...demoGroups,
];
const restGroupName = 'ほか';
const testDir = 'tests/e2e';

// 組の数(ほか を含む)は 9 まで: 作業木のポートは SERVER_PORT + 1〜+9 に収める取り決め(docs の 検査/一括検査の段.md 6.5)
const maxGroups = 9;
if (groups.length + 1 > maxGroups) {
    throw new Error(
        `E2E の組が ${maxGroups} を超える(${groups.length + 1} 組)。作業木のポートは SERVER_PORT + 1〜+9 に収める`,
    );
}
// どのファイルもちょうど 1 つの組で走るよう、組の名前・ファイルの重複を止める。改名・削除で組から黙って外れないよう、
// 書いたファイルが無いか testDir の下の spec でなければ止める
const groupNames = [...groups.map((group) => group.name), restGroupName];
if (new Set(groupNames).size !== groupNames.length) {
    throw new Error(`E2E の組の名前が重なる(${groupNames.join('・')})`);
}
const groupOfFile = new Map<string, string>();
for (const group of groups) {
    for (const file of group.files) {
        const absolute = resolve(import.meta.dirname, file);
        const earlier = groupOfFile.get(absolute);
        if (earlier !== undefined) {
            throw new Error(
                `E2E のファイルが 2 回書かれている: ${file}(組 ${earlier} と組 ${group.name})`,
            );
        }
        groupOfFile.set(absolute, group.name);
        const inTestDir = !relative(
            resolve(import.meta.dirname, testDir),
            absolute,
        ).startsWith('..');
        if (!inTestDir || !file.endsWith('.spec.ts') || !existsSync(absolute)) {
            throw new Error(
                `E2E の組 ${group.name} に書いたファイルが、${testDir} の下の spec でないか無い: ${file}(その組の一覧を直す)`,
            );
        }
    }
}
// 組のファイルは絶対パスと完全に一致する正規表現で渡す(文字列は前に **/ を付けた大文字小文字を区別しない glob で照らされ、
// 書いたファイルと当たるファイルがずれうる)
const escapeRegExp = (text: string) =>
    text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const exactly = (file: string) =>
    new RegExp(`^${escapeRegExp(resolve(import.meta.dirname, file))}$`);
const members = [
    ...groups.map((group) => ({
        name: group.name,
        testMatch: group.files.map(exactly),
    })),
    {
        name: restGroupName,
        testIgnore: groups.flatMap((group) => group.files.map(exactly)),
    },
];
const servers = members.map((member, index) => ({
    name: member.name,
    port: port + index,
    database: `${testDatabase}_e2e_${index + 1}`,
}));
const lastPort = port + members.length - 1;
if (lastPort > 65535) {
    throw new Error(
        `E2E_PORT から組の数だけ続くポートが 65535 を超える(最後 = ${lastPort})。scripts/ci/front からなら .env の SERVER_PORT を小さくする`,
    );
}
const projects = members.map((member, index) => ({
    ...member,
    // 組の中は 1 本ずつ(下の workers の理由)
    workers: 1,
    use: {
        ...devices['Desktop Chrome'],
        baseURL: `http://127.0.0.1:${servers[index].port}`,
        // spec から DB を触る fixture(tests/e2e/support/test.ts の artisan)が、組のサーバーと同じ DB に掛けるため
        groupDatabase: servers[index].database,
    },
}));

export default defineConfig<object, GroupOptions>({
    testDir: `./${testDir}`,
    forbidOnly: true,
    // 送信を 2〜3 回含むテストが、遅い回に tests/e2e/support/submit.ts の失敗の文より先に既定の 30 秒で落ちないようにする
    timeout: 60_000,
    // 全体の workers = 組の数(組ごとに workers: 1 で、組どうしが並列)。組の中を直列にする理由: 組のサーバーの php artisan serve は
    // 単一プロセスで、並列に当てると Inertia の遷移が待たされて toHaveURL などが 5 秒で落ちる(2026-10-11 に 3 回再現。落ちる検査は毎回違った)。
    // 組ごとにサーバーを分けたので、組どうしはこの待たされ方をしない
    workers: projects.length,
    reporter: 'list',
    use: {
        trace: 'retain-on-failure',
        // locale は navigator.language と Accept-Language のため(日本語のブラウザと同じにする)。type="date" などの入力欄の表示の形は
        // ブラウザの OS のロケール(起動の環境変数 LANGUAGE など)で決まり、locale では変わらない。timezoneId は表示のタイムゾーンを利用者と同じにする
        locale: 'ja-JP',
        timezoneId: 'Asia/Tokyo',
    },
    projects,
    webServer: servers.map((server) => ({
        // 組のサーバーの出力に組の名前を付ける(既定はどの組も [WebServer])
        name: `WebServer ${server.name}`,
        // 名前が決まりに合わない・設定キャッシュがある・接続先が保守用の DB にならないときは、e2e-server が起動しない(開発用 DB に掛けないように)
        command: `scripts/ci/e2e-server ${server.port}`,
        env: {
            E2E_TEMPLATE_DATABASE: testDatabase,
            DB_DATABASE: server.database,
        },
        url: `http://127.0.0.1:${server.port}`,
        reuseExistingServer: false,
        // 組の DB の作り直しが混んだ時に 20 秒ほどかかった実測がある
        timeout: 120 * 1000,
    })),
});
