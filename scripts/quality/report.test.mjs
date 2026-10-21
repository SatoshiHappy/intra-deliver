// scripts/quality/report.mjs の単体テスト(node --test 'scripts/quality/*.test.mjs')。記録の行は作りもので、QUALITY_LOG の差し替えで CLI も確かめる。
// 正本 = docs の 設計/コード構成/検査/結果の記録.md の 6.6「結果の記録」。
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath, pathToFileURL } from 'node:url';

import { classify, logPath, parseLog, render, summarize } from './report.mjs';

const SCRIPT = resolve(dirname(fileURLToPath(import.meta.url)), 'report.mjs');

const CFG = { phpstan: 'aaaaaaaaaaaa', 'scripts/ci': 'bbbbbbbbbbbb' };
let clock = 0;

// 1 分ずつ進む時刻の行。上書きした項目だけが変わる
function row(overrides = {}) {
    clock += 1;
    const at = new Date(Date.UTC(2026, 9, 16, 0, clock))
        .toISOString()
        .replace('Z', '+00:00');
    return {
        v: 1,
        at,
        worktree: 'web-a',
        branch: 'work',
        head: '0'.repeat(40),
        tool: 'phpstan',
        argv: '111111111111',
        run: `1-${clock}-1`,
        exit: 0,
        sec: 1,
        dirty: 'd0',
        cfg: CFG,
        ...overrides,
    };
}

function totals(rows, tool = 'phpstan', since = null) {
    return summarize(
        parseLog(rows.map((r) => JSON.stringify(r)).join('\n')).rows,
        since,
    ).tools.get(tool);
}

await test('件にならない 0 はヒットなし、件は非 0 から次の 0 まで', () => {
    const t = totals([
        row(),
        row({ exit: 1 }),
        row({ exit: 1 }),
        row({ exit: 0, dirty: 'd1' }),
        row({ dirty: 'd1' }),
    ]);
    assert.equal(t.runs, 5);
    assert.equal(t.hitless, 2);
    assert.equal(t.kinds['有効'], 1);
});

await test('区分は branch → cfg → dirty の順に始まりと終わりで比べる', () => {
    const start = row({ exit: 1 });
    assert.equal(
        classify(start, row({ branch: 'other', cfg: {}, dirty: 'x' })),
        '判定不能',
    );
    assert.equal(
        classify(
            start,
            row({ cfg: { ...CFG, phpstan: 'cccccccccccc' }, dirty: 'x' }),
        ),
        '無効',
    );
    assert.equal(
        classify(start, row({ cfg: { phpstan: CFG.phpstan } })),
        '無効',
    );
    assert.equal(classify(start, row({ dirty: 'x' })), '有効');
    assert.equal(classify(start, row()), '揺れ');
});

await test('件の途中の行の dirty・cfg は区分に効かない(始まりと終わりだけを比べる)', () => {
    const t = totals([
        row({ exit: 1 }),
        row({ exit: 1, dirty: 'd9', cfg: {} }),
        row(),
    ]);
    assert.equal(t.kinds['揺れ'], 1);
});

await test('終了コード 126・127・129〜192 は走らなかった: 件を開かず閉じず、実行とヒットなしに数えず、秒は数える', () => {
    const t = totals([
        row({ exit: 1, sec: 2 }),
        row({ exit: 130, sec: 3 }),
        row({ exit: 126 }),
        row({ exit: 192 }),
        row({ exit: 127 }),
        row({ exit: 125, sec: 4 }),
        row({ exit: 0, dirty: 'd1', sec: 5 }),
    ]);
    assert.equal(t.notRun, 4);
    assert.equal(t.runs, 3);
    assert.equal(t.hitless, 0);
    assert.equal(t.kinds['有効'], 1);
    assert.equal(t.sec, 17);
});

await test('終了コード 128・193・255 は失敗: 件を開き、直した後の 0 で閉じる', () => {
    for (const exit of [128, 193, 255]) {
        const t = totals([row({ exit }), row({ exit: 0, dirty: 'd1' })]);
        assert.equal(t.kinds['有効'], 1, `exit ${exit}`);
        assert.equal(t.notRun, 0, `exit ${exit}`);
        assert.equal(t.hitless, 0, `exit ${exit}`);
        assert.equal(t.runs, 2, `exit ${exit}`);
    }
});

