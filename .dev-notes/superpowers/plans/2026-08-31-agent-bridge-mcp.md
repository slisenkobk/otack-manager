# Agent Bridge — MCP-мост. План внедрения

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **⚠️ Читать перед исполнением.** Владелец решил (2026-08-31) строить **сначала CLI**, потом этот мост. Значит к моменту, когда этот план дойдёт до исполнения, уже будет существовать работающая PHP-реализация той же логики — и **эти инструменты обязаны быть портом проверенного, а не повторным выводом из чтения API**. Конкретно: `Triage::pick()` и `Triage::newComments()` из `agent/lib/Triage.php` переносятся на JS вместе со своими тестовыми случаями, а не изобретаются заново.
>
> Причина решения, для следующего читателя: MCP окупается, когда потребителей больше одного и вы их не контролируете. Пока единственный потребитель — свой же крон-бот со своим же скиллом, CLI выигрывает по отладке (можно повторить вызовы руками), по зависимостям (ноль против семнадцати) и по независимости от харнесса. Этот мост нужен, когда таск-менеджер захотят дёргать из Claude Desktop, claude.ai или чужого агента.
>
> **Task 7 — не формальность.** Она сверяет таблицу инструментов с тем, что реально понадобилось CLI. Инструмент, которым CLI ни разу не воспользовался, скорее всего не нужен и здесь.
>
> **⚠️ 2026-08-31, пост-аудит.** Этот план прошёл проверку до исполнения (пока он ждёт своей очереди за CLI-планом) и правки уже внесены в текст ниже: точный пин `zod`, схемы инструментов как Zod raw shape, `next_task` переписан по образцу исправленного CLI (гейт по колонке done/backlog + повторная проверка `from_form`), убрана опережающая ссылка `read.js → tools/comments.js` (общая логика лежит в `mcp/src/client.js` и `mcp/src/watermark.js`, оба созданы раньше своих потребителей), `guard()` применяется единообразно ко всем двенадцати инструментам, ретраи на 429 читают `Retry-After`, пагинация комментариев следует `next_cursor`, и ещё десяток более мелких несоответствий кода API. Свежих открытий по существу это не отменяет — исполнитель всё равно обязан свериться с реальным CLI и с состоянием сервера на момент запуска.
>
> **⚠️ 2026-09-01, синк с закалённым CLI.** CLI-план (`.dev-notes/superpowers/plans/2026-08-31-agent-bridge-client.md`) прошёл адверсариальное ревью с мутационным тестированием на каждой задаче, и каждая задача нашла реальные дефекты (`.superpowers/sdd/2026-08-31-agent-bridge-client/progress.md`). Этот план был обновлён, чтобы не воспроизвести их вслепую: `mcp/src/client.js` теперь отклоняет небезопасный `baseUrl`, не следует редиректам, бросает на не-JSON теле 200-ответа, и `paginateAll`/пагинация различают полный результат от усечённого (Task 1); `mcp/src/local.js` бросает, а не молчит, на небулевом `write`, доступном на запись каталоге/файле, некорректной обёртке `projects`, неабсолютном/несуществующем `path`, и несёт новые `in_progress_column`/`review_column` (Task 2); `commentsSinceSelf` бросает на неположительный `selfId` (Task 2); `pickNextTask` теперь явно сортирует внутри ступени по возрастанию id, а не полагается на порядок вызывающего (Task 3); `computeNextTask` различает `idle` от `form_bound_exhausted` (Task 3); **`set_agent_state`/`update_task`/`move_task`/`add_comment`/`reply_comment`/`new_comments` теперь проверяют `project_id` задачи по allow-list перед записью** — в CLI ровно эти шесть аналогов были в числе команд, пропускавших гейт (Tasks 4-5); в README задокументированы находка про `--allowedTools` (границу прав задаёт хост запуска, не этот мост) и асимметрия разрешения колонок «в работе»/«на проверке» (Task 6). Test-счёт по проекту вырос с шестидесяти двух (последняя цифра до этой сверки, конец Task 5) до ста трёх (см. итоговый счёт по задачам ниже) — как и в CLI-плане, каждый дефект приходит с собственным тестом, а не только с прозой.

**Goal:** Отдать те же возможности, что даёт `otack` CLI, как типизированные MCP-инструменты — чтобы мостом могли пользоваться Claude Desktop, claude.ai и любой другой MCP-клиент, а не только Claude Code внутри репозитория.

**Architecture:** Node-сервер на stdio. Один HTTP-клиент к REST API, поверх него двенадцать курируемых инструментов вместо механического отображения сорока восьми маршрутов. Логика водораздела комментариев живёт внутри инструмента, а не повторяется в промптах.

**Tech Stack:** Node 18+ (в системе 24.13.0), `@modelcontextprotocol/sdk` и `zod` — единственные зависимости. Тесты — встроенный `node:test`, без дополнительных пакетов.

**Spec:** `docs/MCP.md` (замысел и таблица инструментов), `.dev-notes/superpowers/specs/2026-08-27-agent-bridge-design.md` §4.1 (водораздел), `docs/API.md` (контракт REST).

## Global Constraints

- Node 18+. **Разрешённые прямые зависимости — `@modelcontextprotocol/sdk` и `zod`.** Тестовый раннер — встроенный `node:test`, HTTP — встроенный `fetch`.
- **Цена зависимости от SDK названа осознанно:** SDK 1.30 тянет 17 прямых пакетов, включая `express`, `hono`, `cors`, `jose` и `pkce-challenge` — весь HTTP/SSE-стек, которым мы не пользуемся, потому что у нас stdio. Альтернатива — руками написать JSON-RPC поверх stdio (~200 строк, ноль зависимостей). **Решение: берём SDK.** Смысл этого моста в том, что его потребляют *чужие* клиенты, а значит корректность рукопожатия, `tools/list`, `tools/call` и уведомлений важнее экономии на пакетах. Версию **пинить точно** (`"@modelcontextprotocol/sdk": "1.30.0"`, без `^`) и обновлять осознанно: это код в контуре автономного исполнителя.
- **Тот же режим пина — у `zod`.** `McpServer.tool(name, description, paramsSchema, cb)` прогоняет `paramsSchema` через `zod-to-json-schema`; схема должна быть настоящим Zod-объектом (raw shape), а не простым JS-объектом-описанием. Раз три файла инструментов пишут `import { z } from 'zod'` напрямую, `zod` — не транзитивная деталь, а прямая зависимость, и её нужно объявить в `dependencies` явно и запинить точно (`"zod": "3.25.76"` — попадает в диапазон `^3.25 || ^4.0`, который требует SDK 1.30.0 на момент написания этого плана). Полагаться на то, что нужная версия случайно окажется поднята транзитивно, нельзя — npm этого не гарантирует.
- PHP-часть репозитория не трогаем. MCP-мост живёт в `mcp/` и не влияет на `make unit` / `make api`.
- Транспорт — **stdio**. Это локальный подпроцесс, а не сетевой сервис.
- Конфигурация — ровно две переменные: `OTACK_API_URL` (базовый URL с `/api/v1`) и `OTACK_API_TOKEN`. Никакой своей модели доступа: границы задаёт роль пользователя токена и его членство в проектах.
- Пять фаз — закрытое множество: `researching`, `awaiting_approval`, `implementing`, `review`, `blocked`.
- Требуется сервер **1.7.1+**.
- Инструмент, возвращающий ошибку API, обязан вернуть её человекочитаемо (код + `error` из конверта), а не бросать наружу сырой стек. Это гарантирует общий хелпер `guard()` (Task 1), применённый **единообразно ко всем двенадцати инструментам** — не выборочно.
- **Каждый инструмент, называющий конкретный проект по задаче, обязан пройти гейт `~/.claude/otack-agent/projects.json`** — не только инструменты чтения. Находка на CLI: четыре команды (`state`, `move`, `comment`, `new-comments`) пропускали этот гейт и были живьём проверены на проекте, которого в файле умышленно не было. Здесь это `get_task`/`get_project_context` (Task 3, уже соблюдали) и `set_agent_state`/`update_task`/`move_task` (Task 4), `add_comment`/`reply_comment`/`new_comments` (Task 5) — все девять резолвят `project_id` через `GET /tasks/{id}` и вызывают `assertProjectAllowed` прежде, чем действовать. Фильтрация внутри `next_task` — не замена этому гейту у остальных инструментов.

---

### Task 1: Каркас, HTTP-клиент и первый инструмент

Один инструмент, который доказывает, что транспорт, конфигурация и обработка ошибок работают. Всё остальное — тиражирование. Здесь же — три общих хелпера (`toolResult`, `guard`, `paginateAll`), которыми будут пользоваться все последующие задачи; они специально живут в Task 1, а не рядом с первым же инструментом, которому понадобились, чтобы ни один более поздний файл не тянул зависимость вперёд по номеру задачи.

**Files:**
- Create: `mcp/package.json`
- Create: `mcp/src/client.js`
- Create: `mcp/src/server.js`
- Test: `mcp/test/client.test.js`

**Interfaces:**
- Produces: `OtackClient` из `mcp/src/client.js` — конструктор `({baseUrl, token, fetchImpl, sleepImpl})`, отклоняющий небезопасный (не-`https`, не-loopback) `baseUrl`; методы `get(path, query)`, `post(path, body)`, `patch(path, body)`, каждый возвращает `{status, json}` или бросает (401, 3xx-редирект, не-JSON тело на 200). Плюс общие хелперы для всех инструментов: `toolResult(res, okStatuses)`, `guard(fn)`, `paginateAll(client, path, query, maxPages)`, `resolveSelfId(client)`. Инструмент `whoami` без аргументов.

- [ ] **Step 1: Написать падающие тесты**

`mcp/test/client.test.js`:

```javascript
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { OtackClient, toolResult, guard, paginateAll, resolveSelfId } from '../src/client.js';

function fakeFetch(responses) {
  const calls = [];
  const impl = async (url, opts) => {
    calls.push({ url, opts });
    const r = responses.shift() ?? { status: 200, body: '{}' };
    return {
      status: r.status,
      text: async () => r.body,
      headers: new Map(Object.entries(r.headers ?? {})),
    };
  };
  impl.calls = calls;
  return impl;
}

function fakeSleep() {
  const calls = [];
  const impl = async (ms) => { calls.push(ms); };
  impl.calls = calls;
  return impl;
}

test('get() builds a query string and sends the bearer token', async () => {
  const f = fakeFetch([{ status: 200, body: '{"items":[]}' }]);
  const c = new OtackClient({ baseUrl: 'https://x.example/api/v1', token: 'otk_abc', fetchImpl: f });
  await c.get('/tasks', { agent_state: 'review', limit: 50 });
  assert.equal(f.calls[0].url, 'https://x.example/api/v1/tasks?agent_state=review&limit=50');
  assert.equal(f.calls[0].opts.headers.Authorization, 'Bearer otk_abc');
});

test('get() omits the question mark when there is no query', async () => {
  const f = fakeFetch([]);
  const c = new OtackClient({ baseUrl: 'https://x.example/api/v1', token: 't', fetchImpl: f });
  await c.get('/me');
  assert.equal(f.calls[0].url, 'https://x.example/api/v1/me');
});

test('get() drops undefined and null query values instead of sending them', async () => {
  const f = fakeFetch([]);
  const c = new OtackClient({ baseUrl: 'https://x.example/api/v1', token: 't', fetchImpl: f });
  await c.get('/tasks', { assignee_id: 5, agent_state: undefined, limit: null });
  assert.equal(f.calls[0].url, 'https://x.example/api/v1/tasks?assignee_id=5');
});

test('post() sends a JSON body and content-type', async () => {
  const f = fakeFetch([{ status: 201, body: '{"id":9}' }]);
  const c = new OtackClient({ baseUrl: 'https://x.example/api/v1', token: 't', fetchImpl: f });
  const r = await c.post('/comments', { entity: 'task', entity_id: 7, body: 'hi' });
  assert.equal(f.calls[0].opts.method, 'POST');
  assert.equal(f.calls[0].opts.headers['Content-Type'], 'application/json');
  assert.equal(f.calls[0].opts.body, '{"entity":"task","entity_id":7,"body":"hi"}');
  assert.equal(r.status, 201);
  assert.equal(r.json.id, 9);
});

test('a 401 throws an error that names the token, not a raw status', async () => {
  const f = fakeFetch([{ status: 401, body: '{"error":"unauthorized"}' }]);
  const c = new OtackClient({ baseUrl: 'https://x.example/api/v1', token: 'bad', fetchImpl: f });
  await assert.rejects(() => c.get('/me'), /token/i);
});

test('no thrown error message contains the bearer token itself', async () => {
  const secret = 'otk_do_not_leak_me';
  const f = fakeFetch([{ status: 401, body: '{}' }]);
  const c = new OtackClient({ baseUrl: 'https://x.example/api/v1', token: secret, fetchImpl: f });
  try {
    await c.get('/me');
    assert.fail('expected a throw');
  } catch (e) {
    // Only e.message crosses the guard() boundary into a tool result; this
    // pins that none of the client's error paths interpolate the token
    // into that message (a stack trace is not exposed to the model either,
    // but V8 stack traces do not embed call-argument values the way some
    // other runtimes' tracebacks do, so the message is the surface that
    // actually matters here).
    assert.equal(e.message.includes(secret), false);
  }
});

test('the constructor rejects a non-https base URL that is not loopback', () => {
  assert.throws(
    () => new OtackClient({ baseUrl: 'http://otack-manager.example/api/v1', token: 't' }),
    /https/,
  );
});

test('the constructor accepts a plain http loopback base URL for local development', () => {
  assert.doesNotThrow(() => new OtackClient({ baseUrl: 'http://127.0.0.1:8080/api/v1', token: 't' }));
});

test('a 3xx response is refused rather than followed, naming the status and Location', async () => {
  const loc = { status: 302, body: '', headers: { Location: 'https://evil.example/steal' } };
  const f = fakeFetch([loc, loc]);
  const c = new OtackClient({ baseUrl: 'https://x.example/api/v1', token: 'otk_secret', fetchImpl: f });
  await assert.rejects(() => c.get('/tasks'), /302/);
  await assert.rejects(() => c.get('/tasks'), /evil\.example/);
  // Never re-fetched the redirect target, and asked fetch itself not to
  // chase it — each rejected call is the single hop, nothing more.
  assert.equal(f.calls.length, 2);
  assert.equal(f.calls[0].opts.redirect, 'manual');
});

test('a 200 response whose body is not valid JSON throws instead of decoding to an empty result', async () => {
  const f = fakeFetch([{ status: 200, body: '<html>Access Denied</html>' }]);
  const c = new OtackClient({ baseUrl: 'https://x.example/api/v1', token: 't', fetchImpl: f });
  await assert.rejects(() => c.get('/tasks'), /valid JSON/);
});

test('a 429 is retried once, waiting the seconds the server names in Retry-After', async () => {
  const f = fakeFetch([
    { status: 429, body: '{}', headers: { 'Retry-After': '5' } },
    { status: 200, body: '{"ok":true}' },
  ]);
  const sleep = fakeSleep();
  const c = new OtackClient({ baseUrl: 'https://x.example/api/v1', token: 't', fetchImpl: f, sleepImpl: sleep });
  const r = await c.get('/ping');
  assert.equal(r.status, 200);
  assert.equal(f.calls.length, 2);
  assert.deepEqual(sleep.calls, [5000]);
});

test('a second 429 is returned rather than retried forever', async () => {
  const f = fakeFetch([
    { status: 429, body: '{}', headers: { 'Retry-After': '2' } },
    { status: 429, body: '{}', headers: { 'Retry-After': '2' } },
  ]);
  const sleep = fakeSleep();
  const c = new OtackClient({ baseUrl: 'https://x.example/api/v1', token: 't', fetchImpl: f, sleepImpl: sleep });
  const r = await c.get('/ping');
  assert.equal(r.status, 429);
  assert.equal(f.calls.length, 2);
  assert.equal(sleep.calls.length, 1); // one wait, before the one retry — never a second
});

test('a 429 Retry-After above the rate-limit window is clamped to 65s', async () => {
  const f = fakeFetch([
    { status: 429, body: '{}', headers: { 'Retry-After': '9999' } },
    { status: 200, body: '{}' },
  ]);
  const sleep = fakeSleep();
  const c = new OtackClient({ baseUrl: 'https://x.example/api/v1', token: 't', fetchImpl: f, sleepImpl: sleep });
  await c.get('/ping');
  assert.deepEqual(sleep.calls, [65000]);
});

test('a 429 with no Retry-After header falls back to a 1s wait', async () => {
  const f = fakeFetch([
    { status: 429, body: '{}' },
    { status: 200, body: '{}' },
  ]);
  const sleep = fakeSleep();
  const c = new OtackClient({ baseUrl: 'https://x.example/api/v1', token: 't', fetchImpl: f, sleepImpl: sleep });
  await c.get('/ping');
  assert.deepEqual(sleep.calls, [1000]);
});

test('paginateAll follows next_cursor across pages and concatenates items', async () => {
  const f = fakeFetch([
    { status: 200, body: '{"items":[{"id":1},{"id":2}],"next_cursor":2}' },
    { status: 200, body: '{"items":[{"id":3}],"next_cursor":null}' },
  ]);
  const c = new OtackClient({ baseUrl: 'https://x.example/api/v1', token: 't', fetchImpl: f });
  const r = await paginateAll(c, '/tasks/1/comments');
  assert.equal(r.status, 200);
  assert.deepEqual(r.items.map(x => x.id), [1, 2, 3]);
  assert.equal(f.calls.length, 2);
  assert.match(f.calls[1].url, /after=2/);
});

test('paginateAll stops after a single page when next_cursor is absent', async () => {
  const f = fakeFetch([{ status: 200, body: '{"items":[{"id":1}]}' }]);
  const c = new OtackClient({ baseUrl: 'https://x.example/api/v1', token: 't', fetchImpl: f });
  const r = await paginateAll(c, '/tasks/1/comments');
  assert.equal(f.calls.length, 1);
  assert.deepEqual(r.items.map(x => x.id), [1]);
});

test('paginateAll throws rather than silently truncating when the page bound is hit with a cursor still pending', async () => {
  // Was: a silent return of exactly `maxPages` items — indistinguishable
  // from a genuinely complete short list, which is exactly the defect this
  // pins against (mirrors Client::getAll()'s equivalent J4 test).
  const responses = [];
  for (let i = 0; i < 20; i++) responses.push({ status: 200, body: `{"items":[{"id":${i}}],"next_cursor":${i}}` });
  const f = fakeFetch(responses);
  const c = new OtackClient({ baseUrl: 'https://x.example/api/v1', token: 't', fetchImpl: f });
  await assert.rejects(() => paginateAll(c, '/tasks/1/comments', {}, 3), /maxPages=3/);
  assert.equal(f.calls.length, 3); // all three pages were still fetched before the throw
});

test('paginateAll throws when next_cursor repeats instead of advancing', async () => {
  const f = fakeFetch([
    { status: 200, body: '{"items":[{"id":1}],"next_cursor":5}' },
    { status: 200, body: '{"items":[{"id":1}],"next_cursor":5}' },
  ]);
  const c = new OtackClient({ baseUrl: 'https://x.example/api/v1', token: 't', fetchImpl: f });
  await assert.rejects(() => paginateAll(c, '/tasks/1/comments'), /next_cursor.*did not advance/);
});

test('paginateAll throws instead of returning a partial list on a bad page', async () => {
  // Mirrors Client::getAll(): a non-200 page is a RuntimeException, not a
  // partial-items return — a caller that only checked r.status !== 200
  // could otherwise read "task not found" as "no new comments".
  const f = fakeFetch([
    { status: 200, body: '{"items":[{"id":1}],"next_cursor":1}' },
    { status: 403, body: '{"error":"forbidden"}' },
  ]);
  const c = new OtackClient({ baseUrl: 'https://x.example/api/v1', token: 't', fetchImpl: f });
  await assert.rejects(() => paginateAll(c, '/tasks/1/comments'), /403/);
});

test('resolveSelfId returns a valid positive integer id from GET /me', async () => {
  const f = fakeFetch([{ status: 200, body: '{"id":7}' }]);
  const c = new OtackClient({ baseUrl: 'https://x.example/api/v1', token: 't', fetchImpl: f });
  assert.equal(await resolveSelfId(c), 7);
});

test('resolveSelfId throws rather than degrading a zero, null, missing or non-integer id', async () => {
  for (const body of ['{"id":0}', '{"id":null}', '{}', '{"id":"7"}']) {
    const f = fakeFetch([{ status: 200, body }]);
    const c = new OtackClient({ baseUrl: 'https://x.example/api/v1', token: 't', fetchImpl: f });
    await assert.rejects(() => resolveSelfId(c), /invalid user id/);
  }
});

test('guard() turns a thrown error into a readable tool result instead of letting it escape', async () => {
  const wrapped = guard(async () => { throw new Error('boom'); });
  const r = await wrapped({});
  assert.equal(r.isError, true);
  assert.equal(r.content[0].text, 'boom');
});

test('guard() passes a successful result through unchanged', async () => {
  const wrapped = guard(async (args) => ({ content: [{ type: 'text', text: `ok:${args.x}` }] }));
  const r = await wrapped({ x: 1 });
  assert.equal(r.content[0].text, 'ok:1');
});
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `cd mcp && node --test`
Expected: FAIL — `src/client.js` не существует.

- [ ] **Step 3: Создать package.json**

`mcp/package.json`:

```json
{
  "name": "otack-manager-mcp",
  "version": "0.1.0",
  "private": true,
  "type": "module",
  "description": "MCP bridge exposing Otack Manager's REST API as curated tools",
  "bin": { "otack-mcp": "./src/server.js" },
  "scripts": { "test": "node --test" },
  "dependencies": {
    "@modelcontextprotocol/sdk": "1.30.0",
    "zod": "3.25.76"
  },
  "engines": { "node": ">=18" }
}
```

- [ ] **Step 4: Реализовать клиент**

`mcp/src/client.js`:

```javascript
/**
 * Minimal REST client for the Otack Manager API.
 *
 * `fetchImpl` and `sleepImpl` are injected so tests exercise URL building,
 * headers, retry timing and error mapping for real, without a network, a
 * stub server, or an actual wait.
 */
