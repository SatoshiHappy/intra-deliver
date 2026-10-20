// 入口の Worker(index.js)の単体テスト(node --test 'deploy/worker/src/*.test.mjs')。VPC サービスの binding は偽物に置き換える。
import assert from 'node:assert/strict';
import { test } from 'node:test';
import worker from './index.js';

/**
 * 受け取った要求を記録し、決めた応答(か例外)を返す偽の binding。
 * @param {(request: Request) => Response | Promise<Response>} respond
 */
function fakeApp(respond) {
    /** @type {Request[]} */
    const received = [];
    return {
        received,
        env: {
            APP: {
                fetch: async (request) => {
                    received.push(request);
                    return respond(request);
                },
            },
        },
    };
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
    const request = new Request(
        'https://intra-deliver.example.workers.dev/records?page=2',
        {
            headers: {
                'CF-Connecting-IP': '203.0.113.7',
                'X-Forwarded-For': '198.51.100.1',
                'X-Forwarded-Host': 'evil.example',
                'X-Forwarded-Proto': 'http',
            },
        },
    );

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
    const request = new Request('https://intra-deliver.example.workers.dev/', {
        headers: { 'X-Forwarded-For': '198.51.100.1' },
    });

    await worker.fetch(request, app.env);

    assert.equal(app.received[0].headers.get('X-Forwarded-For'), null);
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
        new Request('https://intra-deliver.example.workers.dev/'),
        app.env,
    );

    assert.equal(response.status, 302);
    assert.equal(response.headers.get('Location'), '/login');
});

await test('app の 404・500 は案内ページに替えない', async () => {
    for (const status of [404, 500]) {
        const app = fakeApp(() => new Response('app', { status }));

        const response = await worker.fetch(
            new Request('https://intra-deliver.example.workers.dev/'),
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
            await worker.fetch(
                new Request('https://intra-deliver.example.workers.dev/'),
                app.env,
            ),
        );
    }
});

await test('中継が例外になったら案内ページを返し、記録を残す', async (t) => {
    const logged = t.mock.method(console, 'error', () => {});
    const app = fakeApp(() => {
        throw new Error('tunnel down');
    });

    await assertUnavailablePage(
        await worker.fetch(
            new Request('https://intra-deliver.example.workers.dev/'),
            app.env,
        ),
    );
    assert.equal(logged.mock.callCount(), 1);
});

await test('502 の本文を捨てるのに失敗しても案内ページを返し、記録を残す', async (t) => {
    const logged = t.mock.method(console, 'error', () => {});
    const body = new ReadableStream({
        cancel() {
            throw new Error('cancel failed');
        },
    });
    const app = fakeApp(() => new Response(body, { status: 502 }));

    await assertUnavailablePage(
        await worker.fetch(
            new Request('https://intra-deliver.example.workers.dev/'),
            app.env,
        ),
    );
    assert.equal(logged.mock.callCount(), 1);
});
