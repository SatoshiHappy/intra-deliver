// scripts/ci/record の単体テスト(node --test 'scripts/ci/*.test.mjs')。一時の git リポジトリと QUALITY_LOG の差し替えで確かめる。
// 正本 = docs の 設計/コード構成/検査/結果の記録.md の 6.6「結果の記録」。
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { execFile, execFileSync, spawnSync } from 'node:child_process';
import {
    existsSync,
    mkdirSync,
    mkdtempSync,
    readdirSync,
    readFileSync,
    rmSync,
    writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { after, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

const RECORD = resolve(dirname(fileURLToPath(import.meta.url)), 'record');
const APP_ROOT = resolve(dirname(RECORD), '..', '..');
const KEYS = [
    'v',
    'at',
    'worktree',
    'branch',
    'head',
    'tool',
    'argv',
    'run',
    'exit',
    'sec',
    'dirty',
    'cfg',
];
const execFileAsync = promisify(execFile);
const temporaryDirectories = [];

after(() => {
    for (const directory of temporaryDirectories) {
        rmSync(directory, { recursive: true, force: true });
    }
});

function temporaryDirectory() {
    const directory = mkdtempSync(join(tmpdir(), 'record-test-'));
    temporaryDirectories.push(directory);
    return directory;
}

/** 呼び出し元の git の環境変数と親の run を引き継がない環境(テストの git が外のリポジトリを触らず、行に親が混ざらないように)。 */
function cleanEnvironment(extra = {}) {
    const environment = { ...process.env };
    for (const name of Object.keys(environment)) {
        if (name.startsWith('GIT_') || name === 'QUALITY_RUN_ID') {
            delete environment[name];
        }
    }
    return { ...environment, ...extra };
}

function git(directory, ...args) {
    return execFileSync('git', args, {
        cwd: directory,
        encoding: 'utf8',
        env: cleanEnvironment(),
    });
}

/** 追跡したファイルの入った git リポジトリ(コミット済み)を作る。 */
function makeRepository() {
    const directory = temporaryDirectory();
    git(directory, 'init', '-q', '-b', 'main');
    git(directory, 'config', 'user.email', 'test@example.com');
    git(directory, 'config', 'user.name', 'test');
    git(directory, 'config', 'commit.gpgsign', 'false');
    mkdirSync(join(directory, 'app'));
    mkdirSync(join(directory, 'scripts', 'ci'), { recursive: true });
    writeFileSync(join(directory, 'app', 'code.php'), '<?php // 1\n');
    writeFileSync(
        join(directory, 'phpstan.neon'),
        'parameters:\n    level: 8\n',
    );
    writeFileSync(join(directory, 'scripts', 'ci', 'one'), 'one\n');
    writeFileSync(join(directory, 'scripts', 'ci', 'two'), 'two\n');
    writeComposer(directory, {
        scripts: { check: 'a' },
        require: { php: '^8.4' },
    });
    git(directory, 'add', '-A');
    git(directory, 'commit', '-q', '-m', 'first');
    return directory;
}

function writeComposer(directory, content) {
    writeFileSync(
        join(directory, 'composer.json'),
        JSON.stringify(content, null, 4),
    );
}

function logPath() {
    return join(temporaryDirectory(), 'state', 'quality.jsonl');
}

/** record を走らせる(同期)。 */
function record(directory, log, command = ['true'], tool = 'probe') {
    return spawnSync('bash', [RECORD, tool, '--', ...command], {
        cwd: directory,
        encoding: 'utf8',
        env: cleanEnvironment({
            QUALITY_LOG: log,
            GIT_CEILING_DIRECTORIES: dirname(directory),
        }),
    });
}

function readRows(log) {
    return readFileSync(log, 'utf8')
        .split('\n')
        .filter((line) => line !== '')
        .map((line) => JSON.parse(line));
}

function lastRow(log) {
    return readRows(log).at(-1);
}

await test('成功するコマンドは終了コード 0 で、exit 0 の行が 1 つ増える', () => {
    const log = logPath();
    const result = record(makeRepository(), log, ['true']);

    assert.equal(result.status, 0);
    const rows = readRows(log);
    assert.equal(rows.length, 1);
    assert.equal(rows[0].exit, 0);
    assert.equal(rows[0].tool, 'probe');
});

await test('失敗するコマンドの終了コードをそのまま返し、exit にも記録する', () => {
    const log = logPath();
    const result = record(makeRepository(), log, ['sh', '-c', 'exit 3']);

    assert.equal(result.status, 3);
    assert.equal(lastRow(log).exit, 3);
});

await test('コマンドの標準出力と標準エラーは素通しになる', () => {
    const result = record(makeRepository(), logPath(), [
        'sh',
        '-c',
        'echo to-out; echo to-err >&2',
    ]);

    assert.match(result.stdout, /to-out/);
    assert.match(result.stderr, /to-err/);
});

/** 記録に失敗する作り(root でも同じに失敗する)。 */
function unwritableLogs() {
    const base = temporaryDirectory();

    const fileAsParent = join(base, 'file-as-parent');
    writeFileSync(fileAsParent, 'x\n');

    const lockIsDirectory = join(base, 'lock-dir', 'quality.jsonl');
    mkdirSync(`${lockIsDirectory}.lock`, { recursive: true });

    const logIsDirectory = join(base, 'log-dir', 'quality.jsonl');
    mkdirSync(logIsDirectory, { recursive: true });

    return [
        {
            log: join(fileAsParent, 'quality.jsonl'),
            reason: /記録のディレクトリを作れない/,
        },
        { log: lockIsDirectory, reason: /記録のロックのファイルを開けない/ },
        { log: logIsDirectory, reason: /記録のファイルに書けない/ },
    ];
}

await test('記録の場所に書けないとき、終了コードは変わらず、stderr に理由が出て、追記されない', () => {
    const repository = makeRepository();
    const cases = unwritableLogs();

    for (const { log, reason } of cases) {
        for (const expected of [0, 3]) {
            const result = record(repository, log, [
                'sh',
                '-c',
                `exit ${expected}`,
            ]);

            assert.equal(result.status, expected);
            assert.match(result.stderr, /record: 記録できない/);
            assert.match(result.stderr, reason);
        }
    }
    // ロックのファイルがディレクトリの作りでは、記録のファイルを作っていない。記録のファイルがディレクトリの作りでは、中に何も書いていない
    assert.ok(!existsSync(cases[1].log));
    assert.deepEqual(readdirSync(cases[2].log), []);
});

await test('1 行は JSON として読め、4KB 未満で、項目がそろう。ブランチ名に引用符があっても読める', () => {
    const repository = makeRepository();
    git(repository, 'checkout', '-q', '-b', 'feature/a"b');
    const log = logPath();
    record(repository, log);

    const text = readFileSync(log, 'utf8');
    assert.ok(Buffer.byteLength(text) < 4096);
    const row = JSON.parse(text);
    assert.deepEqual(Object.keys(row), KEYS);
    assert.equal(row.v, 1);
    assert.match(row.argv, /^[0-9a-f]{12}$/);
    assert.ok(row.run.length > 0);
    assert.equal(row.branch, 'feature/a"b');
    assert.equal(row.worktree, repository.split('/').at(-1));
    assert.match(row.head, /^[0-9a-f]{40}$/);
    assert.match(row.dirty, /^[0-9a-f]{40}$/);
    assert.match(
        row.at,
        /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/,
    );
    assert.equal(typeof row.sec, 'number');
});

await test('切り離した HEAD のブランチ名は HEAD', () => {
    const repository = makeRepository();
    git(repository, 'checkout', '-q', '--detach');
    const log = logPath();
    record(repository, log);

    assert.equal(lastRow(log).branch, 'HEAD');
});

await test('追跡しているコードのファイルを変えると dirty だけが変わる', () => {
    const repository = makeRepository();
    const log = logPath();
    record(repository, log);
    writeFileSync(join(repository, 'app', 'code.php'), '<?php // 2\n');
    record(repository, log);

    const [before, after] = readRows(log);
    assert.notEqual(after.dirty, before.dirty);
    assert.deepEqual(after.cfg, before.cfg);
});

await test('cfg の対象を変えると、その項目と dirty が変わる', () => {
    const repository = makeRepository();
    const log = logPath();
    record(repository, log);
    writeFileSync(
        join(repository, 'phpstan.neon'),
        'parameters:\n    level: 9\n',
    );
    record(repository, log);

    const [before, after] = readRows(log);
    assert.notEqual(after.dirty, before.dirty);
    assert.notEqual(after.cfg['phpstan.neon'], before.cfg['phpstan.neon']);
    assert.equal(
        after.cfg['composer.json#scripts'],
        before.cfg['composer.json#scripts'],
    );
});

await test('composer.json の scripts を変えると scripts の項目が変わり、require だけなら変わらない', () => {
    const repository = makeRepository();
    const log = logPath();
    record(repository, log);
    writeComposer(repository, {
        scripts: { check: 'a' },
        require: { php: '^8.5' },
    });
    record(repository, log);
    writeComposer(repository, {
        scripts: { check: 'b' },
        require: { php: '^8.5' },
    });
    record(repository, log);

    const [base, requireOnly, scriptsChanged] = readRows(log);
    assert.notEqual(requireOnly.dirty, base.dirty);
    assert.equal(
        requireOnly.cfg['composer.json#scripts'],
        base.cfg['composer.json#scripts'],
    );
    assert.notEqual(
        scriptsChanged.cfg['composer.json#scripts'],
        requireOnly.cfg['composer.json#scripts'],
    );
});

await test('追跡外のファイルの中身を変えると dirty が変わる', () => {
    const repository = makeRepository();
    const log = logPath();
    writeFileSync(join(repository, 'untracked.txt'), 'one\n');
    record(repository, log);
    writeFileSync(join(repository, 'untracked.txt'), 'two\n');
    record(repository, log);

    const [before, after] = readRows(log);
    assert.notEqual(after.dirty, before.dirty);
});

await test('コミットしても内容が同じなら dirty と cfg は変わらない', () => {
    const repository = makeRepository();
    const log = logPath();
    writeFileSync(join(repository, 'app', 'code.php'), '<?php // 3\n');
    record(repository, log);
    git(repository, 'add', '-A');
    git(repository, 'commit', '-q', '-m', 'second');
    record(repository, log);

    const [before, after] = readRows(log);
    assert.notEqual(after.head, before.head);
    assert.equal(after.dirty, before.dirty);
    assert.deepEqual(after.cfg, before.cfg);
});

/** git status・index のファイル・オブジェクトの領域の状態。 */
function gitState(directory) {
    const indexPath = git(
        directory,
        'rev-parse',
        '--path-format=absolute',
        '--git-path',
        'index',
    ).trim();
    return {
        status: git(directory, 'status', '--porcelain'),
        index: readFileSync(indexPath).toString('base64'),
        objects: git(directory, 'count-objects', '-v'),
    };
}

await test('実 index と実のオブジェクトの領域を変えない(追跡外のファイルがあっても)', () => {
    const repository = makeRepository();
    writeFileSync(join(repository, 'untracked.txt'), 'a secret-like body\n');
    writeFileSync(join(repository, 'app', 'code.php'), '<?php // 4\n');
    const before = gitState(repository);
    record(repository, logPath());

    assert.deepEqual(gitState(repository), before);
});

await test('cfg のディレクトリの対象は 1 項目になる(中身が並ばない)', () => {
    const repository = makeRepository();
    const log = logPath();
    record(repository, log);

    const { cfg } = lastRow(log);
    assert.ok('scripts/ci' in cfg);
    assert.ok(!Object.keys(cfg).some((key) => key.startsWith('scripts/ci/')));
});

await test('この作業木の実物でも 1 行が 4KB 未満で、cfg に scripts/ci がある', () => {
    const log = logPath();
    const result = record(APP_ROOT, log);

    // 記録が取れない状態(読めないファイルなど)は気づくべきなので、行が無いときは stderr を添えて失敗させる
    assert.ok(
        existsSync(log),
        `record が行を書かなかった(終了コード ${result.status}): ${result.stderr}`,
    );
    assert.equal(result.status, 0);
    assert.ok(Buffer.byteLength(readFileSync(log, 'utf8')) < 4096);
    assert.ok('scripts/ci' in lastRow(log).cfg);
});

await test('git のリポジトリの外では、コマンドは走り、終了コードは変わらず、stderr に理由が出る', () => {
    const outside = temporaryDirectory();
    const log = logPath();
    const result = record(outside, log, ['sh', '-c', 'echo ran; exit 4']);

    assert.equal(result.status, 4);
    assert.match(result.stdout, /ran/);
    assert.match(result.stderr, /record: 指紋を取れないので記録しない/);
    assert.throws(() => readFileSync(log));
});

await test('指紋の手順が失敗するとき(壊れた index)も、コマンドは走り、終了コードは変わらず、理由が出る', () => {
    const repository = makeRepository();
    writeFileSync(join(repository, '.git', 'index'), 'not an index\n');
    const log = logPath();
    const result = record(repository, log, ['sh', '-c', 'echo ran; exit 5']);

    assert.equal(result.status, 5);
    assert.match(result.stdout, /ran/);
    assert.match(
        result.stderr,
        /record: 指紋を取れないので記録しない: 指紋の手順が失敗した\(.+\)/,
    );
    assert.ok(!existsSync(log));
});

await test('composer.json が壊れているとき、理由にファイル名とメッセージが出る(node の [eval] ではない)', () => {
    const repository = makeRepository();
    writeFileSync(join(repository, 'composer.json'), '{"scripts": ');
    const log = logPath();
    const result = record(repository, log, ['sh', '-c', 'echo ran; exit 6']);

    assert.equal(result.status, 6);
    assert.match(result.stdout, /ran/);
    assert.match(result.stderr, /指紋の手順が失敗した\(composer\.json: .+\)/);
    assert.doesNotMatch(result.stderr, /\[eval\]/);
    assert.ok(!existsSync(log));
});

await test('走っている間にファイルを変えても、dirty は走らせる前の状態のもの', () => {
    const repository = makeRepository();
    const log = logPath();
    record(repository, log);
    record(repository, log, ['sh', '-c', 'echo changed > app/code.php']);
    record(repository, log);

    const [baseline, duringChange, afterChange] = readRows(log);
    assert.equal(duringChange.dirty, baseline.dirty);
    assert.notEqual(afterChange.dirty, baseline.dirty);
});

await test('末尾が改行で終わらない記録のファイルでも、新しい行は独立した 1 行になる', () => {
    const repository = makeRepository();
    const log = logPath();
    mkdirSync(dirname(log), { recursive: true });
    writeFileSync(log, '{"v":1,"at":"途中で切れた');
    const result = record(repository, log);

    assert.equal(result.status, 0);
    const lines = readFileSync(log, 'utf8').split('\n').filter(Boolean);
    assert.equal(lines.length, 2);
    assert.throws(() => JSON.parse(lines[0]));
    assert.equal(JSON.parse(lines[1]).tool, 'probe');
});

await test('並行に 20 回走らせても、20 行がすべて JSON として読める', async () => {
    const repository = makeRepository();
    const log = logPath();
    const environment = cleanEnvironment({ QUALITY_LOG: log });
    const results = await Promise.all(
        Array.from({ length: 20 }, () =>
            execFileAsync('bash', [RECORD, 'probe', '--', 'true'], {
                cwd: repository,
                env: environment,
            }),
        ),
    );

    assert.equal(results.length, 20);
    const rows = readRows(log);
    assert.equal(rows.length, 20);
    assert.ok(rows.every((row) => row.tool === 'probe' && row.exit === 0));
});

await test('使い方が違うと 2 で終わり、何も実行しない', () => {
    const result = spawnSync('bash', [RECORD, 'probe', 'true'], {
        encoding: 'utf8',
        env: cleanEnvironment(),
    });

    assert.equal(result.status, 2);
    assert.match(result.stderr, /使い方/);
});

function digestOf(command) {
    return createHash('sha256')
        .update(command.join('\0'))
        .digest('hex')
        .slice(0, 12);
}

await test('argv はコマンドと引数を NUL でつないだものの SHA-256 の先頭 12 桁で、引数そのものは残らない', () => {
    const repository = makeRepository();
    const log = logPath();
    record(repository, log, ['true']);
    record(repository, log, ['true']);
    record(repository, log, ['true', 'secret-argument']);
    record(repository, log, ['true', 'a b']);
    record(repository, log, ['true', 'a', 'b']);

    const rows = readRows(log);
    assert.equal(rows[0].argv, digestOf(['true']));
    assert.equal(rows[1].argv, rows[0].argv);
    assert.equal(rows[2].argv, digestOf(['true', 'secret-argument']));
    assert.notEqual(rows[2].argv, rows[0].argv);
    assert.notEqual(rows[3].argv, rows[4].argv);
    assert.ok(!readFileSync(log, 'utf8').includes('secret-argument'));
});

await test('run は実行ごとに違い、親が無ければ parent は出ない', () => {
    const repository = makeRepository();
    const log = logPath();
    record(repository, log);
    record(repository, log);

    const [first, second] = readRows(log);
    assert.notEqual(first.run, second.run);
    assert.ok(!('parent' in first));
});

await test('record の中から record を呼ぶと、子の行の parent が親の run と同じになる', () => {
    const repository = makeRepository();
    const log = logPath();
    const result = record(
        repository,
        log,
        ['bash', RECORD, 'child', '--', 'sh', '-c', 'exit 2'],
        'parent',
    );

    assert.equal(result.status, 2);
    const [child, parent] = readRows(log);
    assert.equal(parent.tool, 'parent');
    assert.ok(!('parent' in parent));
    assert.equal(child.tool, 'child');
    assert.equal(child.parent, parent.run);
    assert.notEqual(child.run, parent.run);
});

await test('形の違う QUALITY_RUN_ID は親に結ばず、stderr に理由を出し、値は出さない', () => {
    const repository = makeRepository();
    const log = logPath();
    const value = '{"x":1} hidden-value';
    const result = spawnSync('bash', [RECORD, 'probe', '--', 'true'], {
        cwd: repository,
        encoding: 'utf8',
        env: cleanEnvironment({
            QUALITY_LOG: log,
            QUALITY_RUN_ID: value,
            GIT_CEILING_DIRECTORIES: dirname(repository),
        }),
    });

    assert.equal(result.status, 0);
    assert.match(result.stderr, /QUALITY_RUN_ID の形が違う/);
    assert.ok(!result.stderr.includes('hidden-value'));
    const [row] = readRows(log);
    assert.ok(!('parent' in row));
    assert.ok(!readFileSync(log, 'utf8').includes('hidden-value'));
});

await test('親が指紋を取れず行を書かないときは、子へ run を渡さない', () => {
    const repository = makeRepository();
    const outside = temporaryDirectory();
    const log = logPath();
    const result = spawnSync(
        'bash',
        [
            RECORD,
            'parent',
            '--',
            'bash',
            '-c',
            'cd "$1" && bash "$2" child -- true',
            'child-shell',
            repository,
            RECORD,
        ],
        {
            cwd: outside,
            encoding: 'utf8',
            env: cleanEnvironment({
                QUALITY_LOG: log,
                GIT_CEILING_DIRECTORIES: `${dirname(outside)}:${dirname(repository)}`,
            }),
        },
    );

    assert.equal(result.status, 0);
    assert.match(result.stderr, /指紋を取れないので記録しない/);
    const rows = readRows(log);
    assert.equal(rows.length, 1);
    assert.equal(rows[0].tool, 'child');
    assert.ok(!('parent' in rows[0]));
});

await test('at はコマンドを始めた時刻で、at + sec が終わりの時刻になる', () => {
    const repository = makeRepository();
    const log = logPath();
    const beforeRun = Date.now();
    record(repository, log, ['sleep', '1']);
    const afterRun = Date.now();

    const row = lastRow(log);
    const startedAt = Date.parse(row.at);
    const finishedAt = startedAt + row.sec * 1000;
    // at は秒の精度なので、前後に 1 秒の余裕を見る
    assert.ok(startedAt >= beforeRun - 1000);
    assert.ok(finishedAt <= afterRun + 1000);
    assert.ok(row.sec >= 1);
});