const LOOPBACK_HOSTS = new Set(['127.0.0.1', '::1', 'localhost']);

export class OtackClient {
  constructor({
    baseUrl,
    token,
    fetchImpl = globalThis.fetch,
    sleepImpl = (ms) => new Promise((r) => setTimeout(r, ms)),
  }) {
    if (!baseUrl) throw new Error('OTACK_API_URL is not set');
    if (!token) throw new Error('OTACK_API_TOKEN is not set');

    // The bearer token is the only access lever this API has (no scopes —
    // spec §10), so it must never travel in cleartext. Loopback is exempt:
    // tests and local development talk to 127.0.0.1 / ::1 / localhost
    // without TLS. Mirrors the same check in agent/lib/Client.php's
    // constructor — the two clients must not diverge on this.
    const parsed = new URL(baseUrl);
    if (parsed.protocol !== 'https:' && !LOOPBACK_HOSTS.has(parsed.hostname)) {
      throw new Error(
        `OtackClient requires an https:// base URL (got '${baseUrl}'); a plain http:// origin ` +
        'would send the bearer token in cleartext. Loopback hosts (127.0.0.1, ::1, localhost) ' +
        'are exempt for local development.',
      );
    }

    this.baseUrl = baseUrl.replace(/\/+$/, '');
    this.token = token;
    this.fetchImpl = fetchImpl;
    this.sleepImpl = sleepImpl;
  }

