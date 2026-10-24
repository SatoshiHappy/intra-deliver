// 入口のログイン画面(日本語)。設計 = docs の 設計/公開の配備/入口/ログイン.md 5.9.2。外の資源を読まず、スクリプトを置かない。
// 知らせの文の正本 = 設計/公開の配備/毎日の初期化.md 3 節。見た目は案内ページ(unavailable-page.js)に揃える。

/** @type {Record<string, string>} */
const HTML_ESCAPES = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
};

/** @param {string} text */
function escapeHtml(text) {
    return text.replace(/[&<>"']/g, (char) => HTML_ESCAPES[char]);
}

const LOGIN_STYLE = `body {
  margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f4f8f4; color: #1f2d24;
  font-family: system-ui, "Hiragino Sans", "Noto Sans JP", sans-serif;
}
main {
  width: 100%; max-width: 24rem; margin: 1rem; padding: 2rem; box-sizing: border-box;
  background: #fff; border: 1px solid #d6e4d9; border-radius: 0.75rem;
}
h1 { margin: 0 0 1rem; font-size: 1.25rem; }
p { margin: 0 0 1rem; line-height: 1.7; }
.error { padding: 0.5rem 0.75rem; background: #fdecec; color: #8a1c1c; border-radius: 0.5rem; }
label { display: block; margin: 0 0 0.25rem; font-weight: 600; }
input {
  display: block; width: 100%; margin: 0 0 1rem; padding: 0.5rem 0.75rem; box-sizing: border-box;
  font: inherit; border: 1px solid #b9cdbf; border-radius: 0.5rem;
}
button {
  width: 100%; padding: 0.625rem; font: inherit; font-weight: 600; color: #fff; background: #2f6b45;
  border: 0; border-radius: 0.5rem; cursor: pointer;
}
`;

/**
 * @param {object} options
 * @param {string} options.next 確かめを通した戻り先(入口/ログイン.md 5.9.1 の `next`)
 * @param {boolean} options.failed ID かパスワードが違ったときの画面か
 * @returns {string}
 */
export function loginPage({ next, failed }) {
    const error = failed
        ? '<p class="error" role="alert">ID かパスワードが違います。</p>\n'
        : '';
    return `<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>ログイン | イントラデリバー</title>
<style>
${LOGIN_STYLE}</style>
</head>
<body>
<main>
<h1>イントラデリバー</h1>
<p>公開デモのデータは、毎日 4:00 に初期データへ戻ります。</p>
${error}<form method="post" action="/_entry/login">
<input type="hidden" name="next" value="${escapeHtml(next)}">
<label for="id">ID</label>
<input id="id" name="id" type="text" autocomplete="username" autocapitalize="none" spellcheck="false">
<label for="password">パスワード</label>
<input id="password" name="password" type="password" autocomplete="current-password">
<button type="submit">ログイン</button>
</form>
</main>
</body>
</html>
`;
}
