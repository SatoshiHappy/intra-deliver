// 入口の Worker(index.js)の中継の単体テスト(node --test 'deploy/worker/src/*.test.mjs')。VPC サービスの binding は偽物に置き換える。
// 要求の扱いの表は entry-table.test.mjs、ログインは login.test.mjs、訪問者の ID は visitor-id.test.mjs。
import assert from 'node:assert/strict';
import { test } from 'node:test';
import worker from './index.js';
import { ENTRY_COOKIE, fakeApp, logIn, ORIGIN } from './test-support.mjs';

/**
 * 入口の cookie が有効な要求。Cookie はほかの cookie の前に入口の cookie を置く。
 * @param {object} env
 * @param {string} path
 * @param {{ headers?: Record<string, string>, cookies?: string }} [init]
 */
async function loggedInRequest(env, path, init = {}) {
    const entry = await logIn(env);
    const cookie =
        init.cookies === undefined ? entry : `${entry}; ${init.cookies}`;
    return new Request(`${ORIGIN}${path}`, {
        headers: { ...init.headers, Cookie: cookie },
    });
}

/** @param {Response} response */
async function assertUnavailablePage(response) {
    assert.equal(response.status, 503);
    assert.equal(
        response.headers.get('Content-Type'),
        'text/html; charset=utf-8',
    );
    assert.equal(response.headers.get('Cache-Control'), 'no-store');
    assert.match(await response.text(), /<html/i);
}

await test('app へ中継し、宛先・転送ヘッダーを置き換え、app の応答をそのまま返す', async () => {
    const app = fakeApp(
        () =>
            new Response('ok', { status: 200, headers: { 'X-From-App': '1' } }),
    );
    const request = await loggedInRequest(app.env, '/records?page=2', {
        headers: {
            'CF-Connecting-IP': '203.0.113.7',
            'X-Forwarded-For': '198.51.100.1',
            'X-Forwarded-Host': 'evil.example',
            'X-Forwarded-Proto': 'http',
        },
    });

    const response = await worker.fetch(request, app.env);

    assert.equal(response.status, 200);
    assert.equal(response.headers.get('X-From-App'), '1');
    assert.equal(await response.text(), 'ok');
    assert.equal(app.received.length, 1);
    const relayed = app.received[0];
    assert.equal(relayed.url, 'http://app:8080/records?page=2');
    assert.equal(relayed.headers.get('X-Forwarded-For'), '203.0.113.7');
    assert.equal(
        relayed.headers.get('X-Forwarded-Host'),
        'intra-deliver.example.workers.dev',
    );
    assert.equal(relayed.headers.get('X-Forwarded-Proto'), 'https');
    assert.equal(relayed.redirect, 'manual');
});

await test('CF-Connecting-IP が無いときは、訪問者の X-Forwarded-For を消す', async () => {
    const app = fakeApp(() => new Response('ok'));
    const request = await loggedInRequest(app.env, '/', {
        headers: { 'X-Forwarded-For': '198.51.100.1' },
    });

    await worker.fetch(request, app.env);

    assert.equal(app.received[0].headers.get('X-Forwarded-For'), null);
});

await test('入口の cookie は app へ渡さず、ほかのヘッダーと cookie は順のまま渡す', async () => {
    const app = fakeApp(() => new Response('ok'));
    const entry = await logIn(app.env);
    const request = new Request(`${ORIGIN}/`, {
        headers: {
            Accept: 'text/html',
            Cookie: `intra-deliver-session=abc; ${entry}; XSRF-TOKEN=token`,
        },
    });

    await worker.fetch(request, app.env);

    const relayed = app.received[0].headers;
    assert.equal(relayed.get('Accept'), 'text/html');
    assert.equal(
        relayed.get('Cookie'),
        'intra-deliver-session=abc; XSRF-TOKEN=token',
    );
});