await test('最後まで 0 にならない件は未解消', () => {
    const t = totals([row({ exit: 1 }), row({ exit: 2 })]);
    assert.equal(t.kinds['未解消'], 1);
    assert.equal(t.hitless, 0);
});

await test('件は作業木と道具ごとに分かれる', () => {
    const rows = [
        row({ exit: 1 }),
        row({ worktree: 'web-b' }),
        row({ tool: 'tsc' }),
        row({ exit: 0, dirty: 'd1' }),
    ];
    assert.equal(totals(rows).kinds['有効'], 1);
    assert.equal(totals(rows).hitless, 1);
    assert.equal(totals(rows, 'tsc').hitless, 1);
});

await test('行は追記の順に流す(始まりの時刻が逆でも、先に追記された結果が先)', () => {
    const fail = row({ exit: 1 });
    const pass = row({ dirty: 'd1' });
    const t = totals([pass, fail]);
    assert.equal(t.hitless, 1);
    assert.equal(t.kinds['未解消'], 1);
    assert.equal(t.kinds['有効'], 0);
    assert.equal(totals([fail, pass]).kinds['有効'], 1);
});

await test('一括の行は回数と秒だけで、件もヒットなしも作らない', () => {
    const rows = [
        row({ tool: 'ci-check', exit: 1, sec: 10 }),
        row({ tool: 'ci-check', sec: 20 }),
        row({ tool: 'ci-full', sec: 5 }),
    ];
    const summary = summarize(
        parseLog(rows.map((r) => JSON.stringify(r)).join('\n')).rows,
    );
    assert.equal(summary.tools.size, 0);
    assert.deepEqual(summary.batches.get('ci-check'), {
        count: 2,
        notRun: 0,
        sec: 30,
    });
    assert.deepEqual(summary.batches.get('ci-full'), {
        count: 1,
        notRun: 0,
        sec: 5,
    });
});

await test('一括の行も 126・127・129〜192 は走らなかった: 回数に入れず、秒は数える。255 は回数に入れる', () => {
    const summary = summarize(
        parseLog(
            [
                row({ tool: 'ci-check', sec: 10 }),
                row({ tool: 'ci-check', exit: 130, sec: 3 }),
                row({ tool: 'ci-check', exit: 255, sec: 1 }),
            ]
                .map((r) => JSON.stringify(r))
                .join('\n'),
        ).rows,
    );
    assert.deepEqual(summary.batches.get('ci-check'), {
        count: 2,
        notRun: 1,
        sec: 14,
    });
});

await test('--since は件の判定の後に当て、件は始まりの時刻で数える', () => {
    const before = row({ exit: 1 });
    const boundary = new Date(Date.parse(before.at) + 30_000);
    const t = totals(
        [
            before,
            row({ dirty: 'd1' }),
            row(),
            row({ exit: 1 }),
            row({ dirty: 'd2' }),
        ],
        'phpstan',
        boundary,
    );
    assert.equal(t.kinds['有効'], 1);
    assert.equal(t.runs, 4);
    assert.equal(t.hitless, 1);
});

await test('壊れた行・項目の欠けた行は飛ばし、行の番号を返す', () => {
    const { rows, broken } = parseLog(
        `${JSON.stringify(row())}\n{"v":1,\n\n${JSON.stringify({ v: 1, tool: 'x' })}\n`,
    );
    assert.equal(rows.length, 1);
    assert.deepEqual(broken, [2, 4]);
});

await test('記録の形式(版 1)に合わない行は壊れた行: cfg が null か配列・dirty や branch が無い・版が 2', () => {
    const without = (key) => {
        const copy = row();
        delete copy[key];
        return copy;
    };
    const lines = [
        row({ cfg: null }),
        row({ cfg: [] }),
        without('dirty'),
        without('branch'),
        row({ v: 2 }),
        row(),
    ];
    const { rows, broken } = parseLog(
        lines.map((line) => JSON.stringify(line)).join('\n'),
    );
    assert.equal(rows.length, 1);
    assert.deepEqual(broken, [1, 2, 3, 4, 5]);
});