  get(path, query = {}) { return this.#request('GET', path, null, query); }
  post(path, body) { return this.#request('POST', path, body, {}); }
  patch(path, body) { return this.#request('PATCH', path, body, {}); }

  async #request(method, path, body, query) {
    const params = new URLSearchParams();
    for (const [k, v] of Object.entries(query)) {
      if (v !== undefined && v !== null && v !== '') params.append(k, String(v));
    }
    const qs = params.toString();
    const url = this.baseUrl + path + (qs ? `?${qs}` : '');

    const headers = {
      Accept: 'application/json',
      Authorization: `Bearer ${this.token}`,
      'User-Agent': 'otack-mcp',
    };
    // Never follow a redirect: it would re-send the Authorization header —
    // a full-power, unscoped bearer token (spec §10) — to whatever host
    // Location points at. A test server proved the PHP client did exactly
    // this before agent/lib/Client.php was fixed (`follow_location => 0`);
    // `redirect: 'manual'` plus the explicit 3xx check below is the fetch
    // equivalent, so this client cannot regress the same way.
    const opts = { method, headers, redirect: 'manual' };
    if (body !== null) {
      headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }

    let res = await this.fetchImpl(url, opts);

    // One retry on 429, waiting exactly what the server asked for
    // (ApiKernel::handle() sets Retry-After against its 60/min window —
    // system/Api/V1/ApiKernel.php:173-174). retryWaitMs() clamps on EVERY
    // path it can be reached from — a parsed numeric header, an unparsable
    // one (including an RFC-7231 HTTP-date, a legal Retry-After value this
    // client does not parse as a date), and the missing-header fallback all
    // funnel through the same clamp — so a mis-set header cannot make a
    // tool call hang, and a 0-or-missing header cannot spin straight back
    // into the limiter. A second 429 means something sleeping longer will
    // not fix.
    if (res.status === 429) {
      const waitMs = retryWaitMs(res.headers?.get?.('Retry-After'));
      await this.sleepImpl(waitMs);
      res = await this.fetchImpl(url, opts);
    }

    // This API never legitimately redirects. `redirect: 'manual'` above
    // means fetch hands back the 3xx itself instead of chasing it — refuse
    // it here instead of returning it as if it were a normal response.
    if (res.status >= 300 && res.status < 400) {
      const location = res.headers?.get?.('Location') ?? '(no Location header)';
      throw new Error(
        `${method} ${url} returned ${res.status} redirecting to ${location}. This API never ` +
        'redirects; the client does not follow one to avoid resending the bearer token.',
      );
    }

    if (res.status === 401) {
      throw new Error('Otack API rejected the token (401). Re-issue it at /profile/tokens and update OTACK_API_TOKEN.');
    }

    const text = await res.text();
    if (text === '') return { status: res.status, json: null };
    try {
      return { status: res.status, json: JSON.parse(text) };
    } catch {
      // A 200 with a non-JSON body (WAF interstitial, a PHP fatal rendered
      // as an HTML page, a captive portal, ...) must not read as an empty
      // success — that turns "the request never reached the app" into "no
      // new comments" for a caller that only checks status.
      const snippet = text.slice(0, 200);
      throw new Error(`${method} ${url} returned ${res.status} with a body that isn't valid JSON: ${snippet}`);
    }
  }
}

/** Wait time for a 429 retry, clamped to the rate-limit window (1..65s). */
function retryWaitMs(retryAfterHeader) {
  const n = Number(retryAfterHeader);
  const seconds = Number.isFinite(n) && n > 0 ? n : 1;
  return Math.min(65, Math.max(1, seconds)) * 1000;
}

/**
 * Turn an API response into what a tool should hand back to the model.
 * A failed call is a readable message, never a thrown stack.
 */
export function toolResult(res, okStatuses = [200]) {
  if (okStatuses.includes(res.status)) {
    return { content: [{ type: 'text', text: JSON.stringify(res.json, null, 2) }] };
  }
  const code = res.json?.error ?? 'unknown_error';
  const msg = res.json?.message ?? '';
  const fields = res.json?.fields ? ` fields=${JSON.stringify(res.json.fields)}` : '';
  return {
    isError: true,
    content: [{ type: 'text', text: `Otack API error ${res.status} (${code}) ${msg}${fields}`.trim() }],
  };
}

/**
 * Wrap a tool handler so a thrown error (bad input, a bad token, a network
 * failure) comes back as a normal tool result the model can read, instead of
 * an unhandled rejection that kills the bridge process. Applied to every
 * registered tool in this plan, without exception — see the Global
 * Constraints note on why "most tools" is not the same guarantee as "all
 * of them". Only `e.message` crosses this boundary, never `e.stack`: no
 * thrown message in this client interpolates `this.token`, so neither can
 * leak it — kept explicit here so a future error path is written the same
 * way rather than by accident.
 */
export function guard(fn) {
  return async (args) => {
    try { return await fn(args); }
    catch (e) { return { isError: true, content: [{ type: 'text', text: e.message }] }; }
  };
}

/**
 * Resolve and validate the caller's own user id from `GET /me`. Thrown
 * rather than degraded: a missing, zero, or non-integer id would make
 * `commentsSinceSelf`'s watermark never match anything, so an entire
 * comment thread would read as new on every tick — indistinguishable from
 * "the agent has never commented" and effectively an unsupervised reply
 * loop. Mirrors the boundary check `Triage::newComments()` makes on
 * `$selfUserId`, moved here — the one place `/me` is actually fetched —
 * rather than left to every caller of `commentsSinceSelf` to re-derive; see
 * `registerReadTools` (Task 3) and `registerCommentTools` (Task 5), both of
 * which call this instead of reading `r.json?.id` themselves.
 */
export async function resolveSelfId(client) {
  const r = await client.get('/me');
  const id = r.json?.id;
  if (!Number.isInteger(id) || id <= 0) {
    throw new Error(
      `GET /me returned an invalid user id (${JSON.stringify(id)}) — refusing to use it as the comment watermark`,
    );
  }
  return id;
}

/**
 * Follow `next_cursor` (docs/API.md §4.6) until it is null/undefined or
 * `maxPages` is hit. Every list endpoint this bridge reads shares that
 * cursor shape (`{items, next_cursor}`, `after` query param). Bounded so a
 * pathological thread cannot turn one tool call into an unbounded number of
 * requests against the 60/min budget.
 *
 * Mirrors `Client::getAll()` (`agent/lib/Client.php`) exactly, including
 * where it throws rather than degrades:
 *  - a non-200 page throws naming the path and status, instead of
 *    returning a partial `items` a caller could mistake for "task not
 *    found" reading as "no new comments";
 *  - `next_cursor` failing to advance between two pages throws, instead of
 *    looping on duplicate rows while burning request budget;
 *  - hitting `maxPages` with a cursor still pending throws, instead of
 *    returning a result indistinguishable from a genuinely complete list —
 *    a silent partial prefix breaks the comment watermark, which then
 *    replays already-answered comments as new.
 * Because every failure path throws, a successful return is always
 * `{status: 200, items}` — `status` is kept in the return shape only for
 * continuity with earlier callers, not because any other value can reach
 * them now.
 */
export async function paginateAll(client, path, query = {}, maxPages = 10) {
  let items = [];
  let after;
  for (let page = 0; page < maxPages; page++) {
    const r = await client.get(path, { ...query, limit: query.limit ?? 100, after });
    if (r.status !== 200) {
      throw new Error(`GET ${path} returned ${r.status}`);
    }
    items = items.concat(r.json?.items ?? []);
    const next = r.json?.next_cursor ?? undefined;
    if (next === undefined || next === null) { after = undefined; break; }
    if (next === after) {
      throw new Error(
        `GET ${path}: next_cursor '${next}' did not advance past the previous page — the server appears to be looping`,
      );
    }
    after = next;
  }
  if (after !== undefined) {
    throw new Error(
      `GET ${path}: reached maxPages=${maxPages} with next_cursor '${after}' still pending — the result is truncated, not complete`,
    );
  }
  return { status: 200, items };
}
```

- [ ] **Step 5: Реализовать сервер с одним инструментом**

`mcp/src/server.js`:

```javascript
#!/usr/bin/env node
/**
 * Otack Manager MCP bridge — stdio transport.
 *
 * Deliberately a curated dozen tools rather than one per REST route: the
 * point is to encode the workflow (see docs/MCP.md §3), not to re-publish
 * the API surface.
 */
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { OtackClient, toolResult, guard } from './client.js';

const client = new OtackClient({
  baseUrl: process.env.OTACK_API_URL,
  token: process.env.OTACK_API_TOKEN,
});

const server = new McpServer({ name: 'otack-manager', version: '0.1.0' });

server.tool(
  'whoami',
  'Verify the configured token and show which Otack Manager user this bridge acts as. Cheap sanity check to run before trusting any other tool.',
  {},
  guard(async () => toolResult(await client.get('/me'))),
);

// Tasks 2-5 each add code here, ABOVE the transport/connect lines below:
//   const projects = loadLocalProjects(...)
//   registerReadTools(server, client, projects)
//   registerWriteTools(server, client, projects)
//   registerCommentTools(server, client, projects)
// This file is one sequential module: code appended AFTER `connect()` would
// still run, but only once the server has already started accepting
// requests — a client that lists tools in that window sees an incomplete
// set. Order matters here even though nothing enforces it.

const transport = new StdioServerTransport();
await server.connect(transport);
```

**Перед реализацией сверить, какой мажор `zod` в момент исполнения требует установленная `@modelcontextprotocol/sdk@1.30.0`** — `npm view @modelcontextprotocol/sdk@1.30.0 dependencies` (на момент написания этого плана: `"zod": "^3.25 || ^4.0"`). Схемы всех инструментов ниже уже записаны как **Zod raw shape** (`{ field: z.string().optional() }`), а не как простые объекты: `McpServer.tool()` прогоняет схему через `zod-to-json-schema`, и объект без `_def`/`parse` ломает регистрацию всех инструментов разом при старте. Пин в `mcp/package.json` — `"zod": "3.25.76"`. **Если на момент исполнения этот диапазон сузился и требует другой мажор — поправить пин в `mcp/package.json` до Task 3**, первой задачи, где схема реально используется в тесте. Синтаксис (`z.string()`, `.optional()`, `.nullable()`, `z.enum()`) стабилен между 3.x и 4.x, так что в норме правка ограничится номером версии.

- [ ] **Step 6: Установить и прогнать**

Run: `chmod +x mcp/src/server.js && cd mcp && npm install && node --test`
Expected: PASS, двадцать три теста клиента зелёные (исходные пятнадцать плюс восемь на находки закалки: https/loopback-схема, отказ от редиректа, не-JSON тело на 200, обрыв пагинации при незастрахованном курсоре, `resolveSelfId`, отсутствие токена в тексте ошибки — и два из исходных пятнадцати теперь проверяют `throw`, а не частичный `return`, без изменения счёта).

- [ ] **Step 7: Живая проверка транспорта**

Run: `cd mcp && OTACK_API_URL=https://otack-manager.neoweblab.eu/api/v1 OTACK_API_TOKEN=<токен> npx @modelcontextprotocol/inspector node src/server.js`
Expected: инспектор показывает инструмент `whoami`; вызов возвращает JSON с пользователем бота.

- [ ] **Step 8: Коммит**

```bash
git add mcp/package.json mcp/package-lock.json mcp/src/client.js mcp/src/server.js mcp/test/client.test.js
git commit -m "feat(mcp): stdio bridge scaffold with REST client and whoami"
```

---

### Task 2: Машинный список разрешённых проектов и водораздел комментариев

Дыры, которой в первой редакции плана не было. В CLI гейт «какие проекты этой машине вообще видны» — **код** (`~/.claude/otack-agent/projects.json`), который нельзя обойти. Если перенести мост на инструменты и не перенести гейт, он съедет в прозу скилла, а прозаический запрет слабее программного.

Сервер запускается локально, поэтому читать тот же файл он может ровно так же, как CLI. Формат файла общий — один и тот же файл обслуживает обе реализации.

Второй модуль этой задачи, `watermark.js`, здесь по той же причине, что и первый — не в задаче, которой он логически ближе всего (комментарии, Task 5), а в самой ранней задаче, где он нужен хоть кому-то. `commentsSinceSelf` — чистая функция водораздела по последнему собственному комментарию (спека §4.1), и она нужна и `next_task` (Task 3), и `new_comments` (Task 5). Task 3 исполняется раньше Task 5; если бы `commentsSinceSelf` осталась жить в `tools/comments.js` (как в первой редакции плана), `read.js` (Task 3) ссылался бы на файл из более поздней задачи — не циклический импорт (Task 5 не ссылается на Task 3 в ответ), а **опережающая ссылка вперёд по номеру задачи**: при исполнении по порядку `node --test` в Task 3 падал бы с `ERR_MODULE_NOT_FOUND`, и `server.js` не поднимался бы вовсе. Здесь оба потребителя импортируют её одинаково — назад по номеру задачи.

**Files:**
- Create: `mcp/src/local.js`
- Create: `mcp/src/watermark.js`
- Test: `mcp/test/local.test.js`
- Test: `mcp/test/watermark.test.js`

**Interfaces:**
- Consumes: ничего.
- Produces: `loadLocalProjects(home)` → `Map<string, {path, write, test_cmd, in_progress_column, review_column}>`, бросающий (не молчащий пустым списком) на любой неоднозначной конфигурации — небулево `write`, доступный на запись каталог или файл, некорректная обёртка `projects`, отсутствующий/неканонический id, неабсолютный или несуществующий `path`, неверный тип `in_progress_column`/`review_column`; `assertProjectAllowed(projects, id)` — бросает с внятным текстом, если проекта нет; `verifyRepoMatches(localPath, repoUrl, execImpl)` → `{ok, actual}`; `commentsSinceSelf(comments, selfId)` из `mcp/src/watermark.js` — бросает на неположительный `selfId`; используется в Task 3 и Task 5.

- [ ] **Step 1: Написать падающие тесты**

`mcp/test/local.test.js`:

```javascript
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, chmodSync, realpathSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { loadLocalProjects, assertProjectAllowed, verifyRepoMatches } from '../src/local.js';

// mode: 0o700 is explicit, not incidental: loadLocalProjects now checks
// this directory isn't group/world-writable (M1), and Node's mkdirSync
// mode is still subject to the process umask for any bits it sets — 0o700
// has none in the group/other position, so it's the one mode value that
// stays correct regardless of the ambient umask a test happens to run
// under. Mirrors the PHP fixture's `mkdir($dir, 0700, true)`.
function home(projects) {
  const h = mkdtempSync(join(tmpdir(), 'otack-mcp-'));
  mkdirSync(join(h, '.claude/otack-agent'), { recursive: true, mode: 0o700 });
  if (projects !== null) {
    writeFileSync(join(h, '.claude/otack-agent/projects.json'), JSON.stringify({ projects }));
  }
  return h;
}

/**
 * A real, existing, realpath-resolved directory to use as a project's
 * "path". Not a bare string like '/tmp/p42': the implementation below
 * validates "path" with realpath() + isDirectory (M4), so a fixture that
 * never creates the directory would fail every happy-path test against the
 * hardened loader. Resolved up front (not just created) so an equality
 * assertion survives macOS resolving /tmp to /private/tmp the same way
 * loadLocalProjects's own realpath call does — the exact symlink confound
 * noted in the CLI's own SDD ledger while pinning this same behaviour.
 */
function projectDir() {
  return realpathSync(mkdtempSync(join(tmpdir(), 'otack-mcp-proj-')));
}

test('loadLocalProjects reads path, write and test_cmd', () => {
  const dir = projectDir();
  const p = loadLocalProjects(home({ 42: { path: dir, write: true, test_cmd: 'make test' } }));
  assert.equal(p.get('42').path, dir);
  assert.equal(p.get('42').write, true);
  assert.equal(p.get('42').test_cmd, 'make test');
});

test('loadLocalProjects defaults write to false and test_cmd to null', () => {
  const p = loadLocalProjects(home({ 51: { path: projectDir() } }));
  assert.equal(p.get('51').write, false);
  assert.equal(p.get('51').test_cmd, null);
});

test('a missing projects.json means no project is allowed, not all of them', () => {
  const p = loadLocalProjects(home(null));
  assert.equal(p.size, 0);
});

test('a malformed projects.json throws, naming the file, rather than silently returning empty', () => {
  const h = home({ 42: { path: projectDir() } });
  writeFileSync(join(h, '.claude/otack-agent/projects.json'), '{not json');
  assert.throws(() => loadLocalProjects(h), /projects\.json/);
});

test('assertProjectAllowed names the file so the operator knows where to look', () => {
  const p = loadLocalProjects(home({ 42: { path: projectDir() } }));
  assert.throws(() => assertProjectAllowed(p, 99), /projects\.json/);
});

test('assertProjectAllowed returns the entry for a listed project', () => {
  const dir = projectDir();
  const p = loadLocalProjects(home({ 42: { path: dir, write: true } }));
  assert.equal(assertProjectAllowed(p, 42).path, dir);
});

test('verifyRepoMatches accepts an exact remote match', () => {
  const r = verifyRepoMatches('/tmp/p', 'git@github.com:o/r.git', () => 'git@github.com:o/r.git\n');
  assert.equal(r.ok, true);
});

test('verifyRepoMatches treats ssh and https spellings of one repo as the same', () => {
  const r = verifyRepoMatches('/tmp/p', 'git@github.com:o/r.git', () => 'https://github.com/o/r\n');
  assert.equal(r.ok, true);
});

test('verifyRepoMatches rejects a different repository and reports what it found', () => {
  const r = verifyRepoMatches('/tmp/p', 'git@github.com:o/r.git', () => 'git@github.com:evil/other.git\n');
  assert.equal(r.ok, false);
  assert.match(r.actual, /evil/);
});

test('verifyRepoMatches fails closed when git cannot be run', () => {
  const r = verifyRepoMatches('/tmp/p', 'git@github.com:o/r.git', () => { throw new Error('not a repo'); });
  assert.equal(r.ok, false);
});

// --- Findings mirrored from agent/lib/Config.php's hardening (the shipped
// PHP is the source of truth — .dev-notes/superpowers/plans/2026-08-31-
// agent-bridge-client.md Task 1, verified against tests/unit/
// test_agent_config.php). Each one is a defect adversarial review found
// real in the PHP version; loadLocalProjects reads the same file by the
// same rules and must fail the same way, not just conceptually but on the
// same inputs.

test('loadLocalProjects throws when "write" is a quoted string instead of a real boolean', () => {
  // C1 — "false" (a string) is the single most common hand-editing mistake
  // and must never truthy-coerce into an agent that may commit and push.
  const h = home({ 42: { path: projectDir(), write: 'false' } });
  assert.throws(() => loadLocalProjects(h), /"write"/);
});

test('loadLocalProjects throws when the containing directory is group- or world-writable', () => {
  // M1 — the directory naming projects.json is itself part of the trust
  // boundary: if it's writable by anyone else, the 0600-equivalent gate on
  // the file inside it can simply be replaced.
  const h = home({ 42: { path: projectDir() } });
  chmodSync(join(h, '.claude/otack-agent'), 0o777);
  assert.throws(() => loadLocalProjects(h), /group- or world-writable/);
});

test('loadLocalProjects throws when projects.json itself is group- or world-writable', () => {
  const h = home({ 42: { path: projectDir() } });
  chmodSync(join(h, '.claude/otack-agent/projects.json'), 0o666);
  assert.throws(() => loadLocalProjects(h), /group- or world-writable/);
});

test('loadLocalProjects throws when projects.json is a directory instead of a file', () => {
  // M2 — a directory sitting where the file should be must be an error,
  // never treated as "absent" (which would silently mean "nothing allowed").
  const h = mkdtempSync(join(tmpdir(), 'otack-mcp-'));
  mkdirSync(join(h, '.claude/otack-agent/projects.json'), { recursive: true, mode: 0o700 });
  assert.throws(() => loadLocalProjects(h), /not a regular file/);
});

test('loadLocalProjects throws when projects.json is a top-level array instead of an object', () => {
  const h = mkdtempSync(join(tmpdir(), 'otack-mcp-'));
  mkdirSync(join(h, '.claude/otack-agent'), { recursive: true, mode: 0o700 });
  writeFileSync(join(h, '.claude/otack-agent/projects.json'), '[1,2,3]');
  assert.throws(() => loadLocalProjects(h), /top-level "projects" object/);
});

test('loadLocalProjects throws when the "projects" wrapper key is missing entirely', () => {
  const h = mkdtempSync(join(tmpdir(), 'otack-mcp-'));
  mkdirSync(join(h, '.claude/otack-agent'), { recursive: true, mode: 0o700 });
  writeFileSync(join(h, '.claude/otack-agent/projects.json'), '{"42":{"path":"/tmp"}}');
  assert.throws(() => loadLocalProjects(h), /top-level "projects" object/);
});

test('loadLocalProjects throws when an entry has no "path" (e.g. a typo like "paht")', () => {
  // M3 — a malformed entry must throw, never be dropped silently the way
  // the pre-hardening version's `if (!p || !p.path) continue;` did.
  const h = home({ 42: { write: true } });
  assert.throws(() => loadLocalProjects(h), /missing a non-empty "path"/);
});

test('loadLocalProjects throws when a project id is not a canonical integer ("042")', () => {
  // m3 — JSON.parse leaves "042" as the string "042", not 42; a lookup by
  // the number 42 would silently never find it.
  const h = home({ '042': { path: projectDir() } });
  assert.throws(() => loadLocalProjects(h), /canonical integer/);
});

test('loadLocalProjects throws when "path" is not absolute, and hints about "~"', () => {
  // M4 — this runtime never expands "~"; a relative or tilde path is
  // meaningless against a containment check and must not be silently used.
  const h = home({ 42: { path: '~/projects/p42' } });
  assert.throws(() => loadLocalProjects(h), /never expands "~"/);
});

test('loadLocalProjects throws when "path" does not exist on disk', () => {
  const h = home({ 42: { path: '/tmp/otack-mcp-definitely-does-not-exist' } });
  assert.throws(() => loadLocalProjects(h), /does not exist or is not a directory/);
});

// --- in_progress_column / review_column: the post-hoc column-resolution
// fix (.superpowers/sdd/2026-08-31-agent-bridge-client/progress.md,
// "Post-hoc fix: in-progress/review column resolution"). The server flags
// only is_done/is_backlog on a column — nothing marks "in progress" or
// "review" — and column names are free text in whatever language the
// board's owner used, so this cannot be inferred from the server; the
// operator names it here, once, per project.

test('loadLocalProjects reads in_progress_column and review_column as an integer id or an exact name', () => {
  const p = loadLocalProjects(home({
    42: { path: projectDir(), in_progress_column: 5, review_column: 'На проверке' },
  }));
  assert.equal(p.get('42').in_progress_column, 5);
  assert.equal(p.get('42').review_column, 'На проверке');
});

test('loadLocalProjects defaults in_progress_column and review_column to null when absent', () => {
  const p = loadLocalProjects(home({ 42: { path: projectDir() } }));
  assert.equal(p.get('42').in_progress_column, null);
  assert.equal(p.get('42').review_column, null);
});

test('loadLocalProjects throws when in_progress_column is neither an integer nor a string', () => {
  // Same fail-closed shape as the "write" boolean check (C1): a float,
  // bool, array or object must throw naming the project and the key, never
  // silently stringify or truncate into something that looks valid.
  const h = home({ 42: { path: projectDir(), in_progress_column: 3.5 } });
  assert.throws(() => loadLocalProjects(h), /"in_progress_column"/);
});
```

`mcp/test/watermark.test.js`:

```javascript
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { commentsSinceSelf } from '../src/watermark.js';

const c = (id, user_id) => ({ id, user_id });

test('returns everything after the last comment written by self', () => {
  // c(5, 5) — NOT c(5, 9) — is deliberate: comment 5 must be written by
  // someone other than self (id 9) so it survives the watermark filter.
  // The CLI's own SDD ledger (.superpowers/sdd/2026-08-31-agent-bridge-
  // client/progress.md, "Долги, которые нельзя потерять") logged this
  // exact off-by-author bug in this plan's original test data — with
  // c(5, 9), comment 5 IS self's own last comment, the watermark becomes 5,
  // and the correct result is an empty array, not [5]. Confirmed by
  // actually running this suite: c(5, 9) makes this test fail against a
  // correct implementation.
  const out = commentsSinceSelf([c(1, 5), c(2, 9), c(3, 5), c(4, 9), c(5, 5)], 9);
  assert.deepEqual(out.map(x => x.id), [5]);
});

test('returns the whole thread when self has never commented', () => {
  const out = commentsSinceSelf([c(1, 5), c(2, 7)], 9);
  assert.deepEqual(out.map(x => x.id), [1, 2]);
});

test('returns nothing when self commented last', () => {
  assert.deepEqual(commentsSinceSelf([c(1, 5), c(2, 9)], 9), []);
});

test('is not confused by ids arriving out of order', () => {
  const out = commentsSinceSelf([c(5, 9), c(2, 5), c(7, 5)], 9);
  assert.deepEqual(out.map(x => x.id), [7]);
});

test('returns results in ascending id order', () => {
  const out = commentsSinceSelf([c(9, 3), c(7, 3), c(8, 3)], 1);
  assert.deepEqual(out.map(x => x.id), [7, 8, 9]);
});

test('handles an empty thread', () => {
  assert.deepEqual(commentsSinceSelf([], 9), []);
});

// selfId must be a positive integer, mirroring the boundary check
// Triage::newComments() makes on $selfUserId. A zero or negative id must
// not silently produce a watermark of 0 that then matches nothing — that
// reads as "the agent has never commented" even when it plainly has, and
// replays the whole thread as new on every tick: an unsupervised reply loop
// that looks, from outside, exactly like a healthy agent that has simply
// never spoken yet.

test('throws when selfId is zero rather than treating it as "has never commented"', () => {
  assert.throws(() => commentsSinceSelf([c(1, 5)], 0), /positive integer/);
});

test('throws when selfId is negative', () => {
  assert.throws(() => commentsSinceSelf([c(1, 5)], -3), /positive integer/);
});
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `cd mcp && node --test`
Expected: FAIL — `src/local.js` и `src/watermark.js` не существуют.

- [ ] **Step 3: Реализовать**

`mcp/src/local.js`:

```javascript
import { readFileSync, statSync, realpathSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';

/**
 * The machine-local allow-list, shared byte-for-byte with the PHP CLI
 * (`agent/lib/Config.php`). The server stores *what* a project is; this file
 * stores *where it lives here and whether the agent may write to it*.
 *
 * The split is a security control, not bookkeeping: if the local path came
 * from the API, anyone who can edit a project in the web UI could aim an
 * automated worker at an arbitrary directory.
 *
 * This governs an autonomous bot that reads a directory allow-list here and
 * then commits and pushes on its own. Every failure below is loud and
 * fail-closed: silently narrowing to "nothing allowed" when the real cause
 * is a broken config reads to the operator as "no work to do", not as
 * "config is broken" — so anything ambiguous throws, naming the file and,
 * where it applies, the project and key at fault, instead of degrading
 * quietly. Ported from `Config::load()`'s hardened shape, not the original
 * draft — see the review findings folded into Task 2's test file.
 */
export function loadLocalProjects(home) {
  const dir = join(home, '.claude/otack-agent');
  const path = join(dir, 'projects.json');

  // M1: the directory that holds projects.json names the directories an
  // autonomous writer may touch. If it is group- or world-writable, the
  // gate on the file inside it can simply be replaced by anyone else on
  // the box — checked even before the file itself, and even when the file
  // turns out not to exist.
  assertNotGroupOrWorldWritable(dir, `${dir} directory`);

  let stat;
  try {
    stat = statSync(path);
  } catch (e) {
    // Absent => nothing is allowed. Anything else (permission denied, ...)
    // is a misconfiguration and must not be indistinguishable from "no
    // work" — thrown below, naming the file.
    if (e.code === 'ENOENT') return new Map();
    throw new Error(`cannot read ${path}: ${e.message}`);
  }
  // M2: a directory (or anything else) sitting where projects.json is
  // expected must be an error, not treated as absent.
  if (!stat.isFile()) {
    throw new Error(`${path} exists but is not a regular file`);
  }
  assertNotGroupOrWorldWritable(path, path);

  let text;
  try {
    text = readFileSync(path, 'utf8');
  } catch (e) {
    throw new Error(`cannot read ${path}: ${e.message}`);
  }

  let raw;
  try {
    raw = JSON.parse(text);
  } catch (e) {
    throw new Error(`${path} is not valid JSON: ${e.message}`);
  }

  // M2: a missing/typo'd "projects" key, a top-level JSON array, or
  // anything that isn't a plain object wrapper must be a loud error — not
  // an empty allow-list indistinguishable from "no projects.json at all".
  if (
    raw === null || typeof raw !== 'object' || Array.isArray(raw)
    || raw.projects === undefined || raw.projects === null
    || typeof raw.projects !== 'object' || Array.isArray(raw.projects)
  ) {
    throw new Error(`${path} must have a top-level "projects" object. See agent/projects.example.json.`);
  }

  const out = new Map();
  for (const [id, p] of Object.entries(raw.projects)) {
    // m3: JSON.parse leaves non-canonical numeric keys (e.g. "042") as
    // strings while a lookup by the number 42 uses "42". Reject anything
    // that isn't a canonical integer key so the mismatch can't silently
    // make a project unreachable.
    if (String(parseInt(id, 10)) !== id) {
      throw new Error(`Project id "${id}" in ${path} is not a canonical integer key`);
    }

    // M3: a malformed entry must throw, never be dropped silently.
    if (p === null || typeof p !== 'object' || Array.isArray(p)) {
      throw new Error(`Project "${id}" in ${path} must be an object with a "path" key`);
    }
    if (!('path' in p) || p.path === '') {
      throw new Error(`Project "${id}" in ${path} is missing a non-empty "path"`);
    }

    // C1: "write" must be a real JSON boolean. A quoted string like
    // "false" is the most common hand-editing mistake and must never
    // silently coerce to true.
    let write = false;
    if ('write' in p) {
      if (typeof p.write !== 'boolean') {
        throw new Error(
          `Project "${id}" in ${path} has a non-boolean "write" value (${JSON.stringify(p.write)}); ` +
          'use a real JSON true/false, not a quoted string',
        );
      }
      write = p.write;
    }

    // M4: nothing upstream guarantees this path is a string, is absolute,
    // exists, or is a directory — yet a containment check downstream is
    // meaningless against a relative or "~"-prefixed path.
    if (typeof p.path !== 'string') {
      throw new Error(`Project "${id}" in ${path} has a non-string "path"`);
    }
    if (p.path[0] !== '/') {
      const hint = p.path.startsWith('~') ? ' (this runtime never expands "~" — use an absolute path)' : '';
      throw new Error(`Project "${id}" in ${path} has a non-absolute "path": "${p.path}"${hint}`);
    }
    let real;
    try {
      real = realpathSync(p.path);
    } catch {
      throw new Error(`Project "${id}" in ${path}: path "${p.path}" does not exist or is not a directory`);
    }
    if (!statSync(real).isDirectory()) {
      throw new Error(`Project "${id}" in ${path}: path "${p.path}" does not exist or is not a directory`);
    }

    out.set(id, {
      path: real,
      write,
      test_cmd: p.test_cmd ? String(p.test_cmd) : null,
      // Post-hoc fix (see .superpowers/sdd/2026-08-31-agent-bridge-client/
      // progress.md, "in-progress/review column resolution"): the server
      // flags only is_done/is_backlog, never "in progress" or "review",
      // and column names are free text in any language, so the phase
      // machine cannot infer these — the operator names them here.
      in_progress_column: readColumnRef(p, 'in_progress_column', id, path),
      review_column: readColumnRef(p, 'review_column', id, path),
    });
  }
  return out;
}

/**
 * Reads an optional in_progress_column / review_column value out of one
 * project entry. Absent -> null. Present -> must be a JSON integer column
 * id or a non-empty JSON string with the exact column name; any other type
 * (float, bool, array, object, empty string) throws, naming both the
 * project id and the key — same fail-closed shape as the "write" boolean
 * check above (C1). Never silently stringified or truncated into something
 * that looks valid.
 */
function readColumnRef(p, key, id, path) {
  if (!(key in p) || p[key] === null || p[key] === undefined) return null;
  const v = p[key];
  if (Number.isInteger(v)) return v;
  if (typeof v === 'string' && v !== '') return v;
  throw new Error(
    `Project "${id}" in ${path} has an invalid "${key}" value (${JSON.stringify(v)}); ` +
    'use either an integer column id or a non-empty string with the exact column name',
  );
}

export function assertProjectAllowed(projects, id) {
  const entry = projects.get(String(id));
  if (!entry) {
    throw new Error(
      `project ${id} is not listed in ~/.claude/otack-agent/projects.json — refusing to act on it`,
    );
  }
  return entry;
}

/** Normalise ssh and https spellings so one repository compares equal to itself. */
function canonicalRemote(url) {
  return String(url)
    .trim()
    .replace(/^git@([^:]+):/, 'https://$1/')
    .replace(/\.git$/, '')
    .replace(/\/+$/, '')
    .toLowerCase();
}

/**
 * The interlock: the checkout on disk must be the repository the server says
 * this project is. Fails closed — if git cannot answer, the answer is no.
 */
export function verifyRepoMatches(localPath, repoUrl, execImpl = null) {
  const run = execImpl ?? ((cwd) =>
    execFileSync('git', ['remote', 'get-url', 'origin'], { cwd, encoding: 'utf8' }));
  try {
    const actual = String(run(localPath)).trim();
    return { ok: canonicalRemote(actual) === canonicalRemote(repoUrl), actual };
  } catch (e) {
    return { ok: false, actual: `git failed: ${e.message}` };
  }
}

// M1 + m1: reject a path that is group- or world-writable. A stat failure
// is itself an error (fail closed), never treated as "no bits set".
function assertNotGroupOrWorldWritable(path, label) {
  let stat;
  try {
    stat = statSync(path);
  } catch (e) {
    throw new Error(`Cannot stat ${label}: ${e.message}`);
  }
  const mode = stat.mode & 0o777;
  if ((mode & 0o022) !== 0) {
    throw new Error(`${label} must not be group- or world-writable (mode ${mode.toString(8)})`);
  }
}
```

`mcp/src/watermark.js`:

```javascript
/**
 * Comments written after the caller's own most recent one (spec §4.1).
 *
 * The watermark is the caller's last comment id rather than a stored cursor:
 * an agent ends every phase with a comment, so it marks its own place, and
 * the mark survives a crash, a lost session, or a human editing the board.
 *
 * Its own module rather than living next to `add_comment`/`new_comments` in
 * `tools/comments.js`: both `tools/read.js` (`next_task`, Task 3) and
 * `tools/comments.js` (`new_comments`, Task 5) need it, and Task 3 runs
 * before Task 5 — see the note at the top of this task.
 */
export function commentsSinceSelf(comments = [], selfId) {
  // Mirrors the boundary check `Triage::newComments()` makes on
  // $selfUserId. `selfId` must be a positive integer: 0 (or a caller that
  // reshapes a missing self id to 0) is not "has never commented" — it
  // would produce a watermark of 0 that never matches anything, replaying
  // the entire thread as "new" on every tick. That failure mode is
  // indistinguishable, from the outside, from a healthy agent that has
  // simply never spoken yet, so it must fail loud here rather than degrade
  // into an unsupervised reply loop. `resolveSelfId()` (`client.js`,
  // Task 1) already validates this before it ever reaches here, but this
  // function does not trust callers to have done so — the same
  // defense-in-depth `Triage::newComments()` applies for itself.
  if (!Number.isInteger(Number(selfId)) || Number(selfId) <= 0) {
    throw new Error(`selfId must be a positive integer, got ${JSON.stringify(selfId)}`);
  }
  let watermark = 0;
  for (const c of comments) {
    if (Number(c.user_id) === Number(selfId)) watermark = Math.max(watermark, Number(c.id));
  }
  return comments
    .filter(c => Number(c.id) > watermark)
    .sort((a, b) => Number(a.id) - Number(b.id));
}
```

- [ ] **Step 4: Прогнать тесты**

Run: `cd mcp && node --test`
Expected: PASS, тридцать один новый тест зелёный: `local.test.js` — двадцать три (десять исходных allow-list/интерлок плюс тринадцать на находки закалки `Config::load` — небулев `write`, доступный на запись каталог, доступный на запись файл, каталог вместо файла, массив вместо объекта, отсутствующий ключ `projects`, отсутствующий `path`, неканонический id, неабсолютный `path`, несуществующий `path`, чтение `in_progress_column`/`review_column`, их дефолт `null`, невалидный тип одного из них); `watermark.test.js` — восемь (шесть исходных плюс два на `selfId <= 0`). Всего по проекту — двадцать три (Task 1) плюс тридцать один (Task 2) = пятьдесят четыре.

- [ ] **Step 5: Коммит**

```bash
git add mcp/src/local.js mcp/src/watermark.js mcp/test/local.test.js mcp/test/watermark.test.js
git commit -m "feat(mcp): machine-local project allow-list, repo interlock, and the comment watermark"
```

---

### Task 3: Инструменты обнаружения и чтения задач

Включает `next_task` — вторую дыру первой редакции. Без него у моста есть только плоский `list_my_tasks`, и приоритет фаз пришлось бы выводить модели заново на каждом тике. Это ровно то место, где она ошибётся незаметно: доделает новое вместо начатого, или пройдёт мимо ответа на свой же вопрос.

`next_task` не может быть простым фильтром по `GET /tasks` — у этого эндпоинта нет фильтра по статусу колонки (`TaskRepository::listAssignedAfterId`, `system/Repository/TaskRepository.php:117-149`), так что задачи, лежащие в Done или в Backlog, приходят наравне с активными и с `agent_state: null` были бы выбраны как «новые». А поле `from_form`, которое `pickNextTask` должен отсеивать, отдаёт только `GET /tasks/{id}` (`TasksHandler::serializeOne()`, `:473-500`) — в списочном ответе (`serializeListItem()`, `:451-470`) его нет вовсе, так что фильтр `!t.from_form` внутри `pickNextTask` против списочных данных не срабатывает никогда. Обе поправки — забота инструмента `next_task` (`computeNextTask` ниже), а не `pickNextTask`: он остаётся чистым портом `Triage::pick()` и **второй линией обороны**, а не единственной.

**Files:**
- Create: `mcp/src/tools/read.js`
- Modify: `mcp/src/server.js` (регистрация)
- Test: `mcp/test/read.test.js`

**Interfaces:**
- Consumes: `OtackClient`, `toolResult`, `guard`, `paginateAll`, `resolveSelfId` из Task 1; `loadLocalProjects`, `assertProjectAllowed`, `verifyRepoMatches` из Task 2; `commentsSinceSelf` из `mcp/src/watermark.js` (Task 2).
- Produces: `registerReadTools(server, client, projects)` — регистрирует `list_projects`, `list_my_tasks`, `next_task`, `get_task`, `get_project_context`. Экспортирует `buildListMyTasksQuery(args, selfId)`, `summariseColumns(columns)`, `excludedColumnIds(columns)`, `pickNextTask(tasks, newCommentCounts)` (сортирует внутри ступени по возрастанию id — контракт, а не случайность порядка сервера), `computeNextTask(client, projects, selfId)` (различает `idle` и `form_bound_exhausted` как отдельные причины отсутствия задачи).

**Порт, а не изобретение:** `pickNextTask` — построчный перенос `Triage::pick()` из `agent/lib/Triage.php`, вместе с его тестовыми случаями. Если поведение разошлось — расходится баг, а не «две реализации имеют право отличаться».

- [ ] **Step 1: Написать падающие тесты**

`mcp/test/read.test.js`:

```javascript
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  buildListMyTasksQuery, summariseColumns, excludedColumnIds, pickNextTask, computeNextTask,
} from '../src/tools/read.js';
import { OtackClient } from '../src/client.js';

test('list_my_tasks defaults the assignee to the caller', () => {
  assert.deepEqual(buildListMyTasksQuery({}, 7), { assignee_id: 7, limit: 50 });
});

test('list_my_tasks passes an explicit assignee through', () => {
  assert.deepEqual(buildListMyTasksQuery({ assignee_id: 3 }, 7), { assignee_id: 3, limit: 50 });
});

test('list_my_tasks forwards the phase filter', () => {
  assert.deepEqual(
    buildListMyTasksQuery({ agent_state: 'review' }, 7),
    { assignee_id: 7, agent_state: 'review', limit: 50 },
  );
});

test('list_my_tasks rejects a phase outside the closed set', () => {
  assert.throws(() => buildListMyTasksQuery({ agent_state: 'pondering' }, 7), /researching/);
});

test('summariseColumns marks which column means done and which is the backlog', () => {
  const out = summariseColumns([
    { id: 1, name: 'Backlog', position: 0, is_done: false, is_backlog: true },
    { id: 2, name: 'To Do', position: 1, is_done: false, is_backlog: false },
    { id: 3, name: 'Done', position: 2, is_done: true, is_backlog: false },
  ]);
  assert.equal(out.done_column_id, 3);
  assert.equal(out.backlog_column_id, 1);
  assert.deepEqual(out.in_progress_column_ids, [2]);
});

test('summariseColumns copes with a project that has no done column', () => {
  const out = summariseColumns([{ id: 5, name: 'Only', position: 0, is_done: false, is_backlog: false }]);
  assert.equal(out.done_column_id, null);
  assert.equal(out.backlog_column_id, null);
  assert.deepEqual(out.in_progress_column_ids, [5]);
});

test('excludedColumnIds collects both the done and the backlog column', () => {
  const out = excludedColumnIds([
    { id: 1, is_done: false, is_backlog: true },
    { id: 2, is_done: false, is_backlog: false },
    { id: 3, is_done: true, is_backlog: false },
  ]);
  assert.deepEqual(out, [1, 3]);
});

test('excludedColumnIds is empty when a project has neither', () => {
  assert.deepEqual(excludedColumnIds([{ id: 5, is_done: false, is_backlog: false }]), []);
});

// pickNextTask — the same cases as tests/unit/test_agent_triage.php. If these
// two suites ever disagree, one implementation has a bug; they are not
// allowed to diverge.
const T = (id, agent_state, from_form = false) => ({ id, agent_state, from_form });

test('pickNextTask resumes an in-flight task before starting anything new', () => {
  const r = pickNextTask([T(1, null), T(2, 'implementing')], {});
  assert.equal(r.task.id, 2);
  assert.equal(r.reason, 'resume');
});

test('pickNextTask treats researching as in-flight too', () => {
  const r = pickNextTask([T(1, null), T(2, 'researching')], {});
  assert.equal(r.task.id, 2);
  assert.equal(r.reason, 'resume');
});

test('pickNextTask takes review feedback before starting new work', () => {
  const r = pickNextTask([T(1, null), T(3, 'review')], { 3: 2 });
  assert.equal(r.task.id, 3);
  assert.equal(r.reason, 'rework');
});

test('pickNextTask ignores a review task nobody has commented on', () => {
  const r = pickNextTask([T(1, null), T(3, 'review')], { 3: 0 });
  assert.equal(r.task.id, 1);
  assert.equal(r.reason, 'new');
});

test('pickNextTask takes an answered approval before starting new work', () => {
  const r = pickNextTask([T(1, null), T(4, 'awaiting_approval')], { 4: 1 });
  assert.equal(r.task.id, 4);
  assert.equal(r.reason, 'approved');
});

test('pickNextTask leaves an unanswered approval alone and moves on', () => {
  const r = pickNextTask([T(1, null), T(4, 'awaiting_approval')], { 4: 0 });
  assert.equal(r.task.id, 1);
  assert.equal(r.reason, 'new');
});

test('pickNextTask never takes a task created from a public form', () => {
  assert.equal(pickNextTask([T(8, null, true)], {}), null);
});

test('pickNextTask never takes a blocked task', () => {
  assert.equal(pickNextTask([T(9, 'blocked')], { 9: 3 }), null);
});

// `blocked` matches no rung's state list, so the exclusion above is
// structurally a no-op by itself — deleting it changes nothing, because
// nothing in the ladder would ever have picked it up anyway. The real
// guard against a blocked (or otherwise unrecognised) task being picked up
// as fresh work is the strict "state is exactly null" match on the `new`
// rung, pinned directly below. That test is what actually carries this
// one's intent.
test('pickNextTask never starts a task whose agent_state is neither null nor a known state', () => {
  assert.equal(pickNextTask([T(5, 'some_unknown_state')], {}), null);
});

test('pickNextTask returns null when there is nothing to do', () => {
  assert.equal(pickNextTask([], {}), null);
});

// The ladder pinned with BOTH candidates present in a single call — not
// just each rung against a plain `new` task. Testing resume vs. new alone
// cannot catch resume<->rework swapping: with one candidate per rung the
// suite stays green either way. These three feed two rungs into one call
// and assert which one wins.
test('pickNextTask ladder: resume beats rework when both are present', () => {
  const r = pickNextTask([T(2, 'implementing'), T(3, 'review')], { 3: 1 });
  assert.equal(r.task.id, 2);
  assert.equal(r.reason, 'resume');
});

test('pickNextTask ladder: resume beats approved when both are present', () => {
  const r = pickNextTask([T(2, 'implementing'), T(4, 'awaiting_approval')], { 4: 1 });
  assert.equal(r.task.id, 2);
  assert.equal(r.reason, 'resume');
});

test('pickNextTask ladder: rework beats approved when both are present', () => {
  const r = pickNextTask([T(3, 'review'), T(4, 'awaiting_approval')], { 3: 1, 4: 1 });
  assert.equal(r.task.id, 3);
  assert.equal(r.reason, 'rework');
});

// Within a rung, the lowest id wins — a contract, not an accident of
// whatever order the caller happened to hand tasks in. Fed in descending
// order so array order alone cannot explain the winner.
test('pickNextTask breaks a same-rung tie by lowest id, independent of input order', () => {
  const r = pickNextTask([T(9, 'implementing'), T(4, 'implementing')], {});
  assert.equal(r.task.id, 4);
  assert.equal(r.reason, 'resume');
});

// computeNextTask — the corrections GET /tasks cannot make on its own.
// Drives a fake OtackClient the same way Task 1's client tests do.
function fakeClient(routes) {
  const fetchImpl = async (url) => {
    for (const [matcher, respond] of routes) {
      if (matcher.test(url)) {
        return { status: 200, text: async () => JSON.stringify(respond()), headers: new Map() };
      }
    }
    return { status: 404, text: async () => '{}', headers: new Map() };
  };
  return new OtackClient({ baseUrl: 'https://x.example/api/v1', token: 't', fetchImpl });
}

test('computeNextTask excludes a task sitting in a done or backlog column', async () => {
  const projects = new Map([['42', { path: '/tmp/p', write: false, test_cmd: null }]]);
  const client = fakeClient([
    [/\/tasks\?/, () => ({ items: [
      { id: 1, project_id: 42, column_id: 900, agent_state: null }, // in the Done column
      { id: 2, project_id: 42, column_id: 901, agent_state: null }, // active
    ] })],
    [/\/projects\/42$/, () => ({ columns: [
      { id: 900, is_done: true, is_backlog: false },
      { id: 901, is_done: false, is_backlog: false },
    ] })],
    [/\/tasks\/2$/, () => ({ id: 2, project_id: 42, column_id: 901, agent_state: null, from_form: false })],
  ]);
  const r = await computeNextTask(client, projects, 7);
  assert.equal(r.task.id, 2);
  assert.equal(r.reason, 'new');
});

test('computeNextTask drops a form-created winner and re-picks the next eligible task', async () => {
  const projects = new Map([['42', { path: '/tmp/p', write: false, test_cmd: null }]]);
  const client = fakeClient([
    [/\/tasks\?/, () => ({ items: [
      { id: 1, project_id: 42, column_id: 901, agent_state: null },
      { id: 2, project_id: 42, column_id: 901, agent_state: null },
    ] })],
    [/\/projects\/42$/, () => ({ columns: [{ id: 901, is_done: false, is_backlog: false }] })],
    [/\/tasks\/1$/, () => ({ id: 1, project_id: 42, column_id: 901, agent_state: null, from_form: true })],
    [/\/tasks\/2$/, () => ({ id: 2, project_id: 42, column_id: 901, agent_state: null, from_form: false })],
  ]);
  const r = await computeNextTask(client, projects, 7);
  assert.equal(r.task.id, 2);
  assert.equal(r.reason, 'new');
});

test('computeNextTask gives up with a reason distinct from "idle" when everything is form-created', async () => {
  // Bounded re-pick round trip exhausted while a candidate was still on the
  // table every time — this is NOT the same situation as "nothing was
  // eligible at all" (that is `reason: 'idle'`, tested separately below).
  // Conflating the two would read "work exists but is buried" as "no work
  // to do", which is the opposite of what happened.
  //
  // Deliberately 11 candidates, one more than the round bound (10) —
  // confirmed by actually running this suite that a single form-created
  // candidate does NOT exercise this path: dropping it empties `mine`
  // after round 0, so `pickNextTask` returns null on round 1 and the
  // function reports plain `idle` long before the bound is ever reached.
  // Reaching `form_bound_exhausted` requires the bound itself to run out
  // while a candidate is still sitting in `mine` — which needs more
  // candidates than rounds.
  const N = 11;
  const projects = new Map([['42', { path: '/tmp/p', write: false, test_cmd: null }]]);
  const items = Array.from({ length: N }, (_, i) => (
    { id: i + 1, project_id: 42, column_id: 901, agent_state: null }
  ));
  const taskDetailRoutes = items.map(
    (t) => [new RegExp(`/tasks/${t.id}$`), () => ({ ...t, from_form: true })],
  );
  const client = fakeClient([
    [/\/tasks\?/, () => ({ items })],
    [/\/projects\/42$/, () => ({ columns: [{ id: 901, is_done: false, is_backlog: false }] })],
    ...taskDetailRoutes,
  ]);
  const r = await computeNextTask(client, projects, 7);
  assert.equal(r.task, null);
  assert.equal(r.reason, 'form_bound_exhausted');
  assert.notEqual(r.reason, 'idle');
});

test('computeNextTask reports plain "idle" when nothing was eligible at all', async () => {
  const projects = new Map([['42', { path: '/tmp/p', write: false, test_cmd: null }]]);
  const client = fakeClient([
    [/\/tasks\?/, () => ({ items: [] })],
  ]);
  const r = await computeNextTask(client, projects, 7);
  assert.equal(r.task, null);
  assert.equal(r.reason, 'idle');
});
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `cd mcp && node --test`
Expected: FAIL — `src/tools/read.js` не существует.

- [ ] **Step 3: Реализовать**

`mcp/src/tools/read.js`:

```javascript
import { z } from 'zod';
import { toolResult, guard, paginateAll, resolveSelfId } from '../client.js';
import { assertProjectAllowed, verifyRepoMatches } from '../local.js';
import { commentsSinceSelf } from '../watermark.js';

export const PHASES = ['researching', 'awaiting_approval', 'implementing', 'review', 'blocked'];

/**
 * The "what should I work on" query. Defaults the assignee to the caller so
 * the model never has to know its own user id, and validates the phase here
 * rather than letting a typo come back as an empty list — the API rejects an
 * unknown phase with 422, and a tool should not turn that into "no work".
 */
export function buildListMyTasksQuery(args, selfId) {
  if (args.agent_state !== undefined && !PHASES.includes(args.agent_state)) {
    throw new Error(`unknown agent_state "${args.agent_state}" — valid: ${PHASES.join('|')}`);
  }
  const q = { assignee_id: args.assignee_id ?? selfId, limit: args.limit ?? 50 };
  if (args.agent_state !== undefined) q.agent_state = args.agent_state;
  if (args.updated_after !== undefined) q.updated_after = args.updated_after;
  return q;
}

/**
 * Columns carry the semantics an agent needs to decide where a card goes.
 * Surfacing them as named ids saves the model from re-deriving the meaning
 * of is_done/is_backlog on every call.
 */
export function summariseColumns(columns = []) {
  const done = columns.find(c => c.is_done);
  const backlog = columns.find(c => c.is_backlog);
  return {
    columns,
    done_column_id: done ? done.id : null,
    backlog_column_id: backlog ? backlog.id : null,
    in_progress_column_ids: columns.filter(c => !c.is_done && !c.is_backlog).map(c => c.id),
  };
}

/**
 * Column ids a task must not sit in to be considered for "new" work: the
 * done column (already finished) and the backlog column (not yet triaged
 * onto the active board). `GET /tasks` (`TaskRepository::listAssignedAfterId`)
 * carries no column-status filter, so a task sitting in either comes back
 * indistinguishable from one on the active board — this has to be applied
 * client-side, per project, from that project's column list.
 */
export function excludedColumnIds(columns = []) {
  return columns.filter(c => c.is_done || c.is_backlog).map(c => c.id);
}

/**
 * Which task deserves this tick, and why. A line-by-line port of
 * `Triage::pick()` in the PHP CLI — same order, same reasons:
 *   resume   — already in flight; never leave one half-done
 *   rework   — review feedback arrived; a human is waiting
 *   approved — an approval question was answered; unblock it
 *   new      — nothing in flight, start something
 *
 * `awaiting_approval` with no new comments does NOT block the queue: it is
 * skipped, so one unanswered question cannot idle the agent for a day.
 * `blocked` tasks are never picked.
 *
 * The `from_form` filter below is a SECOND line of defence only. `GET
 * /tasks` — the endpoint `computeNextTask` reads from — never carries
 * `from_form`: `TasksHandler::serializeListItem()` omits the field; only
 * `serializeOne()` (the single-task `GET /tasks/{id}`) includes it. Against
 * real list data this filter is inert. The CALLER (`computeNextTask` below)
 * is responsible for re-verifying the winner with `GET /tasks/{id}` before
 * acting on it — do not trust this function alone to keep a form-created
 * task out.
 *
 * Within a rung, the lowest task id wins. This is a CONTRACT, not an
 * accident of whatever order the caller's list happens to be in — the only
 * reason a naive "take the first match" would look correct in casual
 * testing is that `TaskRepository::listAssignedAfterId()` happens to
 * `ORDER BY id ASC` today. Sorting explicitly here means a caller that
 * assembles `tasks` differently (a different endpoint, a merge of several
 * projects, a re-ordered fixture) can never flip the winner tick to tick on
 * identical data.
 */
export function pickNextTask(tasks, newCommentCounts = {}) {
  const eligible = tasks.filter(t => !t.from_form && t.agent_state !== 'blocked');
  const byState = (states) => eligible.filter(t => states.includes(t.agent_state ?? null));
  const withComments = (rows) => rows.filter(t => (newCommentCounts[t.id] ?? 0) > 0);
  const byId = (rows) => [...rows].sort((a, b) => a.id - b.id);

  const ladder = [
    ['resume', byState(['researching', 'implementing'])],
    ['rework', withComments(byState(['review']))],
    ['approved', withComments(byState(['awaiting_approval']))],
    ['new', byState([null])],
  ];
  for (const [reason, rows] of ladder) {
    if (rows.length > 0) return { task: byId(rows)[0], reason };
  }
  return null;
}

/**
 * The full `next_task` decision, including the two corrections `GET /tasks`
 * cannot make on its own (see the block comment above `pickNextTask`):
 *
 *  1. excludes tasks sitting in a done/backlog column, fetching each
 *     distinct project's columns once via `GET /projects/{id}` rather than
 *     once per task;
 *  2. re-verifies the winner with `GET /tasks/{id}` — the only endpoint that
 *     actually carries `from_form` — and, if it turns out to be
 *     form-created, drops it and re-picks from what remains. Bounded to ten
 *     rounds so a project seeded entirely with form tasks cannot spin
 *     forever.
 *
 * The two ways this can end with no task are NOT the same and get DISTINCT
 * reason strings: `idle` means `pickNextTask` had nothing eligible at all —
 * genuinely no work. `form_bound_exhausted` means the ten-round bound ran
 * out while a candidate was still on the table every round — work exists
 * but this tool gave up verifying it. Collapsing both into `idle` would
 * read "work exists but is buried" as "no work to do", which is the
 * opposite of what happened and the one case where staying silent is worse
 * than a noisy tool result.
 *
 * `paginateAll` (Task 1) now throws rather than returning a non-200 status,
 * so the comment-count loop below has nothing to check a status against —
 * a failed comments fetch propagates out of this function and is reported
 * by the caller's `guard()`, the same as any other failure here.
 *
 * Exported so tests can drive it against a fake `OtackClient` without
 * standing up the MCP transport.
 */
export async function computeNextTask(client, projects, selfId) {
  const r = await client.get('/tasks', { assignee_id: selfId, limit: 100 });
  if (r.status !== 200) return { error: r };

  let mine = (r.json?.items ?? []).filter(t => projects.has(String(t.project_id)));

  const excludedByProject = new Map();
  for (const projectId of new Set(mine.map(t => String(t.project_id)))) {
    const p = await client.get(`/projects/${projectId}`);
    excludedByProject.set(projectId, p.status === 200 ? excludedColumnIds(p.json?.columns ?? []) : []);
  }
  mine = mine.filter(t => !(excludedByProject.get(String(t.project_id)) ?? []).includes(t.column_id));

  // Comment counts only where they decide the outcome — one request per
  // task that needs one, not one per task overall.
  const counts = {};
  for (const t of mine) {
    if (t.agent_state === 'review' || t.agent_state === 'awaiting_approval') {
      const c = await paginateAll(client, `/tasks/${t.id}/comments`);
      counts[t.id] = commentsSinceSelf(c.items, selfId).length;
    }
  }

  for (let round = 0; round < 10; round++) {
    const picked = pickNextTask(mine, counts);
    if (picked === null) return { task: null, reason: 'idle' };

    const detail = await client.get(`/tasks/${picked.task.id}`);
    if (detail.status !== 200) return { error: detail };
    if (!detail.json?.from_form) {
      return { task: detail.json, reason: picked.reason };
    }
    // Confirmed form-created by the endpoint that actually carries the
    // field — drop it and try again with what remains.
    mine = mine.filter(t => t.id !== picked.task.id);
  }
  return { task: null, reason: 'form_bound_exhausted' };
}

export function registerReadTools(server, client, projects) {
  // Validated once, at the boundary, via resolveSelfId() (Task 1) — not
  // `r.json?.id ?? null` read out here. A shape change in the /me response
  // (missing id, id as a string, a zero id) must throw immediately instead
  // of degrading through three different call sites (list_my_tasks's
  // default assignee, next_task's watermark, get_task's — none of them, but
  // the pattern generalises) in three different ways.
  let selfId = null;
  const self = async () => {
    if (selfId === null) selfId = await resolveSelfId(client);
    return selfId;
  };

  server.tool(
    'list_projects',
    'List the projects this token can see, without the full agent_instructions text (fetch get_project_context for a single project to read it). Use it to discover project ids.',
    {},
    guard(async () => {
      const r = await client.get('/projects', { limit: 100 });
      if (r.status !== 200) return toolResult(r);
      // agent_instructions is per-project prose that can run to paragraphs
      // (ProjectsHandler::serializeMany(), system/Api/V1/Handlers/ProjectsHandler.php:201);
      // at limit:100 shipping it here means every project's full text in
      // one response. Stripped; get_project_context returns it for one
      // project at a time.
      const items = (r.json?.items ?? []).map(({ agent_instructions, ...rest }) => rest);
      return { content: [{ type: 'text', text: JSON.stringify({ ...r.json, items }, null, 2) }] };
    }),
  );

  server.tool(
    'list_my_tasks',
    'Tasks assigned to the caller across every visible project — the "what should I work on" entry point. Optionally filter by execution phase.',
    {
      agent_state: z.enum(PHASES).optional().describe(`One of: ${PHASES.join(', ')}`),
      assignee_id: z.number().optional().describe('Defaults to the caller'),
      updated_after: z.string().optional().describe('ISO 8601; results are inclusive at the second boundary'),
    },
    guard(async (args) => {
      const query = buildListMyTasksQuery(args, await self());
      return toolResult(await client.get('/tasks', query));
    }),
  );

  server.tool(
    'get_task',
    'Full detail for one task: description, tags, links, current phase, and whether it originated from a public form. Refuses a task outside the machine-local project allow-list, same as get_project_context.',
    { task_id: z.number().describe('Task id') },
    guard(async ({ task_id }) => {
      const r = await client.get(`/tasks/${task_id}`);
      if (r.status !== 200) return toolResult(r);
      assertProjectAllowed(projects, r.json.project_id);
      return toolResult(r);
    }),
  );

  server.tool(
    'get_project_context',
    'Everything needed to work on a project: repository URL, branches, dev URL, agent instructions, its columns with the done/backlog ones identified, the machine-local path plus whether writing is permitted here, and — if the operator has named them in projects.json — which column means "in progress" and which means "review".',
    { project_id: z.number().describe('Project id') },
    guard(async ({ project_id }) => {
      const local = assertProjectAllowed(projects, project_id);

      const r = await client.get(`/projects/${project_id}`);
      if (r.status !== 200) return toolResult(r);
      const p = r.json;

      // The interlock: the checkout on disk must be the repository the
      // server says this project is. Reported rather than thrown so the
      // model can say why it is stopping instead of failing opaquely.
      const repo = p.repo_url ? verifyRepoMatches(local.path, p.repo_url) : { ok: null, actual: null };

      return {
        content: [{
          type: 'text',
          text: JSON.stringify({
            ...p,
            ...summariseColumns(p.columns),
            local: {
              path: local.path,
              write: local.write,
              test_cmd: local.test_cmd,
              // Raw config values (an integer column id, an exact column
              // name, or null) — NOT resolved against p.columns here. This
              // bridge is deliberately thin: it surfaces what the operator
              // configured and what the board's columns actually are, and
              // leaves resolving one against the other to whatever calls
              // it (the MCP client's own skill/prompt), same as the raw
              // repo_check outcome below. See the design note in Task 6's
              // README on the resolution rule this bridge does NOT
              // implement here, including its block-vs-skip asymmetry.
              in_progress_column: local.in_progress_column,
              review_column: local.review_column,
            },
            repo_check: repo.ok === null
              ? { status: 'skipped', why: 'project has no repo_url set' }
              : repo.ok
                ? { status: 'ok' }
                : { status: 'mismatch', expected: p.repo_url, actual: repo.actual,
                    action: 'do not modify anything; comment and set agent_state=blocked' },
          }, null, 2),
        }],
      };
    }),
  );

  server.tool(
    'next_task',
    'The task this tick should work on, with the reason: resume (already in flight), rework (review feedback arrived), approved (an approval question was answered), or new. Returns null when there is nothing to do. Only considers projects listed in the machine-local allow-list and columns that are neither done nor backlog, and drops a candidate that turns out to be created from a public form.',
    {},
    guard(async () => {
      const result = await computeNextTask(client, projects, await self());
      if (result.error) return toolResult(result.error);
      return { content: [{ type: 'text', text: JSON.stringify(result, null, 2) }] };
    }),
  );
}
```

- [ ] **Step 4: Зарегистрировать в сервере**

В `mcp/src/server.js`, **выше** `const transport = new StdioServerTransport();` (там оставлена метка-комментарий из Task 1):

```javascript
import { loadLocalProjects } from './local.js';
import { registerReadTools } from './tools/read.js';

const projects = loadLocalProjects(process.env.HOME ?? '');
registerReadTools(server, client, projects);
```

**Порядок импортов между задачами.** Проверено: ни один файл в этом плане не ссылается на модуль из более поздней задачи. Оба межзадачных импорта указывают назад: `tools/write.js` (Task 4) → `tools/read.js` (Task 3) за `PHASES`, и `tools/read.js` (Task 3) → `local.js` (Task 2) за гейтом allow-list — так же, как `tools/read.js` и `tools/comments.js` (Task 5) оба указывают на `watermark.js` (Task 2). Опережающая ссылка, которая была здесь в первой редакции плана (см. врезку в Task 2), устранена переносом `commentsSinceSelf`, а не обходом порядка исполнения.

- [ ] **Step 5: Прогнать тесты**

Run: `cd mcp && node --test`
Expected: PASS, двадцать шесть тестов `read.test.js` зелёных: шесть — запросы и колонки, две — исключение колонок done/backlog, четырнадцать — выбор задачи (девять исходных плюс пять на находки закалки `Triage::pick()`: строгий null на ступени `new`, три пары ступеней лестницы с обоими кандидатами сразу, разрыв ничьей внутри ступени по возрастанию id), четыре — полный сценарий `next_task` (три исходных плюс отдельный тест на `reason: 'idle'` при полностью пустом списке, отличимый от `reason: 'form_bound_exhausted'`). Всего по проекту — пятьдесят четыре (Tasks 1-2) плюс двадцать шесть = восемьдесят.

- [ ] **Step 6: Коммит**

```bash
git add mcp/src/tools/read.js mcp/src/server.js mcp/test/read.test.js
git commit -m "feat(mcp): discovery and task-read tools, with next_task's done/backlog and from_form corrections"
```

---

### Task 4: Инструменты записи

Три отдельных инструмента вместо одного универсального — намеренно. `set_agent_state` вызывается на каждом шаге, и им не должно быть возможно случайно обнулить заголовок.

`move_task` не «carries position maths and sub-status side effects», как утверждала первая редакция этого плана — `TasksHandler::move()` вызывает `TaskRepository::move($id, $columnId, $position)` без `$subStatus`/`$clearSubStatus` (`system/Api/V1/Handlers/TasksHandler.php:254-271`), так что эта ветка никогда не срабатывает. Настоящая причина отдельного инструмента проще: перенос в другую колонку — не патч содержимого. Позицию по умолчанию инструмент теперь считает сам, а не подставляет константу: `1024` — это НЕ «низ колонки», а положение прямо у верха любой уже заполненной колонки, потому что позиции — разреженные float, растущие как `MAX(position) + 1024.0` (`TaskRepository.php:19-22`), а колонки читаются по возрастанию позиции (`:39`).

**Гейт allow-list — не только на чтении.** В закалённом CLI ревью нашло, что четыре команды (`state`, `move`, `comment`, `new-comments`) пропускали проверку по `~/.claude/otack-agent/projects.json`, действуя на проект, которого в файле умышленно не было — вплоть до задачи, заведённой публичной формой. `get_task`/`get_project_context` (Task 3) гейт уже соблюдают; здесь ровно та же дыра была бы в `set_agent_state`/`update_task`/`move_task`, если бы каждый инструмент просто патчил `task_id` не глядя, чей это проект. Это особенно важно под моделью угроз prompt injection: текст задачи — данные, которые пишет человек (в том числе анонимно, через публичную форму), и модель, подтолкнутая этим текстом, обходит любую другую защиту, если хоть один инструмент не сверяется с allow-list сам. Поэтому каждый инструмент ниже сначала читает `GET /tasks/{id}`, чтобы узнать `project_id`, и только потом действует — ценой одного лишнего запроса на вызов, тем же способом, каким это было исправлено в CLI (`.superpowers/sdd/2026-08-31-agent-bridge-client/progress.md`, замечание про выросший бюджет запросов на тик).

**Files:**
- Create: `mcp/src/tools/write.js`
- Modify: `mcp/src/server.js`
- Test: `mcp/test/write.test.js`

**Interfaces:**
- Consumes: `PHASES` из `tools/read.js`; `OtackClient`, `toolResult`, `guard` из Task 1; `assertProjectAllowed` из `mcp/src/local.js` (Task 2).
- Produces: `registerWriteTools(server, client, projects)` — `set_agent_state`, `update_task`, `move_task`, каждый сначала подтягивает задачу через `GET /tasks/{id}` и проверяет её `project_id` по `assertProjectAllowed`, прежде чем писать. Экспортирует `buildUpdatePatch(args)`, `validatePhase(phase)`, `nextPosition(rowsInColumn)`, `computeMovePosition(client, projectId, columnId)` для тестов.

- [ ] **Step 1: Написать падающие тесты**

`mcp/test/write.test.js`:

```javascript
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  buildUpdatePatch, validatePhase, nextPosition, computeMovePosition, registerWriteTools,
} from '../src/tools/write.js';
import { OtackClient } from '../src/client.js';

test('validatePhase accepts every phase in the closed set', () => {
  for (const p of ['researching', 'awaiting_approval', 'implementing', 'review', 'blocked']) {
    assert.equal(validatePhase(p), p);
  }
});

test('validatePhase rejects anything else, naming the valid values', () => {
  assert.throws(() => validatePhase('done'), /researching/);
});

test('validatePhase accepts null to clear the phase', () => {
  assert.equal(validatePhase(null), null);
});

test('buildUpdatePatch forwards only the fields that were supplied', () => {
  assert.deepEqual(buildUpdatePatch({ title: 'New title' }), { title: 'New title' });
});

test('buildUpdatePatch never smuggles agent_state through the general update tool', () => {
  assert.throws(() => buildUpdatePatch({ agent_state: 'review' }), /set_agent_state/);
});

test('buildUpdatePatch passes an explicit null through so a field can be cleared', () => {
  assert.deepEqual(buildUpdatePatch({ due_date: null }), { due_date: null });
});

test('buildUpdatePatch refuses an empty patch rather than making a pointless call', () => {
  assert.throws(() => buildUpdatePatch({}), /at least one/i);
});

// Routes as [method, matcher, respond] triples, or a [matcher, respond] pair
// (implicitly GET, for continuity with the pre-existing computeMovePosition
// tests below). Needed once tool handlers are driven end-to-end: those do a
// GET (project-allow-list check) followed by a PATCH or POST.
function fakeClient(routes) {
  const fetchImpl = async (url, opts) => {
    const method = opts?.method ?? 'GET';
    for (const route of routes) {
      const [routeMethod, matcher, respond] = route.length === 3 ? route : ['GET', route[0], route[1]];
      if (routeMethod === method && matcher.test(url)) {
        return { status: 200, text: async () => JSON.stringify(respond()), headers: new Map() };
      }
    }
    return { status: 404, text: async () => '{}', headers: new Map() };
  };
  return new OtackClient({ baseUrl: 'https://x.example/api/v1', token: 't', fetchImpl });
}

function fakeServer() {
  const tools = new Map();
  return { tool: (name, desc, schema, handler) => tools.set(name, { desc, schema, handler }), tools };
}

test('computeMovePosition places the task above the current top of an empty column', async () => {
  const client = fakeClient([[/\/projects\/42\/tasks\?/, () => ({ items: [] })]]);
  const pos = await computeMovePosition(client, 42, 901);
  assert.equal(pos, 1024.0);
});

test('computeMovePosition places the task past the current highest position in a populated column', async () => {
  const client = fakeClient([[/\/projects\/42\/tasks\?/, () => ({ items: [
    { id: 1, column_id: 901, position: 512.0 },
    { id: 2, column_id: 901, position: 2048.0 },
  ] })]]);
  const pos = await computeMovePosition(client, 42, 901);
  assert.equal(pos, 3072.0);
});

// nextPosition — the same cases as Triage::nextPosition() in the shipped
// PHP (tests/unit/test_agent_triage.php). computeMovePosition is now a thin
// wrapper around it; these pin the arithmetic and its failure modes
// directly, without a fake HTTP round trip.

test('nextPosition starts an empty column at 1024.0', () => {
  assert.equal(nextPosition([]), 1024.0);
});

test('nextPosition appends past the current maximum in the column', () => {
  const pos = nextPosition([{ position: 1024.0 }, { position: 3200.5 }, { position: 2048.0 }]);
  assert.equal(pos, 4224.5);
});

test('nextPosition throws instead of silently colliding on a huge existing position', () => {
  // A position already near the top of double precision makes `max + 1024`
  // round back down to `max` — a tie, not an append. The moved card would
  // land exactly on top of the card it was supposed to follow.
  assert.throws(() => nextPosition([{ position: 1e308 }]), /not usable/);
});

test('nextPosition throws instead of producing a non-finite position', () => {
  assert.throws(() => nextPosition([{ position: Infinity }]), /not usable/);
});

// Allow-list enforcement — the finding that four CLI commands (state, move,
// comment, new-comments) skipped the project gate, proven live against a
// task deliberately outside projects.json. Every write tool here resolves
// the task's project via GET /tasks/{id} first and refuses to act if that
// project isn't allow-listed, exactly like get_task/get_project_context
// (Task 3) already do for reads.

test('set_agent_state refuses to act on a task outside the machine-local project allow-list', async () => {
  const server = fakeServer();
  const projects = new Map([['42', { path: '/tmp/p', write: true }]]);
  const client = fakeClient([['GET', /\/tasks\/9$/, () => ({ id: 9, project_id: 99 })]]);
  registerWriteTools(server, client, projects);
  const r = await server.tools.get('set_agent_state').handler({ task_id: 9, agent_state: 'review' });
  assert.equal(r.isError, true);
  assert.match(r.content[0].text, /projects\.json/);
});

test('set_agent_state proceeds when the task\'s project is allow-listed', async () => {
  const server = fakeServer();
  const projects = new Map([['42', { path: '/tmp/p', write: true }]]);
  const client = fakeClient([
    ['GET', /\/tasks\/9$/, () => ({ id: 9, project_id: 42 })],
    ['PATCH', /\/tasks\/9$/, () => ({ id: 9, agent_state: 'review' })],
  ]);
  registerWriteTools(server, client, projects);
  const r = await server.tools.get('set_agent_state').handler({ task_id: 9, agent_state: 'review' });
  assert.equal(r.isError, undefined);
});

test('update_task refuses a task outside the machine-local project allow-list', async () => {
  const server = fakeServer();
  const projects = new Map([['42', { path: '/tmp/p', write: true }]]);
  const client = fakeClient([['GET', /\/tasks\/9$/, () => ({ id: 9, project_id: 99 })]]);
  registerWriteTools(server, client, projects);
  const r = await server.tools.get('update_task').handler({ task_id: 9, title: 'x' });
  assert.equal(r.isError, true);
  assert.match(r.content[0].text, /projects\.json/);
});

test('move_task refuses a task outside the allow-list even with an explicit position', async () => {
  // Explicit position previously skipped the task GET entirely — pinned so
  // "position was supplied" can never become a bypass of the gate.
  const server = fakeServer();
  const projects = new Map([['42', { path: '/tmp/p', write: true }]]);
  const client = fakeClient([['GET', /\/tasks\/9$/, () => ({ id: 9, project_id: 99 })]]);
  registerWriteTools(server, client, projects);
  const r = await server.tools.get('move_task').handler({ task_id: 9, column_id: 5, position: 100 });
  assert.equal(r.isError, true);
  assert.match(r.content[0].text, /projects\.json/);
});
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `cd mcp && node --test`
Expected: FAIL — `src/tools/write.js` не существует.

- [ ] **Step 3: Реализовать**

`mcp/src/tools/write.js`:

```javascript
import { z } from 'zod';
import { toolResult, guard } from '../client.js';
import { assertProjectAllowed } from '../local.js';
import { PHASES } from './read.js';

export function validatePhase(phase) {
  if (phase === null) return null;
  if (!PHASES.includes(phase)) {
    throw new Error(`unknown agent_state "${phase}" — valid: ${PHASES.join('|')}`);
  }
  return phase;
}

const UPDATABLE = ['title', 'description', 'column_id', 'assignee_id', 'priority', 'due_date', 'sub_status'];

/**
 * The general task patch. `agent_state` is deliberately refused here: it is
 * the one write an agent makes constantly, and keeping it in its own tool
 * means a phase change can never accidentally also blank a title.
 */
export function buildUpdatePatch(args) {
  if ('agent_state' in args) {
    throw new Error('use set_agent_state to change the execution phase, not update_task');
  }
  const patch = {};
  for (const f of UPDATABLE) {
    if (f in args) patch[f] = args[f];
  }
  if (Object.keys(patch).length === 0) {
    throw new Error(`supply at least one of: ${UPDATABLE.join(', ')}`);
  }
  return patch;
}

/**
 * Position for a card appended to the end of a column. A line-by-line port
 * of `Triage::nextPosition()` (`agent/lib/Triage.php`): `max` starts at 0.0
 * and only ever rises, so `max + 1024.0` is correct unconditionally — there
 * is no separate empty-column branch to get wrong. What it refuses to do
 * silently: a position already near the top of double precision would let
 * `max + 1024.0` round back down to `max` (a tie, not an append — the moved
 * card would collide with the card it was meant to follow), and an
 * already-infinite position makes the sum infinite too, which
 * `JSON.stringify` would then serialise as `null` with nothing downstream
 * to catch it. Both throw here instead.
 */
export function nextPosition(rowsInColumn = []) {
  let max = 0.0;
  for (const r of rowsInColumn) {
    // No Number.isFinite() guard here on purpose: an existing Infinity
    // position MUST propagate into `max` so the check below catches it.
    // Filtering it out here would silently treat an already-broken
    // position as if the column were empty — exactly the failure mode
    // this function exists to refuse. Mirrors `Triage::nextPosition()`'s
    // bare `max($max, (float)$r['position'])`, with no filter of its own.
    max = Math.max(max, Number(r.position));
  }
  const next = max + 1024.0;
  if (!Number.isFinite(next) || next <= max) {
    throw new Error(`nextPosition: computed position ${next} is not usable past max ${max}`);
  }
  return next;
}

/**
 * The position that places a task at the bottom of `columnId`: one gap
 * above whatever is already the highest position there, matching how
 * `TaskRepository::create()` seeds a new task (`MAX(position) + 1024.0`,
 * `TaskRepository.php:19-22`). A bare `1024.0` is NOT "the bottom" — columns
 * are read in ascending position order (`TaskRepository::listForColumnPaged`,
 * `:31-40`), so a constant 1024 sorts ABOVE anything already past that
 * value, i.e. near the top of a populated column.
 */
export async function computeMovePosition(client, projectId, columnId) {
  const r = await client.get(`/projects/${projectId}/tasks`, { column_id: columnId, limit: 100 });
  return nextPosition(r.json?.items ?? []);
}

/**
 * `projects` (the machine-local allow-list, Task 2) is a REQUIRED third
 * argument, not an optional convenience: every tool below names a task by
 * id and must resolve which project it belongs to — via `GET /tasks/{id}`
 * — before writing to it. Skipping that check was a proven defect in the
 * CLI (four commands acted on a project deliberately absent from
 * projects.json); see the note above Task 4's Files list for the full
 * finding and why it matters most under prompt injection specifically.
 */
export function registerWriteTools(server, client, projects) {
  server.tool(
    'set_agent_state',
    'Set a task\'s execution phase. This is the write an automated worker makes on every step, kept separate from update_task so a phase change can never also modify the task\'s content.',
    {
      task_id: z.number().describe('Task id'),
      agent_state: z.string().nullable().describe(
        `One of: ${PHASES.join(', ')}, or null to clear the phase. (PATCH /tasks/{id} also treats an empty string as the same clear signal — TasksHandler::update(), system/Api/V1/Handlers/TasksHandler.php:235-238 — but this tool only accepts null for that.)`,
      ),
    },
    guard(async ({ task_id, agent_state }) => {
      const t = await client.get(`/tasks/${task_id}`);
      if (t.status !== 200) return toolResult(t);
      assertProjectAllowed(projects, t.json.project_id);
      return toolResult(await client.patch(`/tasks/${task_id}`, { agent_state: validatePhase(agent_state) }));
    }),
  );

  server.tool(
    'update_task',
    'Update a task\'s mutable content: title, description, column, assignee, priority, due date, sub-status. Use set_agent_state for the execution phase.',
    {
      task_id: z.number().describe('Task id'),
      title: z.string().optional(),
      // description/assignee_id/due_date/sub_status accept an explicit null
      // to clear the field (PATCH /tasks/{id}, TasksHandler.php:203-227);
      // title and column_id have no clearing story, priority always
      // resolves to a concrete value.
      description: z.string().nullable().optional(),
      column_id: z.number().optional(),
      assignee_id: z.number().nullable().optional(),
      priority: z.enum(['none', 'low', 'medium', 'high', 'urgent']).optional(),
      due_date: z.string().nullable().optional(),
      sub_status: z.string().nullable().optional(),
    },
    guard(async ({ task_id, ...rest }) => {
      const t = await client.get(`/tasks/${task_id}`);
      if (t.status !== 200) return toolResult(t);
      assertProjectAllowed(projects, t.json.project_id);
      return toolResult(await client.patch(`/tasks/${task_id}`, buildUpdatePatch(rest)));
    }),
  );

  server.tool(
    'move_task',
    'Move a task to a column. When no position is given, the task is placed at the bottom of the target column, computed from that column\'s current highest position — not a fixed constant.',
    {
      task_id: z.number().describe('Task id'),
      column_id: z.number().describe('Target column id — see get_project_context'),
      position: z.number().optional().describe('Sort position within the column; omit to place the task at the bottom'),
    },
    guard(async ({ task_id, column_id, position }) => {
      // Always fetched — even when position is given explicitly — because
      // this GET is also how the allow-list is enforced. An explicit
      // position must never become a way to skip the project check.
      const t = await client.get(`/tasks/${task_id}`);
      if (t.status !== 200) return toolResult(t);
      assertProjectAllowed(projects, t.json.project_id);
      const pos = position ?? await computeMovePosition(client, t.json.project_id, column_id);
      return toolResult(await client.post(`/tasks/${task_id}/move`, { column_id, position: pos }));
    }),
  );
}
```

- [ ] **Step 4: Зарегистрировать и прогнать**

В `mcp/src/server.js`, **выше** `const transport = new StdioServerTransport();`:

```javascript
import { registerWriteTools } from './tools/write.js';
registerWriteTools(server, client, projects);
```

Run: `cd mcp && node --test`
Expected: PASS, семнадцать тестов `write.test.js` зелёных: семь — валидация фазы и патча, два — расчёт позиции переноса через `computeMovePosition`, четыре — `nextPosition` напрямую (пустая колонка, обычный случай, throw на позиции у предела double, throw на бесконечной позиции), четыре — гейт allow-list (`set_agent_state` отказывает/пропускает, `update_task` отказывает, `move_task` отказывает даже с явной позицией). Всего по проекту — восемьдесят (Tasks 1-3) плюс семнадцать = девяносто семь.

- [ ] **Step 5: Коммит**

```bash
git add mcp/src/tools/write.js mcp/src/server.js mcp/test/write.test.js
git commit -m "feat(mcp): task write tools with the phase change kept separate and a real bottom-of-column position"
```

---

### Task 5: Инструменты комментариев

`new_comments` — единственный инструмент с настоящей логикой водораздела (спека §4.1), но сама функция `commentsSinceSelf` живёт в `mcp/src/watermark.js` (Task 2) — см. врезку в начале Task 2 про то, почему. Здесь она только реэкспортируется, чтобы код, ожидающий найти её рядом с инструментами комментариев, не ломался.

Комментарии читаются постранично через `paginateAll` (Task 1), а не одним `limit: 100`. При плоском `limit: 100` без учёта `next_cursor` последний собственный комментарий агента — сам водораздел — выпадал бы за пределы первой страницы у любой ветки длиннее ста реплик, и вся первая страница ошибочно выглядела бы «новой».

**Тот же гейт allow-list, что и в Task 4.** В закалённом CLI `comment` и `new-comments` были в числе четырёх команд, пропускавших проверку по `projects.json`. Все три инструмента ниже сначала читают `GET /tasks/{task_id}`, чтобы узнать `project_id`, и только потом читают/пишут комментарии — `new_comments` этой ценой платит за право читать чужую переписку, `add_comment`/`reply_comment` — за право в неё писать.

**Files:**
- Create: `mcp/src/tools/comments.js`
- Modify: `mcp/src/server.js`
- Test: `mcp/test/comments.test.js`

**Interfaces:**
- Consumes: `OtackClient`, `toolResult`, `guard`, `paginateAll`, `resolveSelfId` из Task 1; `assertProjectAllowed` из `mcp/src/local.js` (Task 2); `commentsSinceSelf` из `mcp/src/watermark.js` (Task 2).
- Produces: `registerCommentTools(server, client, projects)` — `add_comment`, `reply_comment`, `new_comments`, каждый сначала проверяет `project_id` задачи через `assertProjectAllowed`. Реэкспортирует `commentsSinceSelf`.

- [ ] **Step 1: Написать падающие тесты**

`mcp/test/comments.test.js`:

```javascript
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { registerCommentTools, commentsSinceSelf } from '../src/tools/comments.js';
import { OtackClient } from '../src/client.js';

function fakeServer() {
  const tools = new Map();
  return { tool: (name, desc, schema, handler) => tools.set(name, { desc, schema, handler }), tools };
}

// Routes as [method, matcher, respond, status?] tuples (status defaults to
// 200; add_comment/reply_comment need 201, since toolResult() there is
// called with okStatuses=[201]). Needed here (unlike the Step-1-only
// fakeServer above) because the allow-list tests below drive real tool
// handlers, which do a GET (project check) before the POST that
// add_comment/reply_comment make, or before the paginated GETs new_comments
// makes.
function fakeClient(routes) {
  const fetchImpl = async (url, opts) => {
    const method = opts?.method ?? 'GET';
    for (const [routeMethod, matcher, respond, status] of routes) {
      if (routeMethod === method && matcher.test(url)) {
        return { status: status ?? 200, text: async () => JSON.stringify(respond()), headers: new Map() };
      }
    }
    return { status: 404, text: async () => '{}', headers: new Map() };
  };
  return new OtackClient({ baseUrl: 'https://x.example/api/v1', token: 't', fetchImpl });
}

test('registerCommentTools registers add_comment, reply_comment and new_comments', () => {
  const server = fakeServer();
  registerCommentTools(server, null, new Map());
  assert.deepEqual([...server.tools.keys()].sort(), ['add_comment', 'new_comments', 'reply_comment']);
});

test('re-exports commentsSinceSelf so code that imports it from here keeps working', () => {
  assert.equal(typeof commentsSinceSelf, 'function');
});

// Allow-list enforcement — comment and new-comments were two of the four
// CLI commands the closed hardening found skipping the gate.

test('add_comment refuses a task outside the machine-local project allow-list', async () => {
  const server = fakeServer();
  const projects = new Map([['42', { path: '/tmp/p', write: true }]]);
  const client = fakeClient([['GET', /\/tasks\/9$/, () => ({ id: 9, project_id: 99 })]]);
  registerCommentTools(server, client, projects);
  const r = await server.tools.get('add_comment').handler({ task_id: 9, body: 'hi' });
  assert.equal(r.isError, true);
  assert.match(r.content[0].text, /projects\.json/);
});

test('add_comment proceeds when the task\'s project is allow-listed', async () => {
  const server = fakeServer();
  const projects = new Map([['42', { path: '/tmp/p', write: true }]]);
  const client = fakeClient([
    ['GET', /\/tasks\/9$/, () => ({ id: 9, project_id: 42 })],
    ['POST', /\/comments$/, () => ({ id: 501 }), 201],
  ]);
  registerCommentTools(server, client, projects);
  const r = await server.tools.get('add_comment').handler({ task_id: 9, body: 'hi' });
  assert.equal(r.isError, undefined);
});

test('reply_comment refuses a task outside the allow-list', async () => {
  const server = fakeServer();
  const projects = new Map([['42', { path: '/tmp/p', write: true }]]);
  const client = fakeClient([['GET', /\/tasks\/9$/, () => ({ id: 9, project_id: 99 })]]);
  registerCommentTools(server, client, projects);
  const r = await server.tools.get('reply_comment').handler({ task_id: 9, parent_id: 3, body: 'hi' });
  assert.equal(r.isError, true);
  assert.match(r.content[0].text, /projects\.json/);
});

test('new_comments refuses a task outside the allow-list, before reading any comments', async () => {
  const server = fakeServer();
  const projects = new Map([['42', { path: '/tmp/p', write: true }]]);
  const client = fakeClient([['GET', /\/tasks\/9$/, () => ({ id: 9, project_id: 99 })]]);
  registerCommentTools(server, client, projects);
  const r = await server.tools.get('new_comments').handler({ task_id: 9 });
  assert.equal(r.isError, true);
  assert.match(r.content[0].text, /projects\.json/);
});
```

The watermark logic itself (`commentsSinceSelf`) has its own exhaustive suite in `mcp/test/watermark.test.js` (Task 2) — not duplicated here.

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `cd mcp && node --test`
Expected: FAIL — `src/tools/comments.js` не существует.

- [ ] **Step 3: Реализовать**

`mcp/src/tools/comments.js`:

```javascript
import { z } from 'zod';
import { toolResult, guard, paginateAll, resolveSelfId } from '../client.js';
import { assertProjectAllowed } from '../local.js';
import { commentsSinceSelf } from '../watermark.js';

export { commentsSinceSelf };

/**
 * `projects` is required for the same reason it is in `registerWriteTools`
 * (Task 4): a task id alone says nothing about which project it belongs
 * to, and `comment`/`new-comments` were two of the four CLI commands
 * proven to skip that check. Every tool below resolves the project via
 * `GET /tasks/{task_id}` first.
 */
export function registerCommentTools(server, client, projects) {
  // Validated once via resolveSelfId() (Task 1) rather than read out of
  // `r.json?.id ?? null` here — the same boundary check registerReadTools
  // makes (Task 3), so a bad /me shape fails loud in exactly one place
  // instead of being re-derived, and possibly re-broken, in every tool
  // file that needs the caller's own id.
  let selfId = null;
  const self = async () => {
    if (selfId === null) selfId = await resolveSelfId(client);
    return selfId;
  };

  server.tool(
    'add_comment',
    'Start a new comment thread on a task — progress notes, findings, questions. Markdown is supported.',
    {
      task_id: z.number().describe('Task id'),
      body: z.string().describe('Markdown body'),
    },
    guard(async ({ task_id, body }) => {
      const t = await client.get(`/tasks/${task_id}`);
      if (t.status !== 200) return toolResult(t);
      assertProjectAllowed(projects, t.json.project_id);
      return toolResult(
        await client.post('/comments', { entity: 'task', entity_id: task_id, body }),
        [201],
      );
    }),
  );

  server.tool(
    'reply_comment',
    'Reply inside an existing thread. Use this rather than add_comment when answering someone, so the conversation stays threaded.',
    {
      task_id: z.number().describe('Task id'),
      parent_id: z.number().describe('Id of the comment being replied to'),
      body: z.string().describe('Markdown body'),
    },
    guard(async ({ task_id, parent_id, body }) => {
      const t = await client.get(`/tasks/${task_id}`);
      if (t.status !== 200) return toolResult(t);
      assertProjectAllowed(projects, t.json.project_id);
      return toolResult(
        await client.post('/comments', { entity: 'task', entity_id: task_id, body, parent_id }),
        [201],
      );
    }),
  );

  server.tool(
    'new_comments',
    'Comments added to a task since the caller last commented on it — "what has been said while I was away". The since-my-last-comment logic lives here so it never has to be re-derived; follows pagination so a thread longer than one page cannot make an old page look new.',
    { task_id: z.number().describe('Task id') },
    guard(async ({ task_id }) => {
      const t = await client.get(`/tasks/${task_id}`);
      if (t.status !== 200) return toolResult(t);
      assertProjectAllowed(projects, t.json.project_id);
      // paginateAll (Task 1) now throws rather than returning a non-200
      // status, so there is nothing left here to branch on — a failed page
      // propagates out of this handler and is reported by guard(), same as
      // any other failure.
      const all = await paginateAll(client, `/tasks/${task_id}/comments`);
      const items = commentsSinceSelf(all.items, await self());
      return { content: [{ type: 'text', text: JSON.stringify({ items }, null, 2) }] };
    }),
  );
}
```

- [ ] **Step 4: Зарегистрировать и прогнать**

В `mcp/src/server.js`, **выше** `const transport = new StdioServerTransport();` — последний из трёх блоков регистрации; ниже этой черты в файле остаются только создание транспорта и `connect()`:

```javascript
import { registerCommentTools } from './tools/comments.js';
registerCommentTools(server, client, projects);
```

Run: `cd mcp && node --test`
Expected: PASS, шесть тестов `comments.test.js` зелёных: два исходных (регистрация трёх инструментов, реэкспорт `commentsSinceSelf`) плюс четыре на гейт allow-list (`add_comment` отказывает/пропускает, `reply_comment` отказывает, `new_comments` отказывает до чтения единого комментария). Всего по проекту — девяносто семь (Tasks 1-4) плюс шесть = сто три.

- [ ] **Step 5: Коммит**

```bash
git add mcp/src/tools/comments.js mcp/src/server.js mcp/test/comments.test.js
git commit -m "feat(mcp): comment tools, paginated, with the watermark helper re-exported for continuity"
```

---

### Task 6: Документация и подключение

**Files:**
- Create: `mcp/README.md`
- Modify: `docs/MCP.md` (снять статус «замысел», сослаться на реализацию, дополнить таблицу инструментов)

- [ ] **Step 1: Написать mcp/README.md**

Содержание: назначение; предпосылка — сервер 1.7.1+; установка (`cd mcp && npm install`); две переменные окружения; блок конфигурации для Claude Desktop —

```json
{
  "mcpServers": {
    "otack-manager": {
      "command": "node",
      "args": ["/absolute/path/to/otack-tasks/mcp/src/server.js"],
      "env": {
        "OTACK_API_URL": "https://otack-manager.neoweblab.eu/api/v1",
        "OTACK_API_TOKEN": "otk_…"
      }
    }
  }
}
```

— и та же команда для `claude mcp add`. Таблица двенадцати инструментов с однострочным назначением каждого:

| Инструмент | Назначение |
|---|---|
| `whoami` | Проверить токен и узнать, от чьего имени действует мост |
| `list_projects` | Список видимых проектов (без `agent_instructions` — см. `get_project_context`) |
| `list_my_tasks` | Задачи исполнителя, опционально по фазе |
| `next_task` | Задача этого тика с причиной (resume/rework/approved/new); поправки на done/backlog-колонки и на задачи из формы |
| `get_task` | Полная карточка одной задачи |
| `get_project_context` | Репозиторий, ветки, колонки, инструкции агента, локальный путь, интерлок `repo_url` и — если заданы в `projects.json` — сырые `in_progress_column`/`review_column` |
| `set_agent_state` | Смена фазы исполнения — единственная запись на каждом шаге |
| `update_task` | Правка заголовка/описания/колонки/исполнителя/приоритета/срока/подстатуса |
| `move_task` | Перенос в другую колонку с корректной позицией |
| `add_comment` | Новая ветка комментариев на задаче |
| `reply_comment` | Ответ внутри существующей ветки |
| `new_comments` | Комментарии с момента последнего собственного (водораздел) |

Раздел «границы»:

«У токена нет скоупов, доступ ограничен ролью пользователя токена и его членством в проектах; мост ничего к этому не добавляет, кроме машинного списка разрешённых проектов (Task 2).

**Право доступа к самому токену — слабее, чем у CLI.** `OTACK_API_TOKEN` мост читает из переменной окружения в конфиге MCP-клиента (`claude_desktop_config.json` или аргумент `claude mcp add`) и никак не проверяет права доступа к этому файлу. CLI (`agent/bin/otack`) отказывается работать, если `~/.claude/otack-agent/.env` доступен группе или всем — режим обязан быть `0600` (спека §10). У MCP-моста такой проверки нет и не может появиться сама по себе: конфиг живёт внутри клиентского приложения, а не в файле, который открывает наш код. **Права нужно выставить вручную**:

```bash
chmod 600 ~/Library/Application\ Support/Claude/claude_desktop_config.json
```

(или путь конфига другого MCP-клиента). Это не эквивалент гейта CLI, а его более слабая замена: файл конфигурации может хранить секреты и других серверов, и его права — забота пользователя, а не кода в `mcp/`.

**`write: false` в `get_project_context` — совет, а не запрет, и граница проходит не здесь.** Эмпирическая находка на CLI-плане (`.superpowers/sdd/2026-08-31-agent-bridge-client/progress.md`, Task 5 Step 5): `--allowedTools` у `claude`-харнесса ДОБАВЛЯЕТ правила к `~/.claude/settings.json` оператора, а не заменяет их — Claude Code, запущенный из обычной сессии, наследует всё, что эта сессия и так могла бы сделать. Для CLI это было закрыто отдельной обёрткой (`agent/bin/otack-tick`, изоляция через `CLAUDE_CONFIG_DIR`, экспериментально проверенная сверкой `git log`/`git status` СНАРУЖИ процесса на всех шести `--permission-mode`, финальный выбор — `dontAsk`, единственный режим, отказывающий во всём вне allow-list и для Bash, и для Write/Edit одновременно). У MCP-моста такой обёртки нет и появиться сама по себе не может: сервер — просто дочерний процесс того MCP-клиента, который его запустил (Claude Desktop, `claude mcp add` внутри обычной сессии, что угодно ещё), и какие права имеет этот клиент — решает не код в `mcp/`. Поэтому `write: false`, которое `get_project_context` возвращает в поле `local.write`, — это **совет исполнителю/скиллу**, а не техническая граница: мост не трогает файловую систему сам, значит и запретить запись программно не может. Настоящая граница — там, откуда запущен хост (изолированный ли у него конфиг, какой у него `--permission-mode`, какие правила уже лежат в его `settings.json`), и это ответственность того, кто разворачивает MCP-мост, а не этого плана.

**Разрешение колонок «в работе»/«на проверке» — асимметрично, и мост его не делает сам.** `get_project_context` (Task 3) отдаёт сырые `in_progress_column`/`review_column` из `projects.json` (id или точное имя колонки) рядом с реальным списком колонок проекта — но не сопоставляет одно с другим: это дело того, что вызывает мост (скилл/промпт клиента), а не тонкого моста самого по себе. Тот же пост-хок фикс на CLI (`.superpowers/sdd/2026-08-31-agent-bridge-client/progress.md`, «Post-hoc fix: in-progress/review column resolution») установил порядок разрешения, который здесь стоит повторить в промпте/скилле MCP-клиента: (1) конфиг называет колонку явно (по id или точному имени) → использовать её; (2) конфиг не называет, но ровно одна не-done/не-backlog колонка — очевидное совпадение по имени → допустимо ТОЛЬКО для переноса в «в работе», никогда для «на проверке»; (3) иначе — не гадать: прокомментировать реальные имена колонок проекта и остановиться. Асимметрия на шаге 3 — не опечатка: для переноса «в работе» (до начала работы) неразрешённая колонка — это блокировка, законный стоп. Для передачи на проверку (работа уже сделана, тесты зелёные, ждём человека) неразрешённая колонка НЕ должна значить «заблокировано» — это спрятало бы готовую задачу за косметической неудачей переноса. Вместо этого: пропустить `move_task`, сказать об этом в комментарии-передаче, и всё равно выставить `set_agent_state(review)` — именно `agent_state` читает машина фаз и логика выбора задачи (`next_task`), а не то, в какой колонке карточка визуально лежит.»

- [ ] **Step 2: Обновить docs/MCP.md**

Заменить формулировку «intent, not a build order» (L1) на ссылку на реализацию и `mcp/README.md`. **Обновить таблицу инструментов §3 до двенадцати** — добавить строку `next_task` (у него нет единого REST-аналога: это агрегат `GET /tasks` + `GET /projects/{id}` по каждому встретившемуся проекту + `GET /tasks/{id}/comments` + верифицирующий `GET /tasks/{id}`, а не проброс одного маршрута). В первой редакции этого плана обновление таблицы было отложено на Task 7, но Task 7 не может исполниться до обкатки CLI (см. её собственную шапку) — таблица не должна тем временем перечислять одиннадцать инструментов (как на L78 и L121 `docs/MCP.md` на момент написания этого плана), когда сервер уже отдаёт двенадцать. Task 7 тогда правит эту же таблицу по итогам обкатки, а не создаёт её с нуля. **Сохранить** раздел §5 про гейт как исторический контекст — он объясняет, почему таблица инструментов именно такая, и почему её следует пересматривать по итогам реальной работы. **Добавить в §5 (или отдельный подраздел рядом) короткую ссылку на два новых раздела «границы» из `mcp/README.md`** (Step 1 выше — про `--allowedTools`-находку и про асимметрию разрешения колонок): `docs/MCP.md` — точка входа для читателя, который решает, доверять ли мосту, и оба факта не должны обнаруживаться только тем, кто откроет README.

- [ ] **Step 3: Живая проверка всех инструментов**

Подключить сервер к Claude Desktop или `claude mcp add`, затем прогнать вручную все двенадцать: `whoami` → `list_projects` → `list_my_tasks` → `get_project_context` → `get_task` → `next_task` → `add_comment` → `reply_comment` → `new_comments` → `set_agent_state` → `update_task` → `move_task`. `next_task` и `move_task` — единственные с нетривиальной многошаговой логикой (агрегация по проектам, повторный выбор при form-created, расчёт позиции) — проверить их результат вручную по данным в веб-интерфейсе, а не только по факту отсутствия ошибки. Каждый инструмент должен вернуть осмысленный результат, а неверный `agent_state` — читаемую ошибку, а не стек.

- [ ] **Step 4: Коммит**

```bash
git add mcp/README.md docs/MCP.md
git commit -m "docs(mcp): bridge documentation and client registration"
```

---

### Task 7: Сверка с реальным опытом CLI

**Эта задача не механическая и не может быть выполнена до обкатки.** Она существует, потому что `docs/MCP.md` §5 обещает именно её.

- [ ] **Step 1: Собрать факты**

После того как связка «скилл + CLI» отработала на реальном бэклоге, ответить письменно: какие команды CLI вызывались чаще всего; какие оказались не нужны; где понадобилось два вызова там, где предполагался один; каких данных не хватало в ответах.

- [ ] **Step 2: Свести с таблицей инструментов**

Для каждого из двенадцати: пережил без изменений / нужно разделить / нужно слить / не нужен вовсе. Добавить инструменты, которых не хватило.

- [ ] **Step 3: Внести правки и зафиксировать причины**

Изменить состав инструментов по итогам сверки. В `docs/MCP.md` записать, что именно показала обкатка — чтобы следующий читатель видел не только итог, но и основание.

---

## Проверка плана против docs/MCP.md и спеки

| Требование | Задача |
|---|---|
| §3 таблица двенадцати инструментов | Tasks 3, 4, 5 |
| §4 транспорт stdio | Task 1 |
| §4 конфигурация двумя переменными | Task 1, Task 6 |
| §4 отсутствие своей модели доступа | Task 6 (раздел «границы» в README) |
| §5 гейт «только после обкатки CLI» | Task 7 + шапка плана |
| Спека §4.1 водораздел по своему комментарию | Task 2 (`commentsSinceSelf` в `mcp/src/watermark.js`), используется в Task 3 и Task 5 |
| Спека §4.3 приоритет фаз | Task 3 (`pickNextTask`, `computeNextTask`) |
| Спека §7.3 машинный список проектов | Task 2 |
| Спека §7.3 сверка `repo_url` с `git remote` | Task 2 (`verifyRepoMatches`), выдаётся в `get_project_context` |
| Гейт allow-list — не только у инструментов чтения | Task 4 (`set_agent_state`, `update_task`, `move_task`), Task 5 (`add_comment`, `reply_comment`, `new_comments`) |
| Асимметрия разрешения колонок «в работе»/«на проверке» | Task 3 (`get_project_context` отдаёт сырые `in_progress_column`/`review_column`), задокументирована в Task 6 «границы» |
| `write: false` — совет, не запрет; реальная граница — хост запуска | Task 6 «границы» (находка про `--allowedTools`) |

**Двенадцатый инструмент.** `docs/MCP.md` §3 перечислял одиннадцать; здесь их двенадцать — добавлен `next_task`. Причина: без него мост слабее CLI, у которого есть `otack next`, и приоритет фаз пришлось бы выводить модели заново каждый тик. Task 6 (Step 2) вносит его в таблицу `docs/MCP.md`; Task 7 в дальнейшем эту же таблицу пересматривает по факту обкатки, а не создаёт заново.

Осознанно не покрыто:

- `list_projects` не отдаёт `agent_instructions` — инструмент вырезает это поле перед тем, как отдать список модели (Task 3), полный текст приходит только через `get_project_context` для одного проекта за раз.
- Инструментов удаления нет ни для задач, ни для комментариев: у автономного исполнителя нет сценария, где ему нужно что-то удалять.
- Гейт `write: false` остаётся **советующим**: мост отдаёт флаг в `get_project_context`, а соблюдает его скилл. Программно запретить запись мост не может — он не трогает файловую систему, её трогает агент. Единственный программный гейт здесь — видимость проекта (Task 2, теперь применённый единообразно и к записи, и к комментариям в Tasks 4-5), и он есть. Настоящая граница прав — там, откуда запущен MCP-хост, не в этом мосту; см. находку про `--allowedTools` в разделе «границы» `mcp/README.md` (Task 6, Step 1).
- Разрешение колонок «в работе»/«на проверке» мост не делает сам — только отдаёт сырые `in_progress_column`/`review_column` рядом со списком колонок (`get_project_context`, Task 3). Асимметричный порядок разрешения (block-and-stop для «в работе», skip-move-но-`agent_state=review` для «на проверке») — забота скилла/промпта MCP-клиента; см. тот же раздел «границы».
