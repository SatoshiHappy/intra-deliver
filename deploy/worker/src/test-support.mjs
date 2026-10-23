// 入口の Worker の単体テストが共有する偽物(テストのファイルではない。node --test の対象の *.test.mjs に合わない名前)。

/**
 * 受け取った要求を記録し、決めた応答(か例外)を返す偽の binding。
 * @param {(request: Request) => Response | Promise<Response>} respond
 */
export function fakeApp(respond) {
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