await test('置き場は record と同じ規則(QUALITY_LOG → XDG_STATE_HOME → HOME)', () => {
    assert.equal(
        logPath({
            QUALITY_LOG: '/x/q.jsonl',
            XDG_STATE_HOME: '/s',
            HOME: '/h',
        }),
        '/x/q.jsonl',
    );
    assert.equal(
        logPath({ QUALITY_LOG: '', XDG_STATE_HOME: '/s', HOME: '/h' }),
        '/s/intra-deliver/quality.jsonl',
    );
    assert.equal(
        logPath({ HOME: '/h' }),
        '/h/.local/state/intra-deliver/quality.jsonl',
    );
});

await test('表は Markdown で、率の分母が 0 なら — にする', () => {
    const output = render(
        summarize(
            parseLog(JSON.stringify(row({ tool: 'tsc', exit: 130 }))).rows,
        ),
    );
    assert.match(output, /^\| 道具 \| 実行 \|/);
    assert.match(
        output,
        /\| tsc \| 0 \| 0 \| — \| 0 \| 0 \| — \| 0 \| 0 \| 0 \| 1 \| 1\.0 \|/,
    );
});

await test('CLI: QUALITY_LOG を読み、壊れた行を stderr に出して終了 0', (t) => {
    const directory = mkdtempSync(join(tmpdir(), 'quality-report-'));
    t.after(() => rmSync(directory, { recursive: true, force: true }));
    const log = join(directory, 'quality.jsonl');
    writeFileSync(
        log,
        `${JSON.stringify(row({ exit: 1 }))}\nnot json\n${JSON.stringify(row({ dirty: 'd1' }))}\n`,
    );
    const result = spawnSync(process.execPath, [SCRIPT], {
        env: { ...process.env, QUALITY_LOG: log },
        encoding: 'utf8',
    });
    assert.equal(result.status, 0, result.stderr);
    assert.match(
        result.stdout,
        /\| phpstan \| 2 \| 0 \| 0% \| 1 \| 0 \| 0% \|/,
    );
    assert.match(result.stderr, /quality\.jsonl:2: 壊れた行を飛ばした/);
});

await test('CLI: 存在しない日付の --since は終了 2', () => {
    const result = spawnSync(
        process.execPath,
        [SCRIPT, '--since', '2026-02-31'],
        { encoding: 'utf8' },
    );
    assert.equal(result.status, 2);
    assert.match(result.stderr, /--since は YYYY-MM-DD/);
});

await test('CLI: シンボリックリンク経由の起動でも表を出す', (t) => {
    const directory = mkdtempSync(join(tmpdir(), 'quality-report-link-'));
    t.after(() => rmSync(directory, { recursive: true, force: true }));
    const log = join(directory, 'quality.jsonl');
    writeFileSync(log, `${JSON.stringify(row())}\n`);
    const link = join(directory, 'link.mjs');
    symlinkSync(SCRIPT, link);
    const result = spawnSync(process.execPath, [link], {
        env: { ...process.env, QUALITY_LOG: log },
        encoding: 'utf8',
    });
    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /\| phpstan \| 1 \| 1 \|/);
});

await test('import しただけでは何も出さず、node -e からの import も落ちない', () => {
    const result = spawnSync(
        process.execPath,
        [
            '--input-type=module',
            '-e',
            `await import(${JSON.stringify(pathToFileURL(SCRIPT).href)});`,
        ],
        {
            env: { ...process.env, QUALITY_LOG: '/nonexistent/none.jsonl' },
            encoding: 'utf8',
        },
    );
    assert.equal(result.status, 0, result.stderr);
    assert.equal(result.stdout, '');
    assert.equal(result.stderr, '');
});

await test('CLI: 記録が無い・--since の形が違うときは理由を出して非 0', () => {
    const missing = spawnSync(process.execPath, [SCRIPT], {
        env: {
            ...process.env,
            QUALITY_LOG: join(tmpdir(), 'quality-report-missing', 'none.jsonl'),
        },
        encoding: 'utf8',
    });
    assert.equal(missing.status, 1);
    assert.match(missing.stderr, /記録を読めない/);
    const badSince = spawnSync(
        process.execPath,
        [SCRIPT, '--since', '2026/10/16'],
        { encoding: 'utf8' },
    );
    assert.equal(badSince.status, 2);
    assert.match(badSince.stderr, /--since は YYYY-MM-DD/);
});
