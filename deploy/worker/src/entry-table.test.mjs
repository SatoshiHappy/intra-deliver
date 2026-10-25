// 入口の Worker の要求の扱いの表(docs の 設計/公開の配備/入口/ログイン.md 5.9.1)のうち、secret の欠けと /_entry/ の道の単体テスト。
// app への中継と入口の cookie が無効・無い要求は entry-table-session.test.mjs、入口の cookie の確かめ(5.9.3)は entry-cookie.test.mjs。
import assert from 'node:assert/strict';
import { test } from 'node:test';
import worker from './index.js';
import {
    assertNoStore,
    cookieStates,
    fakeApp,
    logIn,
    request,
    SECRETS,
} from './test-support.mjs';

const SECRET_NAMES = /** @type {const} */ ([
    'ENTRY_ID',
    'ENTRY_PASSWORD',
    'ENTRY_SESSION_KEY',
]);

/** @param {Response} response */
async function assertUnavailablePage(response) {
    assert.equal(response.status, 503);
    assert.equal(
        response.headers.get('Content-Type'),
        'text/html; charset=utf-8',
    );
    assert.equal(response.headers.get('Cache-Control'), 'no-store');
    assert.match(await response.text(), /ただいま準備中です/);
}

await test('secret のどれかが無いか空なら、どの道・メソッドも 503 の案内ページで、app へ中継せず、欠けた名前だけをログに出す', async (t) => {
    const full = fakeApp(() => new Response('ok'));
    const validCookie = await logIn(full.env);

    for (const name of SECRET_NAMES) {
        for (const value of [undefined, '']) {
            /** @type {Record<string, string | undefined>} */
            const secrets = { ...SECRETS, [name]: value };
            if (value === undefined) {
                delete secrets[name];
            }
            const requests = [
                request('/', { headers: { Cookie: validCookie } }),
                request('/_entry/login'),
                request('/_entry/login', {
                    method: 'POST',
                    body: new URLSearchParams({
                        id: SECRETS.ENTRY_ID,
                        password: SECRETS.ENTRY_PASSWORD,
                    }),
                }),
                request('/records', { method: 'POST' }),
            ];
            for (const incoming of requests) {
                const logged = t.mock.method(console, 'error', () => {});
                const app = fakeApp(() => new Response('ok'), secrets);

                const response = await worker.fetch(incoming, app.env);

                const label = `${name}=${String(value)} ${incoming.method} ${incoming.url}`;
                await assertUnavailablePage(response);
                assert.equal(response.headers.get('Set-Cookie'), null, label);
                assert.equal(app.received.length, 0, label);
                assert.equal(logged.mock.callCount(), 1, label);
                const line = String(logged.mock.calls[0].arguments[0]);
                assert.match(line, new RegExp(name), label);
                for (const secret of Object.values(SECRETS)) {
                    assert.equal(line.includes(secret), false, label);
                }
                logged.mock.restore();
            }
        }
    }
});

await test('/_entry/login の GET・HEAD は、入口の cookie と画面の通信によらずログイン画面(200)', async () => {
    const app = fakeApp(() => new Response('ok'));
    const states = await cookieStates(app.env);

    for (const [state, headers] of Object.entries(states)) {
        for (const method of ['GET', 'HEAD']) {
            const response = await worker.fetch(
                request('/_entry/login', {
                    method,
                    headers: { ...headers, 'X-Inertia': 'true' },
                }),
                app.env,
            );

            assert.equal(response.status, 200, `${state} ${method}`);
            assertNoStore(response);
            const body = await response.text();
            if (method === 'GET') {
                assert.match(body, /<form method="post"/);
            } else {
                assert.equal(body, '');
            }
        }
    }
    assert.equal(app.received.length, 0);
});

await test('/_entry/login の POST は、入口の cookie によらず確かめる(合えば 303、合わなければ 401)', async () => {
    const app = fakeApp(() => new Response('ok'));
    const states = await cookieStates(app.env);

    for (const [state, headers] of Object.entries(states)) {
        for (const [password, status] of /** @type {const} */ ([
            [SECRETS.ENTRY_PASSWORD, 303],
            ['wrong-password', 401],
        ])) {
            const response = await worker.fetch(
                request('/_entry/login', {
                    method: 'POST',
                    headers,
                    body: new URLSearchParams({
                        id: SECRETS.ENTRY_ID,
                        password,
                    }),
                }),
                app.env,
            );

            assert.equal(response.status, status, state);
            assertNoStore(response);
        }
    }
    assert.equal(app.received.length, 0);
});

await test('/_entry/login のほかのメソッドは、入口の cookie によらず 405', async () => {
    const app = fakeApp(() => new Response('ok'));
    const states = await cookieStates(app.env);

    for (const [state, headers] of Object.entries(states)) {
        for (const method of ['PUT', 'DELETE', 'PATCH', 'OPTIONS']) {
            const response = await worker.fetch(
                request('/_entry/login', { method, headers }),
                app.env,
            );

            assert.equal(response.status, 405, `${state} ${method}`);
            assertNoStore(response);
            assert.equal(response.headers.get('Allow'), 'GET, HEAD, POST');
        }
    }
    assert.equal(app.received.length, 0);
});

await test('/_entry/ で始まるほかの道は、入口の cookie が有効でも 404 で、app へ中継しない', async () => {
    const app = fakeApp(() => new Response('ok'));
    const states = await cookieStates(app.env);

    for (const [state, headers] of Object.entries(states)) {
        for (const [path, method] of [
            ['/_entry/other', 'GET'],
            ['/_entry/', 'GET'],
            ['/_entry/login/extra', 'POST'],
        ]) {
            const response = await worker.fetch(
                request(path, { method, headers }),
                app.env,
            );

            assert.equal(response.status, 404, `${state} ${method} ${path}`);
            assertNoStore(response);
            assert.equal(
                response.headers.get('Content-Type'),
                'text/plain; charset=utf-8',
            );
            assert.notEqual(await response.text(), '');
        }
    }
    assert.equal(app.received.length, 0);
});