await test('cookie の名前は大文字と小文字を区別し、小文字の __host-entry は渡す', async () => {
    const app = fakeApp(() => new Response('ok'));
    const request = await loggedInRequest(app.env, '/', {
        cookies: '__host-entry=x',
    });

    await worker.fetch(request, app.env);

    assert.equal(app.received[0].headers.get('Cookie'), '__host-entry=x');
});

await test('cookie が入口のものだけなら Cookie のヘッダーを消す', async () => {
    const app = fakeApp(() => new Response('ok'));
    const request = await loggedInRequest(app.env, '/');

    await worker.fetch(request, app.env);

    assert.equal(app.received[0].headers.get('Cookie'), null);
});

await test('残す cookie は、値に `,` があっても切らずに順のまま渡す', async () => {
    const app = fakeApp(() => new Response('ok'));
    const request = await loggedInRequest(app.env, '/', {
        cookies: 'intra-deliver-session=abc; pref=a,b',
    });

    await worker.fetch(request, app.env);

    assert.equal(
        app.received[0].headers.get('Cookie'),
        'intra-deliver-session=abc; pref=a,b',
    );
});

await test('Cookie のヘッダーが 2 本で `, ` でつながれたときは、2 本目から後の入口の cookie が残る(受け入れた残り)', async () => {
    const app = fakeApp(() => new Response('ok'));
    const entry = await logIn(app.env);
    // 2 本を `, ` でつないだ値を直に置く(node の Headers は Cookie を `; ` でつなぎ、Workers の形を作れないため)
    const request = new Request(`${ORIGIN}/`, {
        headers: { Cookie: `${entry}; a=1, ${entry}; b=2` },
    });

    const response = await worker.fetch(request, app.env);

    assert.equal(response.status, 200);
    assert.equal(app.received[0].headers.get('Cookie'), `a=1, ${entry}; b=2`);
    assert.ok(
        app.received[0].headers.get('Cookie')?.includes(`${ENTRY_COOKIE}=`),
    );
});

await test('app のリダイレクトは辿らずにそのまま返す', async () => {
    const app = fakeApp(
        () =>
            new Response(null, {
                status: 302,
                headers: { Location: '/login' },
            }),
    );

    const response = await worker.fetch(
        await loggedInRequest(app.env, '/'),
        app.env,
    );

    assert.equal(response.status, 302);
    assert.equal(response.headers.get('Location'), '/login');
});

await test('app の 404・500 は案内ページに替えない', async () => {
    for (const status of [404, 500]) {
        const app = fakeApp(() => new Response('app', { status }));

        const response = await worker.fetch(
            await loggedInRequest(app.env, '/'),
            app.env,
        );

        assert.equal(response.status, status);
        assert.equal(await response.text(), 'app');
    }
});

await test('502・503・504 は案内ページ(503)に替える', async () => {
    for (const status of [502, 503, 504]) {
        const app = fakeApp(() => new Response('upstream', { status }));

        await assertUnavailablePage(
            await worker.fetch(await loggedInRequest(app.env, '/'), app.env),
        );
    }
});

await test('中継が例外になったら案内ページを返し、記録を残す', async (t) => {
    const app = fakeApp(() => {
        throw new Error('tunnel down');
    });
    const request = await loggedInRequest(app.env, '/');
    const logged = t.mock.method(console, 'error', () => {});

    await assertUnavailablePage(await worker.fetch(request, app.env));
    assert.equal(logged.mock.callCount(), 1);
});

await test('502 の本文を捨てるのに失敗しても案内ページを返し、記録を残す', async (t) => {
    const body = new ReadableStream({
        cancel() {
            throw new Error('cancel failed');
        },
    });
    const app = fakeApp(() => new Response(body, { status: 502 }));
    const request = await loggedInRequest(app.env, '/');
    const logged = t.mock.method(console, 'error', () => {});

    await assertUnavailablePage(await worker.fetch(request, app.env));
    assert.equal(logged.mock.callCount(), 1);
});
