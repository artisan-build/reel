import { execFileSync, spawn } from 'node:child_process';
import {
    existsSync,
    mkdtempSync,
    readFileSync,
    rmSync,
    writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const chromeBinary = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const baseUrl = process.env.REEL_R1_BROWSER_BASE_URL;
const artifactDirectory = process.env.REEL_R1_BROWSER_ARTIFACT_DIR;
const candidateSha = process.env.REEL_R1_BROWSER_CANDIDATE_SHA;
const applicationPath = process.env.REEL_R1_BROWSER_APPLICATION_PATH;
const sessionPath = process.env.REEL_R1_BROWSER_SESSION_PATH;

if (!baseUrl || !artifactDirectory || !candidateSha || !applicationPath || !sessionPath) {
    throw new Error('Browser lane configuration is incomplete.');
}

const parsedBaseUrl = new URL(baseUrl);
if (parsedBaseUrl.protocol !== 'http:' || parsedBaseUrl.hostname !== '127.0.0.1' || parsedBaseUrl.pathname !== '/') {
    throw new Error('Browser lane requires an HTTP loopback origin.');
}
if (!/^[a-f0-9]{40}$/.test(candidateSha)
    || !/^\/applications\/[0-9A-HJKMNP-TV-Z]{26}$/.test(applicationPath)
    || !/^\/applications\/[0-9A-HJKMNP-TV-Z]{26}\/sessions\/[a-f0-9]{64}$/.test(sessionPath)) {
    throw new Error('Browser lane received invalid candidate fixture identifiers.');
}

const roleCases = [
    {
        role: 'owner',
        email: 'r1-browser-owner@example.test',
        screenshot: 'browser-owner.png',
        memberManagement: true,
        managedTransitions: true,
    },
    {
        role: 'admin',
        email: 'r1-browser-admin@example.test',
        screenshot: 'browser-admin.png',
        memberManagement: true,
        managedTransitions: false,
    },
    {
        role: 'member',
        email: 'r1-browser-member@example.test',
        screenshot: 'browser-member.png',
        memberManagement: false,
        managedTransitions: false,
    },
];

const navigationDiagnosticMaxBytes = 1024;
const sleep = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

class NavigationDiagnosticError extends Error {}

async function waitFor(fn, message, attempts = 100) {
    for (let attempt = 0; attempt < attempts; attempt++) {
        try {
            const value = await fn();
            if (value) return value;
        } catch {}
        await sleep(100);
    }
    throw new Error(message);
}

function clearClipboard() {
    execFileSync('/usr/bin/pbcopy', { input: '', stdio: ['pipe', 'ignore', 'ignore'] });
}

const profile = mkdtempSync(join(tmpdir(), 'reel-r1-chrome-'));
const chrome = spawn(chromeBinary, [
    '--headless=new',
    '--incognito',
    '--disable-background-networking',
    '--disable-component-update',
    '--disable-default-apps',
    '--disable-extensions',
    '--disable-features=Translate,MediaRouter,OptimizationHints',
    '--disable-sync',
    '--metrics-recording-only',
    '--no-first-run',
    '--no-default-browser-check',
    '--remote-debugging-port=0',
    `--user-data-dir=${profile}`,
    '--window-size=1440,1000',
    'about:blank',
], { stdio: 'ignore' });
const chromeExited = new Promise((resolve) => chrome.once('exit', resolve));

let socket;
let nextId = 0;
const pending = new Map();
let mainDocumentStatus = null;
let mainDocumentFailed = false;

function send(method, params = {}) {
    return new Promise((resolve, reject) => {
        const id = ++nextId;
        pending.set(id, { resolve, reject });
        socket.send(JSON.stringify({ id, method, params }));
    });
}

async function evaluate(expression) {
    const response = await send('Runtime.evaluate', {
        expression,
        awaitPromise: true,
        returnByValue: true,
    });
    if (response.exceptionDetails) throw new Error('Browser expression failed.');
    return response.result.value;
}

function sanitizePath(path) {
    const normalized = String(path ?? '')
        .split(/[?#]/, 1)[0]
        .replace(/[0-9A-HJKMNP-TV-Z]{26}|[a-f0-9]{64}/g, '{fixture}');
    const allowed = [
        '/',
        '/bfc/login',
        '/bfc/ui',
        '/bfc/members',
        '/dashboard',
        '/applications',
        '/sessions',
        '/applications/{fixture}',
        '/applications/{fixture}/sessions/{fixture}',
    ];

    return allowed.includes(normalized) ? normalized : '/{other}';
}

async function navigationDiagnostic(path, marker) {
    let pageState = {};
    try {
        pageState = await evaluate(`({
            currentPath: location.pathname,
            readyState: document.readyState,
            expectedMarkerPresent: Boolean(document.querySelector(${JSON.stringify(marker)})),
            errorMarkerPresent: Boolean(document.querySelector('[data-testid="error-page"], [data-testid="server-error"]')),
            loginMarkerPresent: Boolean(document.querySelector('[data-testid="login-form"]')),
        })`);
    } catch {}

    const diagnostic = JSON.stringify({
        expected_path: sanitizePath(path),
        current_path: sanitizePath(pageState.currentPath),
        document_ready_state: ['loading', 'interactive', 'complete'].includes(pageState.readyState)
            ? pageState.readyState
            : 'unavailable',
        main_document_status: Number.isInteger(mainDocumentStatus) && mainDocumentStatus >= 100 && mainDocumentStatus <= 599
            ? mainDocumentStatus
            : null,
        expected_marker_present: pageState.expectedMarkerPresent === true,
        error_marker_present: pageState.errorMarkerPresent === true,
        login_marker_present: pageState.loginMarkerPresent === true,
        document_request_failed: mainDocumentFailed,
        cdp_socket_open: socket?.readyState === WebSocket.OPEN,
        chrome_running: chrome.exitCode === null && chrome.signalCode === null,
    });

    return Buffer.byteLength(diagnostic, 'utf8') <= navigationDiagnosticMaxBytes
        ? diagnostic
        : '{"diagnostic":"unavailable"}';
}

async function navigate(path, marker) {
    mainDocumentStatus = null;
    mainDocumentFailed = false;
    await send('Page.navigate', { url: baseUrl + path });
    try {
        await waitFor(
            () => evaluate(`document.readyState === 'complete'
                && location.pathname === ${JSON.stringify(path)}
                && Boolean(document.querySelector(${JSON.stringify(marker)}))`),
            'Browser navigation timed out.',
        );
    } catch {
        throw new NavigationDiagnosticError(`Browser navigation failed: ${await navigationDiagnostic(path, marker)}`);
    }
}

async function screenshot(name) {
    const result = await send('Page.captureScreenshot', { format: 'png', fromSurface: true });
    try {
        writeFileSync(join(artifactDirectory, name), Buffer.from(result.data, 'base64'));
    } catch {
        throw new Error(`Browser screenshot could not be written: ${name}.`);
    }
}

async function login(roleCase, password) {
    await navigate('/bfc/login', '[data-testid="login-form"]');
    const submitted = await evaluate(`(() => {
        const form = document.querySelector('[data-testid="login-form"] form');
        if (!form) return false;
        form.querySelector('[name="email"]').value = ${JSON.stringify(roleCase.email)};
        form.querySelector('[name="password"]').value = ${JSON.stringify(password)};
        form.requestSubmit();
        return true;
    })()`);
    if (!submitted) throw new Error('Package login form was unavailable.');

    try {
        await waitFor(
            () => evaluate(`document.readyState === 'complete'
                && location.pathname === '/bfc/ui'
                && Boolean(document.querySelector('[data-testid="ui-shell"]'))`),
            `Package login did not complete for ${roleCase.role}.`,
        );
    } catch {
        throw new NavigationDiagnosticError(`Package login did not complete for ${roleCase.role}: ${await navigationDiagnostic('/bfc/ui', '[data-testid="ui-shell"]')}`);
    }
}

async function proveRole(roleCase) {
    const affordances = await evaluate(`({
        memberManagement: Boolean(document.querySelector('[data-testid="ui-nav-member-management"]')),
        managedTransitions: Boolean(document.querySelector('[data-testid="ui-nav-managed-transitions"]')),
        sessionManagement: Boolean(document.querySelector('[data-testid="ui-nav-session-management"]')),
    })`);
    if (affordances.memberManagement !== roleCase.memberManagement
        || affordances.managedTransitions !== roleCase.managedTransitions
        || !affordances.sessionManagement) {
        throw new Error(`Package role affordances were incorrect for ${roleCase.role}.`);
    }

    await navigate('/bfc/members', '[data-testid="members-management"]');
    const identityVisible = await evaluate(`[...document.querySelectorAll('[data-testid="members-item"]')]
        .some((item) => item.textContent.includes(${JSON.stringify(roleCase.email)})
            && item.textContent.includes(${JSON.stringify(roleCase.role)}))`);
    if (!identityVisible) throw new Error(`Package identity label was unavailable for ${roleCase.role}.`);
    await screenshot(roleCase.screenshot);
}

async function proveReelSurfaces(roleCase) {
    await navigate('/dashboard', '[data-test="retention-diagnostics"]');
    if (!await evaluate(`document.body.textContent.includes('Operational health')`)) {
        throw new Error(`Dashboard content was unavailable for ${roleCase.role}.`);
    }

    await navigate('/applications', '[data-testid="logout-button"]');
    if (!await evaluate(`document.body.textContent.includes('R1 Browser Application')
        && document.body.textContent.includes('New application')`)) {
        throw new Error(`Application affordances were unavailable for ${roleCase.role}.`);
    }

    await navigate('/sessions', '[data-testid="session-list"]');
    if (!await evaluate(`document.body.textContent.includes('R1 Browser Application')
        && document.body.textContent.includes('Inspect')`)) {
        throw new Error(`Session affordances were unavailable for ${roleCase.role}.`);
    }

    await navigate(applicationPath, '[data-testid="application-signing-credentials"]');
    if (!await evaluate(`document.body.textContent.includes('Signing credentials')
        && document.body.textContent.includes('Issue credential')`)) {
        throw new Error(`Signing credential affordances were unavailable for ${roleCase.role}.`);
    }

    await navigate(sessionPath, '[data-testid="retention-controls"]');
    if (!await evaluate(`document.body.textContent.includes('Retention')
        && document.body.textContent.includes('Protect recording')
        && document.body.textContent.includes('Delete now')`)) {
        throw new Error(`Retention and protection controls were unavailable for ${roleCase.role}.`);
    }
}

async function logout(roleCase) {
    await navigate('/bfc/ui', '[data-testid="ui-logout-form"]');
    const submitted = await evaluate(`(() => {
        const form = document.querySelector('[data-testid="ui-logout-form"]');
        if (!form) return false;
        form.requestSubmit();
        return true;
    })()`);
    if (!submitted) throw new Error(`Package logout was unavailable for ${roleCase.role}.`);
    await waitFor(
        () => evaluate(`document.readyState === 'complete'
            && location.pathname === '/'
            && Boolean(document.querySelector('[data-testid="landing"]'))`),
        `Package logout did not complete for ${roleCase.role}.`,
    );
    await send('Network.clearBrowserCookies');
}

const evidence = {
    schema: 'reel.r1.browser.v1',
    candidate_sha: candidateSha,
    browser: {
        product: 'Google Chrome',
        version: execFileSync(chromeBinary, ['--version'], { encoding: 'utf8' }).trim(),
        mode: 'headless incognito standalone CDP',
    },
    cases: {},
    verifier_blocked: [],
    cleanup: {
        clipboard_cleared: false,
        chrome_stopped: false,
        profile_removed: false,
    },
    secrets_recorded: false,
};

let failure;
let disposablePassword = '';
try {
    const debugPort = await waitFor(() => {
        try {
            return readFileSync(join(profile, 'DevToolsActivePort'), 'utf8').split('\n')[0];
        } catch {
            return '';
        }
    }, 'Chrome did not expose a debugging port.');
    const target = await waitFor(async () => {
        const response = await fetch(`http://127.0.0.1:${debugPort}/json/list`);
        const rows = await response.json();
        return rows.find((row) => row.type === 'page' && row.webSocketDebuggerUrl);
    }, 'Chrome page target was unavailable.');

    socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
        socket.onopen = resolve;
        socket.onerror = () => reject(new Error('Chrome debugging socket failed.'));
    });
    socket.onmessage = (event) => {
        const message = JSON.parse(event.data);
        if (message.method === 'Network.responseReceived'
            && message.params?.type === 'Document'
            && Number.isInteger(message.params.response?.status)) {
            mainDocumentStatus = message.params.response.status;
        }
        if (message.method === 'Network.loadingFailed' && message.params?.type === 'Document') {
            mainDocumentFailed = true;
        }
        if (!message.id || !pending.has(message.id)) return;
        const waiter = pending.get(message.id);
        pending.delete(message.id);
        if (message.error) waiter.reject(new Error('Chrome command failed.'));
        else waiter.resolve(message.result);
    };
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Network.enable');

    disposablePassword = execFileSync('/usr/bin/pbpaste', { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
    clearClipboard();
    if (!disposablePassword) throw new Error('Disposable browser password was unavailable.');

    for (const roleCase of roleCases) {
        await login(roleCase, disposablePassword);
        await proveRole(roleCase);
        await proveReelSurfaces(roleCase);
        await logout(roleCase);
        evidence.cases[roleCase.role] = {
            result: 'pass',
            identity: roleCase.email,
            role_label: roleCase.role,
            paths: [
                '/bfc/login',
                '/bfc/ui',
                '/bfc/members',
                '/dashboard',
                '/applications',
                '/sessions',
                '/applications/{application}',
                '/applications/{application}/sessions/{session}',
            ],
            markers: [
                'login-form',
                'ui-shell',
                'members-management',
                'members-item',
                'retention-diagnostics',
                'session-list',
                'application-signing-credentials',
                'retention-controls',
            ],
            actions: ['login', 'inspect-role', 'navigate-reel-surfaces', 'logout'],
            screenshot: roleCase.screenshot,
        };
    }
} catch (error) {
    failure = error;
} finally {
    disposablePassword = '';
    try {
        clearClipboard();
        evidence.cleanup.clipboard_cleared = true;
    } catch {
        failure ??= new Error('Browser clipboard cleanup failed.');
    }
    try {
        socket?.close();
        if (chrome.exitCode === null) {
            chrome.kill('SIGTERM');
            await Promise.race([chromeExited, sleep(2000)]);
        }
        if (chrome.exitCode === null) {
            chrome.kill('SIGKILL');
            await Promise.race([chromeExited, sleep(2000)]);
        }
        evidence.cleanup.chrome_stopped = chrome.exitCode !== null || chrome.signalCode !== null;
        if (!evidence.cleanup.chrome_stopped) failure ??= new Error('Headless Chrome cleanup failed.');
    } catch {
        failure ??= new Error('Headless Chrome cleanup failed.');
    }
    try {
        rmSync(profile, { recursive: true, force: true });
        evidence.cleanup.profile_removed = !existsSync(profile);
        if (!evidence.cleanup.profile_removed) failure ??= new Error('Chrome profile cleanup failed.');
    } catch {
        failure ??= new Error('Chrome profile cleanup failed.');
    }
}

if (failure instanceof NavigationDiagnosticError) {
    process.stderr.write(`${failure.message}\n`);
    process.exit(1);
}
if (failure) throw failure;
if (!evidence.cleanup.clipboard_cleared || !evidence.cleanup.chrome_stopped || !evidence.cleanup.profile_removed) {
    throw new Error('Browser cleanup verdict was incomplete.');
}

try {
    writeFileSync(join(artifactDirectory, 'browser-evidence.json'), `${JSON.stringify(evidence, null, 2)}\n`);
} catch {
    throw new Error('Browser evidence could not be written.');
}
process.stdout.write('Reel R1 browser lane passed.\n');
