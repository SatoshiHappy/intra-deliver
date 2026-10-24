// 入口のログインの戻り先(`next`)の単体テスト(docs の 設計/公開の配備/入口/ログイン.md 5.9.1)。
// ログイン画面と POST は login.test.mjs。
import assert from 'node:assert/strict';
import { test } from 'node:test';
import worker from './index.js';
import {
    fakeApp,
    getLoginPage,
    hiddenNext,
    loginRequest,
    ORIGIN,
    SECRETS,
} from './test-support.mjs';

const HOST = new URL(ORIGIN).host;

await test('next: 同じ origin の値はその URL へ戻り、ログイン画面のフォームにも同じ URL を持つ', async () => {
    const app = fakeApp(() => new Response('ok'));
    const expected = `${ORIGIN}/records?x=1`;

    const page = await worker.fetch(
        getLoginPage('/_entry/login?next=%2Frecords%3Fx%3D1'),
        app.env,
    );
    const posted = await worker.fetch(
        loginRequest({
            id: SECRETS.ENTRY_ID,
            password: SECRETS.ENTRY_PASSWORD,
            next: '/records?x=1',
        }),
        app.env,
    );

    assert.equal(hiddenNext(await page.text()), expected);
    assert.equal(posted.status, 303);
    assert.equal(posted.headers.get('Location'), expected);
});

await test('next: よその宛先に解ける値と解けない値は `/` へ、同じ origin に解ける値はその URL へ(どれも要求のホストの絶対 URL)', async () => {
    const app = fakeApp(() => new Response('ok'));
    const cases = [
        ['//evil.example/x', `${ORIGIN}/`],
        ['/\\evil.example', `${ORIGIN}/`],
        ['/\t/evil.example', `${ORIGIN}/`],
        ['/\n/evil.example', `${ORIGIN}/`],
        ['https://evil.example/', `${ORIGIN}/`],
        ['javascript:alert(1)', `${ORIGIN}/`],
        ['http://[', `${ORIGIN}/`],
        ['/%09/evil.example', `${ORIGIN}/%09/evil.example`],
        ['/%0A/evil.example', `${ORIGIN}/%0A/evil.example`],
        ['/.//evil.example', `${ORIGIN}//evil.example`],
        ['/%2e//evil.example', `${ORIGIN}//evil.example`],
        ['/a/..//evil.example', `${ORIGIN}//evil.example`],
        [`${ORIGIN}/records`, `${ORIGIN}/records`],
    ];

    for (const [next, expected] of cases) {
        const page = await worker.fetch(
            getLoginPage(`/_entry/login?${new URLSearchParams({ next })}`),
            app.env,
        );
        const posted = await worker.fetch(
            loginRequest({
                id: SECRETS.ENTRY_ID,
                password: SECRETS.ENTRY_PASSWORD,
                next,
            }),
            app.env,
        );

        const held = hiddenNext(await page.text());
        const location = posted.headers.get('Location') ?? '';
        assert.equal(held, expected, JSON.stringify(next));
        assert.equal(location, expected, JSON.stringify(next));
        assert.equal(new URL(location).host, HOST, JSON.stringify(next));
    }
});

await test('next: 誤りの 401 の画面も、確かめを通した next を持ち続ける', async () => {
    const app = fakeApp(() => new Response('ok'));

    const response = await worker.fetch(
        loginRequest({ id: 'other', password: 'other', next: '/records?x=1' }),
        app.env,
    );

    assert.equal(response.status, 401);
    assert.equal(hiddenNext(await response.text()), `${ORIGIN}/records?x=1`);
});

await test('next: HTML の特殊文字を含む値は、フォームの属性の値に符号化して入り、属性を閉じない', async () => {
    const app = fakeApp(() => new Response('ok'));
    const next = `/records?q="><script>alert(1)</script>&a='b'`;

    const response = await worker.fetch(
        getLoginPage(`/_entry/login?${new URLSearchParams({ next })}`),
        app.env,
    );

    const html = await response.text();
    assert.doesNotMatch(html, /<script/i);
    assert.equal(html.includes('"><'), false);
    const held = hiddenNext(html);
    assert.equal(new URL(held).origin, ORIGIN);
    assert.equal(
        new URL(held).searchParams.get('q'),
        '"><script>alert(1)</script>',
    );
    assert.match(html, /value="[^"]*&amp;a=/);
});

await test('next: 戻り先を持たせる GET の 302 から入ると、元の URL へ戻る', async () => {
    const app = fakeApp(() => new Response('ok'));

    const redirected = await worker.fetch(
        new Request(`${ORIGIN}/records/12?tab=history&page=2`),
        app.env,
    );
    const location = redirected.headers.get('Location') ?? '';
    const page = await worker.fetch(getLoginPage(location), app.env);
    const next = hiddenNext(await page.text());
    const posted = await worker.fetch(
        loginRequest({
            id: SECRETS.ENTRY_ID,
            password: SECRETS.ENTRY_PASSWORD,
            next,
        }),
        app.env,
    );

    assert.equal(redirected.status, 302);
    assert.equal(
        posted.headers.get('Location'),
        `${ORIGIN}/records/12?tab=history&page=2`,
    );
});
