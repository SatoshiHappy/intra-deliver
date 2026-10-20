// PC・Tunnel・app のどれかが止まっているときに返す案内ページ(日本語)。画面の文言は設計の画面の意匠の文言の規則(11 節)に揃える。
export const UNAVAILABLE_PAGE = `<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>準備中 | イントラデリバー</title>
<style>
body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f4f8f4; color: #1f2d24; font-family: system-ui, "Hiragino Sans", "Noto Sans JP", sans-serif; }
main { max-width: 32rem; margin: 1rem; padding: 2rem; background: #fff; border: 1px solid #d6e4d9; border-radius: 0.75rem; }
h1 { margin: 0 0 1rem; font-size: 1.25rem; }
p { margin: 0 0 0.75rem; line-height: 1.7; }
p:last-child { margin-bottom: 0; }
</style>
</head>
<body>
<main>
<h1>ただいま準備中です</h1>
<p>イントラデリバーは現在、ご利用いただけません。</p>
<p>しばらくしてから、もう一度アクセスしてください。</p>
</main>
</body>
</html>
`;
