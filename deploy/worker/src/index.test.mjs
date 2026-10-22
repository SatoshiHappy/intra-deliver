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

// Access が通した要求の ctx(中身は Worker が読まないので最小にする)
const accessed = { access: { aud: 'test-aud' } };

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

await test('Access が通していない要求(ctx.access が無い)は 403 の固定の文を返し、本文を読まず app へ中継しない', async () => {
    const app = fakeApp(() => new Response('ok'));
    const request = new Request(
        'https://intra-deliver.example.workers.dev/records',
        {
            method: 'POST',
            body: 'title=x',
        },
    );

    const response = await worker.fetch(request, app.env, {});

    assert.equal(response.status, 403);
    assert.equal(
        response.headers.get('Content-Type'),
        'text/plain; charset=utf-8',
    );
    assert.equal(response.headers.get('Cache-Control'), 'no-store');
    assert.equal(
        await response.text(),
        'この要求は入口(ログイン)を通っていないため、受け付けません。',
    );
    assert.equal(app.received.length, 0);
    assert.equal(request.bodyUsed, false);
});

await test('ctx が無い呼び出しも 403 で閉じ、app へ中継しない', async () => {
    const app = fakeApp(() => new Response('ok'));

    const response = await worker.fetch(
        new Request('https://intra-deliver.example.workers.dev/'),
        app.env,
    );

    assert.equal(response.status, 403);
    assert.equal(app.received.length, 0);
});

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

    const response = await worker.fetch(request, app.env, accessed);

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

    await worker.fetch(request, app.env, accessed);

    assert.equal(app.received[0].headers.get('X-Forwarded-For'), null);
});

await test('Access の資格情報(Cf-Access- のヘッダーと CF_ の cookie)は app へ渡さず、ほかのヘッダーと cookie は渡す', async () => {
    const app = fakeApp(() => new Response('ok'));
    const request = new Request('https://intra-deliver.example.workers.dev/', {
        headers: {
            Accept: 'text/html',
            'Cf-Access-Jwt-Assertion': 'jwt',
            'Cf-Access-Authenticated-User-Email': 'recruiter@example.com',
            'CF-Access-Client-Id': 'client-id',
            'CF-Access-Client-Secret': 'client-secret',
            Cookie: 'CF_Authorization=jwt; intra-deliver-session=abc; CF_AppSession=nonce; XSRF-TOKEN=token',
        },
    });

    await worker.fetch(request, app.env, accessed);

    const relayed = app.received[0].headers;
    for (const name of [
        'Cf-Access-Jwt-Assertion',
        'Cf-Access-Authenticated-User-Email',
        'CF-Access-Client-Id',
        'CF-Access-Client-Secret',
    ]) {
        assert.equal(relayed.get(name), null, name);
    }
    assert.equal(relayed.get('Accept'), 'text/html');
    assert.equal(
        relayed.get('Cookie'),
        'intra-deliver-session=abc; XSRF-TOKEN=token',
    );
});

await test('cookie の名前は大文字と小文字を区別し、小文字の cf_ で始まる cookie は渡す', async () => {
    const app = fakeApp(() => new Response('ok'));
    const request = new Request('https://intra-deliver.example.workers.dev/', {
        headers: { Cookie: 'CF_Authorization=jwt; cf_clearance=x' },
    });

    await worker.fetch(request, app.env, accessed);

    assert.equal(app.received[0].headers.get('Cookie'), 'cf_clearance=x');
});

await test('cookie が Access のものだけなら Cookie のヘッダーを消す', async () => {
    const app = fakeApp(() => new Response('ok'));
    const request = new Request('https://intra-deliver.example.workers.dev/', {
        headers: { Cookie: 'CF_Authorization=jwt; CF_AppSession=nonce' },
    });

    await worker.fetch(request, app.env, accessed);

    assert.equal(app.received[0].headers.get('Cookie'), null);
});

await test('残す cookie は、値に `,` があっても切らずに順のまま渡す', async () => {
    const app = fakeApp(() => new Response('ok'));
    const request = new Request('https://intra-deliver.example.workers.dev/', {
        headers: {
            Cookie: 'CF_Authorization=jwt; intra-deliver-session=abc; pref=a,b',
        },
    });

    await worker.fetch(request, app.env, accessed);

    assert.equal(
        app.received[0].headers.get('Cookie'),
        'intra-deliver-session=abc; pref=a,b',
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
        new Request('https://intra-deliver.example.workers.dev/'),
        app.env,
        accessed,
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
            accessed,
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
                accessed,
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
            accessed,
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
            accessed,
        ),
    );
    assert.equal(logged.mock.callCount(), 1);
});
