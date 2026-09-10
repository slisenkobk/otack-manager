# Agent Bridge — серверная часть. План внедрения

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Довести Otack Manager API до состояния, в котором внешний бот-исполнитель может брать заасайненные на него задачи, вести по ним фазу работы и видеть, к какому коду относится проект.

**Architecture:** Изменения затрагивают четыре слоя: политику прав (`RolePolicy`), схему (две миграции), API v1 (хендлеры + роутинг) и веб-UI (форма проекта, бейдж фазы). Ничего не ломается для существующих клиентов — все изменения аддитивные, кроме расширения `canEditTask`, которое только добавляет разрешения.

**Tech Stack:** PHP 8.2+ без Composer, SQLite/MySQL 8, Schema DSL миграции, собственный тест-раннер (`it()` / `assert_eq`), Playwright для e2e.

**Spec:** `.dev-notes/superpowers/specs/2026-08-27-agent-bridge-design.md`

## Global Constraints

- PHP 8.2+, **без Composer**. Никаких новых зависимостей.
- Миграции **append-only**. Имя применённого файла — навсегда. Новые файлы с бо́льшим datetime-префиксом.
- **i18n-паритет** обязателен: каждый новый ключ добавляется в `system/i18n/en.php`, `uk.php`, `pl.php`. Проверяется `tests/unit/test_i18n.php` (тест `i18n catalogues have key parity`).
- **Никаких `alert()` / `confirm()` / `prompt()`** в JS. Только `UI.toast` / `UI.confirm` / `UI.prompt` из `public/assets/js/ui.js`.
- **Никакого `innerHTML`** для пользовательских строк. `textContent` / `createElement`.
- Инлайновые `<style>` — только через `inline_style()` с per-request nonce.
- Только существующие токены отступов: `--space-2/3/4/5/6/8/10/12/16/20/24/32`. **`--space-7`, `--space-9`, `--space-11` не существуют** и молча схлопываются в `0`.
- `docs/openapi.yaml` — часть контракта. Расхождение с роутингом ловит `tests/unit/test_openapi_drift.php`.
- Прогон: `make unit`, `make api`, `make e2e`. Полный — `make test`.

**Допущения, помеченные владельцем как «по умолчанию»** (меняются без переписывания плана): интервал тика 30 мин, порог зависания 3 ч, уведомление о `blocked` — только комментарий в задаче, пуш без человека — только в `dev_branch`.

---

### Task 1: Исполнитель может вести свою задачу

Блокер из §6.1 спеки. Сейчас `canEditTask` не смотрит на `assignee_id` вообще, поэтому сотрудник (и бот-`employee`) не может сдвинуть заасайненную на него карточку.

**Files:**
- Modify: `system/Service/RolePolicy.php:39-48`
- Test: `tests/unit/test_role_policy.php` (дописать в блок `── canEditTask ──`, после строки 142)
- Test: `tests/api/test_tasks.php` (дописать в конец)

**Interfaces:**
- Consumes: ничего из предыдущих задач.
- Produces: `RolePolicy::canEditTask(array $user, array $task, ProjectMemberRepository $members): bool` — сигнатура не меняется, меняется только результат для случая «пользователь = `assignee_id` задачи».

- [ ] **Step 1: Написать падающие unit-тесты**

Дописать в `tests/unit/test_role_policy.php`:

```php
it('canEditTask: assignee can edit a task they did not create', function () {
    $pdo = _rpPdo();
    $members = new \App\Repository\ProjectMemberRepository($pdo);
    $task = ['id' => 1, 'project_id' => 100, 'created_by' => 11, 'assignee_id' => 12];
    assert_true(RolePolicy::canEditTask(['id' => 12, 'role' => 'employee'], $task, $members));
});

it('canEditTask: employee assigned to someone else still cannot edit', function () {
    $pdo = _rpPdo();
    $members = new \App\Repository\ProjectMemberRepository($pdo);
    $task = ['id' => 1, 'project_id' => 100, 'created_by' => 11, 'assignee_id' => 99];
    assert_true(!RolePolicy::canEditTask(['id' => 12, 'role' => 'employee'], $task, $members));
});

it('canEditTask: unassigned task gives no one extra rights', function () {
    $pdo = _rpPdo();
    $members = new \App\Repository\ProjectMemberRepository($pdo);
    $task = ['id' => 1, 'project_id' => 100, 'created_by' => 11, 'assignee_id' => null];
    assert_true(!RolePolicy::canEditTask(['id' => 12, 'role' => 'employee'], $task, $members));
});
```

Третий тест важен: `null` приводится к `0`, и если написать проверку небрежно, незаасайненная задача откроется всем.

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `make unit`
Expected: FAIL на `canEditTask: assignee can edit a task they did not create`. Два других теста должны **пройти** уже сейчас — они закрепляют текущее корректное поведение.

- [ ] **Step 3: Реализовать**

В `system/Service/RolePolicy.php` заменить тело `canEditTask`:

```php
    /** Can fully edit/delete a task (title, description, links, delete). */
    public static function canEditTask(array $user, array $task, ProjectMemberRepository $members): bool {
        if (self::isAdmin($user)) return true;
        $userId = (int)$user['id'];
        $isAuthor = (int)($task['created_by'] ?? 0) === $userId;
        if ($isAuthor) return true;
        // The assignee owns execution of their own task: they may move it
        // between columns and update its fields. Without this an employee
        // cannot progress work a manager handed them — which also blocks
        // API service accounts (see docs/API.md §2.1). Guard against the
        // null→0 cast: an unassigned task must not grant anyone rights.
        $assigneeId = (int)($task['assignee_id'] ?? 0);
        if ($assigneeId !== 0 && $assigneeId === $userId) return true;
        // manager can edit any task within projects they own
        if (self::isManager($user)) {
            return $members->isOwner((int)$task['project_id'], $userId);
        }
        return false;
    }
```

- [ ] **Step 4: Убедиться, что unit-тесты проходят**

Run: `make unit`
Expected: PASS, включая четыре существовавших теста `canEditTask` (они не задают `assignee_id`, поэтому поведение для них не меняется).

- [ ] **Step 5: Написать падающий API-тест регрессии**

Дописать в конец `tests/api/test_tasks.php`:

```php
api_it('PATCH /tasks/{id}: assignee employee may update a manager-created task', function () {
    [$pdo, , $empTok] = tasks_setup();
    tasks_seed_project($pdo, 5310, 1);
    tasks_seed_column($pdo, 53101, 5310, 'To Do', 0);
    // user 2 is an employee, a member of the project, assigned to the task,
    // but NOT its author — the exact shape of a bot service account.
    $pdo->prepare("INSERT OR IGNORE INTO project_members (project_id, user_id, role) VALUES (?, ?, 'member')")
        ->execute([5310, 2]);
    tasks_seed_task($pdo, 5320, 5310, 53101, 1, 2);

    $r = api_request('PATCH', '/api/v1/tasks/5320', [
        'headers' => ['Authorization: Bearer ' . $empTok],
        'body'    => json_encode(['priority' => 'high']),
    ]);
    assert_eq(200, $r['status']);
    assert_eq('high', $r['json']['priority']);
});

api_it('PATCH /tasks/{id}: non-assignee employee still gets 403', function () {
    [$pdo, , $empTok] = tasks_setup();
    tasks_seed_project($pdo, 5330, 1);
    tasks_seed_column($pdo, 53301, 5330, 'To Do', 0);
    $pdo->prepare("INSERT OR IGNORE INTO project_members (project_id, user_id, role) VALUES (?, ?, 'member')")
        ->execute([5330, 2]);
    // Assigned to user 1, not to the caller.
    tasks_seed_task($pdo, 5340, 5330, 53301, 1, 1);

    $r = api_request('PATCH', '/api/v1/tasks/5340', [
        'headers' => ['Authorization: Bearer ' . $empTok],
        'body'    => json_encode(['priority' => 'high']),
    ]);
    assert_eq(403, $r['status']);
});
```

- [ ] **Step 6: Прогнать API-тесты**

Run: `make api`
Expected: PASS оба новых теста плюс вся существующая сьюта.

- [ ] **Step 7: Коммит**

```bash
git add system/Service/RolePolicy.php tests/unit/test_role_policy.php tests/api/test_tasks.php
git commit -m "fix(policy): assignee may edit and move their own task"
```

---

### Task 2: Миграция `agent_state` и запись через репозиторий

§6.3 спеки. Своя колонка вместо `sub_status`, который принадлежит приложению и обнуляется при перемещении.

**Files:**
- Create: `system/Database/migrations/20260827_000_tasks_agent_state.php`
- Modify: `system/Repository/TaskRepository.php:217` (список `$allowed`)
- Test: `tests/unit/test_task_repo.php` (дописать в конец)

**Interfaces:**
- Consumes: ничего.
- Produces: колонки `tasks.agent_state` (string 32, nullable) и `tasks.agent_state_at` (timestamp, nullable); `TaskRepository::update()` принимает обе.

- [ ] **Step 1: Написать падающий тест**

Дописать в конец `tests/unit/test_task_repo.php`:

```php
it('update() persists agent_state and agent_state_at', function () {
    $pdo = \App\Database\Connection::open('sqlite::memory:');
    $base = dirname(__DIR__, 2) . '/system/Database/migrations/';
    apply_migration($pdo, $base . '20260522_000_users.php');
    apply_migration($pdo, $base . '20260522_010_projects.php');
    apply_migration($pdo, $base . '20260522_030_task_columns.php');
    apply_migration($pdo, $base . '20260522_040_tasks.php');
    apply_migration($pdo, $base . '20260526_020_tasks_sub_status.php');
    apply_migration($pdo, $base . '20260526_030_tasks_priority.php');
    apply_migration($pdo, $base . '20260827_000_tasks_agent_state.php');

    $pdo->exec("INSERT INTO users (id, name, email, password_hash, role, status, created_at) VALUES (1,'U','u@x','x','admin','approved','2026-01-01 00:00:00')");
    $pdo->exec("INSERT INTO projects (id, name, slug, status, created_by, created_at, updated_at) VALUES (1,'P','p','active',1,'2026-01-01 00:00:00','2026-01-01 00:00:00')");
    $pdo->exec("INSERT INTO task_columns (id, project_id, name, position, is_done, is_backlog) VALUES (1,1,'To Do',0,0,0)");
    $pdo->exec("INSERT INTO tasks (id, project_id, column_id, title, position, created_by, created_at, updated_at) VALUES (1,1,1,'T',1024,1,'2026-01-01 00:00:00','2026-01-01 00:00:00')");

    $repo = new \App\Repository\TaskRepository($pdo);
    $repo->update(1, ['agent_state' => 'researching', 'agent_state_at' => '2026-08-27 10:00:00']);

    $row = $pdo->query('SELECT agent_state, agent_state_at FROM tasks WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
    assert_eq('researching', $row['agent_state']);
    assert_eq('2026-08-27 10:00:00', $row['agent_state_at']);
});

it('update() ignores unknown columns', function () {
    // Guards the $allowed allowlist: a typo'd field must not reach SQL.
    $pdo = \App\Database\Connection::open('sqlite::memory:');
    $base = dirname(__DIR__, 2) . '/system/Database/migrations/';
    apply_migration($pdo, $base . '20260522_000_users.php');
    apply_migration($pdo, $base . '20260522_010_projects.php');
    apply_migration($pdo, $base . '20260522_030_task_columns.php');
    apply_migration($pdo, $base . '20260522_040_tasks.php');
    apply_migration($pdo, $base . '20260827_000_tasks_agent_state.php');
    $pdo->exec("INSERT INTO users (id, name, email, password_hash, role, status, created_at) VALUES (1,'U','u@x','x','admin','approved','2026-01-01 00:00:00')");
    $pdo->exec("INSERT INTO projects (id, name, slug, status, created_by, created_at, updated_at) VALUES (1,'P','p','active',1,'2026-01-01 00:00:00','2026-01-01 00:00:00')");
    $pdo->exec("INSERT INTO task_columns (id, project_id, name, position, is_done, is_backlog) VALUES (1,1,'To Do',0,0,0)");
    $pdo->exec("INSERT INTO tasks (id, project_id, column_id, title, position, created_by, created_at, updated_at) VALUES (1,1,1,'T',1024,1,'2026-01-01 00:00:00','2026-01-01 00:00:00')");

    $repo = new \App\Repository\TaskRepository($pdo);
    $repo->update(1, ['agent_stat' => 'typo']);   // no exception, no write
    $row = $pdo->query('SELECT agent_state FROM tasks WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
    assert_eq(null, $row['agent_state']);
});
```

- [ ] **Step 2: Убедиться, что тест падает**

Run: `make unit`
Expected: FAIL — файла миграции нет, `apply_migration` бросит на `require`.

- [ ] **Step 3: Создать миграцию**

`system/Database/migrations/20260827_000_tasks_agent_state.php`:

```php
<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

// Agent execution phase, owned by the external agent bridge. Deliberately
// NOT reusing `sub_status`: that column belongs to the app (reopened /
// returned) and TaskRepository::move() clears it, which would wipe the
// agent's phase exactly when the agent moves the card.
return function (Schema $schema): void {
    $schema->alterTable('tasks', function (Blueprint $t) {
        $t->string('agent_state', 32)->nullable();
        $t->timestamp('agent_state_at')->nullable();
    });
};
```

Форма повторяет `20260602_000_forms_project_attach.php` — несколько колонок одним `alterTable`, без ручного guard: повторное применение исключено журналом миграций.

- [ ] **Step 4: Разрешить запись через репозиторий**

В `system/Repository/TaskRepository.php:217` заменить строку `$allowed`:

```php
        $allowed = ['title', 'description', 'column_id', 'position', 'assignee_id', 'due_date', 'priority', 'sub_status', 'agent_state', 'agent_state_at'];
```

- [ ] **Step 5: Прогнать тесты**

Run: `make unit`
Expected: PASS.

- [ ] **Step 6: Применить миграцию локально**

Run: `make migrate`
Expected: миграция `20260827_000_tasks_agent_state` применена, ошибок нет.

- [ ] **Step 7: Коммит**

```bash
git add system/Database/migrations/20260827_000_tasks_agent_state.php system/Repository/TaskRepository.php tests/unit/test_task_repo.php
git commit -m "feat(db): tasks.agent_state + agent_state_at for the agent bridge"
```

---

### Task 3: `agent_state` в API

**Files:**
- Modify: `system/Api/V1/Handlers/TasksHandler.php:334-352` (`serializeListItem`), `:120-176` (`update`), `:24-55` (`indexForProject`)
- Test: `tests/api/test_tasks.php`

**Interfaces:**
- Consumes: колонки из Task 2.
- Produces: поле `agent_state` в `TaskListItem` и `Task`; `PATCH /tasks/{id}` принимает `agent_state`; `GET /projects/{id}/tasks?agent_state=` фильтрует.

- [ ] **Step 1: Написать падающие API-тесты**

Дописать в конец `tests/api/test_tasks.php`:

```php
api_it('PATCH /tasks/{id}: sets agent_state and stamps agent_state_at', function () {
    [$pdo, $adminTok,] = tasks_setup();
    tasks_seed_project($pdo, 5400, 1);
    tasks_seed_column($pdo, 54001, 5400, 'To Do', 0);
    tasks_seed_task($pdo, 5410, 5400, 54001, 1);

    $r = api_request('PATCH', '/api/v1/tasks/5410', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['agent_state' => 'researching']),
    ]);
    assert_eq(200, $r['status']);
    assert_eq('researching', $r['json']['agent_state']);

    $row = $pdo->query('SELECT agent_state_at FROM tasks WHERE id = 5410')->fetch(\PDO::FETCH_ASSOC);
    assert_true(!empty($row['agent_state_at']), 'agent_state_at must be stamped automatically');
});

api_it('PATCH /tasks/{id}: rejects an unknown agent_state', function () {
    [$pdo, $adminTok,] = tasks_setup();
    tasks_seed_project($pdo, 5420, 1);
    tasks_seed_column($pdo, 54201, 5420, 'To Do', 0);
    tasks_seed_task($pdo, 5430, 5420, 54201, 1);

    $r = api_request('PATCH', '/api/v1/tasks/5430', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['agent_state' => 'nonsense']),
    ]);
    assert_eq(422, $r['status']);
    assert_eq('validation_failed', $r['json']['error']);
});

api_it('PATCH /tasks/{id}: agent_state null clears the phase', function () {
    [$pdo, $adminTok,] = tasks_setup();
    tasks_seed_project($pdo, 5440, 1);
    tasks_seed_column($pdo, 54401, 5440, 'To Do', 0);
    tasks_seed_task($pdo, 5450, 5440, 54401, 1);
    $pdo->exec("UPDATE tasks SET agent_state = 'review' WHERE id = 5450");

    $r = api_request('PATCH', '/api/v1/tasks/5450', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['agent_state' => null]),
    ]);
    assert_eq(200, $r['status']);
    assert_eq(null, $r['json']['agent_state']);
});

api_it('GET /projects/{id}/tasks?agent_state= filters by phase', function () {
    [$pdo, $adminTok,] = tasks_setup();
    tasks_seed_project($pdo, 5460, 1);
    tasks_seed_column($pdo, 54601, 5460, 'To Do', 0);
    tasks_seed_task($pdo, 5470, 5460, 54601, 1);
    tasks_seed_task($pdo, 5471, 5460, 54601, 1);
    $pdo->exec("UPDATE tasks SET agent_state = 'implementing' WHERE id = 5470");

    $r = api_request('GET', '/api/v1/projects/5460/tasks?agent_state=implementing', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $r['status']);
    assert_eq(1, count($r['json']['items']));
    assert_eq(5470, (int)$r['json']['items'][0]['id']);
});
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `make api`
Expected: FAIL — поля `agent_state` в ответе нет.

- [ ] **Step 3: Добавить константу допустимых фаз**

В `system/Api/V1/Handlers/TasksHandler.php`, сразу после `final class TasksHandler extends BaseHandler {`:

```php
    /**
     * Phases owned by the external agent bridge. Kept as a closed set so a
     * typo in an automated client surfaces as 422 instead of silently
     * parking a task in a state nothing will ever pick up.
     */
    private const AGENT_STATES = ['researching', 'awaiting_approval', 'implementing', 'review', 'blocked'];
```

- [ ] **Step 4: Отдавать поле в сериализаторе**

В `serializeListItem()` (строка ~344) добавить после `'sub_status'`:

```php
            'agent_state' => $t['agent_state'] ?? null,
```

Поле попадает и в `serializeOne()`, потому что тот строится поверх `serializeListItem()`.

- [ ] **Step 5: Принимать поле в `update()`**

В `update()`, после блока `sub_status` (строка ~170), добавить:

```php
        if (array_key_exists('agent_state', $body)) {
            $s = $body['agent_state'];
            if ($s === null || $s === '') {
                $fields['agent_state']    = null;
                $fields['agent_state_at'] = null;
            } elseif (in_array((string)$s, self::AGENT_STATES, true)) {
                $fields['agent_state']    = (string)$s;
                // Stamped server-side: clients must not be able to backdate a
                // phase, since staleness detection keys on this value.
                $fields['agent_state_at'] = iso_now_utc();
            } else {
                return ApiResponse::error(422, 'validation_failed', 'unknown agent_state', ['agent_state' => 'invalid']);
            }
        }
```

- [ ] **Step 6: Добавить фильтр в список проекта**

В `TasksHandler::indexForProject()` строка 40 — расширить список строковых фильтров:

```php
        foreach (['status', 'priority', 'search', 'agent_state'] as $k) {
```

Одного этого мало: `TaskRepository::listForProjectAfterId()` собирает `WHERE` из явных `if`-блоков, а не по allowlist, поэтому неизвестный ключ фильтра молча игнорируется. Добавить в него блок после фильтра `priority` (строка ~157):

```php
        if (isset($filters['agent_state']) && $filters['agent_state'] !== '') {
            $where[] = 't.agent_state = ?';
            $args[] = (string)$filters['agent_state'];
        }
```

- [ ] **Step 7: Прогнать тесты**

Run: `make api`
Expected: PASS все четыре новых теста.

- [ ] **Step 8: Коммит**

```bash
git add system/Api/V1/Handlers/TasksHandler.php system/Repository/TaskRepository.php tests/api/test_tasks.php
git commit -m "feat(api): expose and validate agent_state on tasks"
```

---

### Task 4: Кросс-проектный `GET /api/v1/tasks`

§6.2 спеки. Без него бот делает запрос на каждый проект и упирается в 60/мин.

**Files:**
- Modify: `system/Api/V1/ApiKernel.php:71` (регистрация роута)
- Modify: `system/Api/V1/Handlers/TasksHandler.php` (новый метод `index`)
- Modify: `system/Repository/TaskRepository.php` (новый метод `listAssignedAfterId`)
- Test: `tests/api/test_tasks.php`

**Interfaces:**
- Consumes: `agent_state` из Task 3.
- Produces: `TaskRepository::listAssignedAfterId(int $assigneeId, ?array $projectIds, int $after, int $limit, ?string $updatedAfter, ?string $agentState): array` — возвращает строки `tasks`, отсортированные по `id ASC`. `$projectIds === null` означает «без ограничения видимости» (админ).
- Produces: `GET /api/v1/tasks` → `TasksHandler::index()`, формат ответа `PaginatedTasks` (как у `indexForProject`).

- [ ] **Step 1: Написать падающие API-тесты**

Дописать в конец `tests/api/test_tasks.php`:

```php
api_it('GET /tasks: returns only tasks assigned to the caller, across projects', function () {
    [$pdo, , $empTok] = tasks_setup();
    tasks_seed_project($pdo, 5500, 1);
    tasks_seed_project($pdo, 5501, 1);
    tasks_seed_column($pdo, 55001, 5500, 'To Do', 0);
    tasks_seed_column($pdo, 55011, 5501, 'To Do', 0);
    $pdo->prepare("INSERT OR IGNORE INTO project_members (project_id, user_id, role) VALUES (?, 2, 'member')")->execute([5500]);
    $pdo->prepare("INSERT OR IGNORE INTO project_members (project_id, user_id, role) VALUES (?, 2, 'member')")->execute([5501]);
    tasks_seed_task($pdo, 5510, 5500, 55001, 1, 2);   // assigned to caller
    tasks_seed_task($pdo, 5511, 5501, 55011, 1, 2);   // assigned to caller, other project
    tasks_seed_task($pdo, 5512, 5500, 55001, 1, 1);   // assigned to someone else

    $r = api_request('GET', '/api/v1/tasks', [
        'headers' => ['Authorization: Bearer ' . $empTok],
    ]);
    assert_eq(200, $r['status']);
    $ids = array_map('intval', array_column($r['json']['items'], 'id'));
    assert_true(in_array(5510, $ids, true), '5510 must be present');
    assert_true(in_array(5511, $ids, true), '5511 must be present');
    assert_true(!in_array(5512, $ids, true), '5512 belongs to another assignee');
});

api_it('GET /tasks: never leaks tasks from projects the caller is not in', function () {
    [$pdo, , $empTok] = tasks_setup();
    tasks_seed_project($pdo, 5520, 1);
    tasks_seed_column($pdo, 55201, 5520, 'To Do', 0);
    // Assigned to the caller, but the caller is NOT a member of the project.
    tasks_seed_task($pdo, 5530, 5520, 55201, 1, 2);

    $r = api_request('GET', '/api/v1/tasks', [
        'headers' => ['Authorization: Bearer ' . $empTok],
    ]);
    assert_eq(200, $r['status']);
    $ids = array_map('intval', array_column($r['json']['items'], 'id'));
    assert_true(!in_array(5530, $ids, true), 'membership must gate visibility');
});

api_it('GET /tasks?agent_state= filters across projects', function () {
    [$pdo, , $empTok] = tasks_setup();
    tasks_seed_project($pdo, 5540, 1);
    tasks_seed_column($pdo, 55401, 5540, 'To Do', 0);
    $pdo->prepare("INSERT OR IGNORE INTO project_members (project_id, user_id, role) VALUES (?, 2, 'member')")->execute([5540]);
    tasks_seed_task($pdo, 5550, 5540, 55401, 1, 2);
    tasks_seed_task($pdo, 5551, 5540, 55401, 1, 2);
    $pdo->exec("UPDATE tasks SET agent_state = 'awaiting_approval' WHERE id = 5550");

    $r = api_request('GET', '/api/v1/tasks?agent_state=awaiting_approval', [
        'headers' => ['Authorization: Bearer ' . $empTok],
    ]);
    assert_eq(200, $r['status']);
    assert_eq(1, count($r['json']['items']));
    assert_eq(5550, (int)$r['json']['items'][0]['id']);
});

api_it('GET /tasks requires a token', function () {
    $r = api_request('GET', '/api/v1/tasks');
    assert_eq(401, $r['status']);
});
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `make api`
Expected: FAIL с 404 — роут не зарегистрирован.

- [ ] **Step 3: Метод репозитория**

В `system/Repository/TaskRepository.php` добавить:

```php
    /**
     * Cursor-paginated tasks assigned to one user, optionally constrained to
     * a set of visible project ids. $projectIds === null means "no visibility
     * constraint" (admin); an empty array means "sees nothing" and short-
     * circuits, because `IN ()` is a syntax error in both drivers.
     *
     * @param  list<int>|null $projectIds
     * @return list<array<string,mixed>>
     */
    public function listAssignedAfterId(
        int $assigneeId,
        ?array $projectIds,
        int $after,
        int $limit,
        ?string $updatedAfter = null,
        ?string $agentState = null
    ): array {
        if ($projectIds !== null && $projectIds === []) return [];

        $sql  = 'SELECT * FROM tasks WHERE assignee_id = ? AND id > ?';
        $vals = [$assigneeId, $after];

        if ($projectIds !== null) {
            $sql .= ' AND project_id IN (' . implode(',', array_fill(0, count($projectIds), '?')) . ')';
            foreach ($projectIds as $pid) $vals[] = (int)$pid;
        }
        if ($updatedAfter !== null && $updatedAfter !== '') {
            $sql .= ' AND updated_at > ?';
            $vals[] = $updatedAfter;
        }
        if ($agentState !== null && $agentState !== '') {
            $sql .= ' AND agent_state = ?';
            $vals[] = $agentState;
        }
        $sql .= ' ORDER BY id ASC LIMIT ?';
        $vals[] = $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($vals);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
```

`LIMIT` биндится параметром — ровно так же, как в соседнем `listForProjectAfterId()`. Не отклоняться от этого: смешение стилей в одном репозитории дороже мнимой выгоды.

- [ ] **Step 4: Хендлер**

В `system/Api/V1/Handlers/TasksHandler.php` добавить метод `index()` перед `indexForProject()`:

```php
    /**
     * Cross-project task list. Defaults to the caller as assignee — the
     * common case is a service account asking "what is mine?".
     */
    public function index(Request $req, array $params = []): array
    {
        $after = isset($req->query['after']) ? (int)$req->query['after'] : 0;
        $limit = min(100, max(1, (int)($req->query['limit'] ?? 50)));
        $assigneeId = isset($req->query['assignee_id'])
            ? (int)$req->query['assignee_id']
            : $this->userId();

        $agentState   = isset($req->query['agent_state']) ? (string)$req->query['agent_state'] : null;
        $updatedAfter = isset($req->query['updated_after']) ? (string)$req->query['updated_after'] : null;

        // Visibility: admins see everything, everyone else is bounded by
        // project membership — the only scoping lever tokens have (docs/API.md §2.1).
        $projectIds = null;
        if (!RolePolicy::isAdmin($this->user())) {
            $visible = $this->svc('projects')->listForUser($this->userId(), false);
            $projectIds = array_values(array_unique(array_map(fn($p) => (int)$p['id'], $visible)));
        }

        $rows = $this->svc('tasks')->listAssignedAfterId(
            $assigneeId, $projectIds, $after, $limit + 1, $updatedAfter, $agentState
        );

        $next = null;
        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
            $next = (int)end($rows)['id'];
        }
        return ApiResponse::paginated(array_map(fn($t) => $this->serializeListItem($t), $rows), $next);
    }
```

- [ ] **Step 5: Зарегистрировать роут**

В `system/Api/V1/ApiKernel.php`, рядом с остальными задачами (после строки 71):

```php
        $this->routes['GET /api/v1/tasks'] = ['handler' => 'Tasks', 'action' => 'index'];
```

Порядок значения не имеет: `/api/v1/tasks` и `/api/v1/tasks/{id}` компилируются в разные якорные регулярки.

- [ ] **Step 6: Прогнать тесты**

Run: `make api`
Expected: PASS все четыре теста, включая проверку на утечку из чужого проекта.

- [ ] **Step 7: Коммит**

```bash
git add system/Api/V1/ApiKernel.php system/Api/V1/Handlers/TasksHandler.php system/Repository/TaskRepository.php tests/api/test_tasks.php
git commit -m "feat(api): cross-project GET /tasks for assignees"
```

---

### Task 5: Три мелкие дыры API

§6.4 спеки: описание проекта не читается; бот не знает, какая колонка «Готово»; нельзя отличить задачу, порождённую публичной формой.

**Files:**
- Modify: `system/Api/V1/Handlers/ProjectsHandler.php:175-202` (`serializeMany`, `serializeOne`)
- Modify: `system/Api/V1/Handlers/TasksHandler.php` (`serializeOne`, `countFor` allowlist)
- Test: `tests/api/test_projects_read.php`, `tests/api/test_tasks.php`

**Interfaces:**
- Consumes: ничего.
- Produces: поле `description` в `Project`; `is_done` и `is_backlog` в `ProjectDetail.columns[]`; булево `from_form` в `Task`.

- [ ] **Step 1: Написать падающие тесты**

В `tests/api/test_projects_read.php` дописать. Общего setup-хелпера в этом файле нет — каждый тест поднимает фикстуры сам, повторяем этот стиль:

```php
api_it('GET /projects/{id}: exposes description and column flags', function () {
    api_request('GET', '/api/v1/ping');   // warm-up so migrations run
    $pdo = \App\Database\Connection::open(dirname(__DIR__, 2) . '/data/app.api-test.sqlite');
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (1, 'U', 'u@x', 'x', 'admin', 'approved', '2026-01-01')");
    $repo = new \App\Repository\ApiTokenRepository($pdo);
    $tok = $repo->create(1, 'proj-agent-' . bin2hex(random_bytes(3)))['token'];

    $pdo->exec("INSERT OR IGNORE INTO projects (id, name, slug, description, color, status, created_by, created_at, updated_at) VALUES (5600, 'D', 'p-desc', 'Project brief text', '#fff', 'active', 1, '2026-01-01', '2026-01-01')");
    $pdo->exec("INSERT OR IGNORE INTO task_columns (id, project_id, name, color, position, is_done, is_backlog) VALUES (56001, 5600, 'Done', '#fff', 2, 1, 0)");

    $r = api_request('GET', '/api/v1/projects/5600', [
        'headers' => ['Authorization: Bearer ' . $tok],
    ]);
    assert_eq(200, $r['status']);
    assert_eq('Project brief text', $r['json']['description']);
    assert_eq(true, $r['json']['columns'][0]['is_done']);
    assert_eq(false, $r['json']['columns'][0]['is_backlog']);
});
```

В `tests/api/test_tasks.php` дописать:

```php
api_it('GET /tasks/{id}: from_form marks tasks born from a public submission', function () {
    [$pdo, $adminTok,] = tasks_setup();
    tasks_seed_project($pdo, 5700, 1);
    tasks_seed_column($pdo, 57001, 5700, 'To Do', 0);
    tasks_seed_task($pdo, 5710, 5700, 57001, 1);
    tasks_seed_task($pdo, 5711, 5700, 57001, 1);
    $pdo->exec("INSERT OR IGNORE INTO forms (id, hash, title, status, locale, created_by, created_at, updated_at) VALUES (5790, 'h5790', 'F', 'published', 'en', 1, '2026-01-01', '2026-01-01')");
    $pdo->exec("INSERT INTO form_submissions (form_id, status, data_json, converted_task_id, created_at, updated_at) VALUES (5790, 'new', '{}', 5711, '2026-01-01', '2026-01-01')");

    $plain = api_request('GET', '/api/v1/tasks/5710', ['headers' => ['Authorization: Bearer ' . $adminTok]]);
    $born  = api_request('GET', '/api/v1/tasks/5711', ['headers' => ['Authorization: Bearer ' . $adminTok]]);
    assert_eq(false, $plain['json']['from_form']);
    assert_eq(true,  $born['json']['from_form']);
});
```

Колонка полезной нагрузки называется `data_json`, а не `answers` — API отдаёт её наружу под именем `answers`, но в схеме (`20260529_030_form_submissions.php`) она `data_json`. Лёгкая ловушка: писать фикстуру по форме API-ответа нельзя.

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `make api`
Expected: FAIL — полей нет в ответе.

- [ ] **Step 3: Описание проекта**

В `ProjectsHandler::serializeMany()` добавить после `'name'`:

```php
            'description' => $p['description'] ?? null,
```

Замечание для потребителей: описание редактируется через Quill и хранится как санитизированный HTML, а не как plain text.

- [ ] **Step 4: Флаги колонок**

В `ProjectsHandler::serializeOne()` заменить маппинг `columns`:

```php
        $base['columns'] = array_map(fn($c) => [
            'id'         => (int)$c['id'],
            'name'       => $c['name'],
            'position'   => (int)$c['position'],
            'is_done'    => !empty($c['is_done']),
            'is_backlog' => !empty($c['is_backlog']),
        ], $columns);
```

- [ ] **Step 5: Признак происхождения из формы**

В `TasksHandler::serializeOne()` перед `return $base;`:

```php
        // Tasks auto-created from a public form submission carry text written
        // by anonymous internet users. The agent bridge refuses to act on them
        // until a human re-assigns the task (see the agent-bridge spec §10).
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM form_submissions WHERE converted_task_id = ?');
        $stmt->execute([$id]);
        $base['from_form'] = (int)$stmt->fetchColumn() > 0;
```

- [ ] **Step 6: Прогнать тесты**

Run: `make api`
Expected: PASS.

- [ ] **Step 7: Коммит**

```bash
git add system/Api/V1/Handlers/ProjectsHandler.php system/Api/V1/Handlers/TasksHandler.php tests/api/test_projects_read.php tests/api/test_tasks.php
git commit -m "feat(api): project description, column flags, from_form marker"
```

---

### Task 6: Поля проекта под связку с кодом

§6.5 спеки.

**Files:**
- Create: `system/Database/migrations/20260827_010_projects_agent_fields.php`
- Modify: `system/Repository/ProjectRepository.php:47` (`$allowed`)
- Modify: `system/Api/V1/Handlers/ProjectsHandler.php` (`serializeMany`, `update`)
- Test: `tests/api/test_projects_write.php`

**Interfaces:**
- Consumes: ничего.
- Produces: колонки `projects.repo_url`, `default_branch`, `dev_branch`, `dev_url`, `agent_instructions`; те же имена — в JSON проекта и в теле `PATCH /projects/{id}`.

- [ ] **Step 1: Написать падающий тест**

В `tests/api/test_projects_write.php` дописать:

Файл уже несёт свои хелперы: `pw_setup()` возвращает **четыре** элемента — `[$pdo, $repo, $adminTok, $employeeTok]` (обратите внимание: репозиторий вторым, в отличие от `tasks_setup()`), а `pw_seed_project($pdo, $id, $name, $createdBy)` создаёт проект со слагом `pw-<id>`.

```php
api_it('PATCH /projects/{id}: round-trips agent bridge fields', function () {
    [$pdo, , $adminTok, ] = pw_setup();
    pw_seed_project($pdo, 5800, 'A');

    $r = api_request('PATCH', '/api/v1/projects/5800', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode([
            'repo_url'           => 'git@github.com:otack/demo.git',
            'default_branch'     => 'main',
            'dev_branch'         => 'develop',
            'dev_url'            => 'https://demo.dev.example.com',
            'agent_instructions' => "Run make test before pushing.\nNever touch payments.",
        ]),
    ]);
    assert_eq(200, $r['status']);
    assert_eq('git@github.com:otack/demo.git', $r['json']['repo_url']);
    assert_eq('develop', $r['json']['dev_branch']);
    assert_true(str_contains($r['json']['agent_instructions'], 'Never touch payments'));
});
```

- [ ] **Step 2: Убедиться, что тест падает**

Run: `make api`
Expected: FAIL — полей нет.

- [ ] **Step 3: Миграция**

`system/Database/migrations/20260827_010_projects_agent_fields.php`:

```php
<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

// Ties a project to the code it produces, for the external agent bridge.
// The *local filesystem path* is deliberately NOT stored here: it lives in
// the operator's machine-local config, so editing a field in the web UI can
// never redirect an automated worker at an arbitrary directory.
return function (Schema $schema): void {
    $schema->alterTable('projects', function (Blueprint $t) {
        $t->string('repo_url', 500)->nullable();
        $t->string('default_branch', 100)->nullable();
        $t->string('dev_branch', 100)->nullable();
        $t->string('dev_url', 500)->nullable();
        $t->text('agent_instructions')->nullable();
    });
};
```

- [ ] **Step 4: Разрешить запись в репозитории**

`system/Repository/ProjectRepository.php:47`:

```php
        $allowed = ['name', 'description', 'status', 'color', 'repo_url', 'default_branch', 'dev_branch', 'dev_url', 'agent_instructions'];
```

- [ ] **Step 5: Отдавать и принимать через API**

В `ProjectsHandler::serializeMany()` добавить после `'description'`:

```php
            'repo_url'           => $p['repo_url'] ?? null,
            'default_branch'     => $p['default_branch'] ?? null,
            'dev_branch'         => $p['dev_branch'] ?? null,
            'dev_url'            => $p['dev_url'] ?? null,
            'agent_instructions' => $p['agent_instructions'] ?? null,
```

В `ProjectsHandler::update()` расширить список полей:

```php
        foreach (['name', 'color', 'description', 'status', 'repo_url', 'default_branch', 'dev_branch', 'dev_url', 'agent_instructions'] as $f) {
```

- [ ] **Step 6: Применить миграцию и прогнать тесты**

Run: `make migrate && make api`
Expected: PASS.

- [ ] **Step 7: Коммит**

```bash
git add system/Database/migrations/20260827_010_projects_agent_fields.php system/Repository/ProjectRepository.php system/Api/V1/Handlers/ProjectsHandler.php tests/api/test_projects_write.php
git commit -m "feat(projects): repo/branch/dev-url/agent-instructions fields"
```

---

### Task 7: Поля проекта в веб-форме

**Files:**
- Modify: `views/projects/form.php` (после блока описания, перед кнопкой submit)
- Modify: `system/Controller/ProjectController.php:241` (сбор `$fields` в update)
- Modify: `system/i18n/en.php`, `system/i18n/uk.php`, `system/i18n/pl.php`

**Interfaces:**
- Consumes: колонки из Task 6.
- Produces: ключи i18n `projects.form.repo_url`, `projects.form.default_branch`, `projects.form.dev_branch`, `projects.form.dev_url`, `projects.form.agent_instructions`, `projects.form.agent_hint`.

- [ ] **Step 1: Добавить ключи во все три каталога**

В `system/i18n/en.php` рядом с существующими `projects.form.*`:

```php
    'projects.form.repo_url'           => 'Repository URL',
    'projects.form.default_branch'     => 'Default branch',
    'projects.form.dev_branch'         => 'Dev branch',
    'projects.form.dev_url'            => 'Dev environment URL',
    'projects.form.agent_instructions' => 'Instructions for the agent',
    'projects.form.agent_hint'         => 'Stack, how to run tests, conventions, what not to touch.',
```

`system/i18n/uk.php`:

```php
    'projects.form.repo_url'           => 'URL репозиторію',
    'projects.form.default_branch'     => 'Основна гілка',
    'projects.form.dev_branch'         => 'Гілка розробки',
    'projects.form.dev_url'            => 'URL dev-середовища',
    'projects.form.agent_instructions' => 'Інструкції для агента',
    'projects.form.agent_hint'         => 'Стек, як запускати тести, домовленості, чого не чіпати.',
```

`system/i18n/pl.php`:

```php
    'projects.form.repo_url'           => 'URL repozytorium',
    'projects.form.default_branch'     => 'Gałąź główna',
    'projects.form.dev_branch'         => 'Gałąź deweloperska',
    'projects.form.dev_url'            => 'URL środowiska dev',
    'projects.form.agent_instructions' => 'Instrukcje dla agenta',
    'projects.form.agent_hint'         => 'Stos, jak uruchamiać testy, konwencje, czego nie ruszać.',
```

- [ ] **Step 2: Проверить паритет**

Run: `make unit`
Expected: PASS `test_i18n.php` — ни один каталог не отстаёт.

- [ ] **Step 3: Добавить поля в форму**

В `views/projects/form.php` перед `<button class="submit mt-18" ...>`:

```php
  <div class="field mt-14">
    <label for="f-project-repo-url"><?= e(t('projects.form.repo_url')) ?></label>
    <input id="f-project-repo-url" class="input" type="text" name="repo_url"
           value="<?= e($project['repo_url'] ?? '') ?>" maxlength="500"
           placeholder="git@github.com:org/repo.git">
  </div>
  <div class="field mt-14">
    <label for="f-project-default-branch"><?= e(t('projects.form.default_branch')) ?></label>
    <input id="f-project-default-branch" class="input" type="text" name="default_branch"
           value="<?= e($project['default_branch'] ?? '') ?>" maxlength="100" placeholder="main">
  </div>
  <div class="field mt-14">
    <label for="f-project-dev-branch"><?= e(t('projects.form.dev_branch')) ?></label>
    <input id="f-project-dev-branch" class="input" type="text" name="dev_branch"
           value="<?= e($project['dev_branch'] ?? '') ?>" maxlength="100" placeholder="develop">
  </div>
  <div class="field mt-14">
    <label for="f-project-dev-url"><?= e(t('projects.form.dev_url')) ?></label>
    <input id="f-project-dev-url" class="input" type="text" name="dev_url"
           value="<?= e($project['dev_url'] ?? '') ?>" maxlength="500"
           placeholder="https://dev.example.com">
  </div>
  <div class="field mt-14">
    <label for="f-project-agent-instructions"><?= e(t('projects.form.agent_instructions')) ?></label>
    <textarea id="f-project-agent-instructions" class="input" name="agent_instructions"
              rows="6"><?= e($project['agent_instructions'] ?? '') ?></textarea>
    <div class="field__hint"><?= e(t('projects.form.agent_hint')) ?></div>
  </div>
```

Намеренно **обычная `<textarea>`, а не Quill**: инструкции читает машина, и HTML-разметка от WYSIWYG только мешала бы. Значение экранируется через `e()`, поэтому XSS-поверхности нет.

- [ ] **Step 4: Принять поля в контроллере**

В `system/Controller/ProjectController.php`, рядом со сбором `description` (строка ~241):

```php
        foreach (['repo_url', 'default_branch', 'dev_branch', 'dev_url', 'agent_instructions'] as $f) {
            if (!array_key_exists($f, $data)) continue;
            $v = trim((string)$data[$f]);
            $fields[$f] = $v === '' ? null : $v;
        }
```

Санитайзер здесь не нужен: поля не рендерятся как HTML, а выводятся через `e()`.

- [ ] **Step 5: Проверить руками**

Run: `make dev`, открыть `/projects/<id>/edit`, заполнить поля, сохранить, перезагрузить.
Expected: значения сохранились и вернулись в форму; `GET /api/v1/projects/<id>` отдаёт их же.

- [ ] **Step 6: Коммит**

```bash
git add views/projects/form.php system/Controller/ProjectController.php system/i18n/en.php system/i18n/uk.php system/i18n/pl.php
git commit -m "feat(projects): agent bridge fields in the edit form"
```

---

### Task 8: Бейдж фазы агента

**Files:**
- Modify: `views/partials/kanban-card.php:16-29`
- Modify: `views/tasks/show.php:22-27`
- Modify: `public/assets/css/` (файл с правилами `.sub-status`)
- Modify: `system/i18n/en.php`, `uk.php`, `pl.php`

**Interfaces:**
- Consumes: `tasks.agent_state` из Task 2.
- Produces: ключи `tasks.agent_state.researching`, `.awaiting_approval`, `.implementing`, `.review`, `.blocked`.

- [ ] **Step 1: Ключи i18n во все три каталога**

`en.php` (рядом с `tasks.sub_status.*`):

```php
    'tasks.agent_state.researching'      => '🔍 Researching',
    'tasks.agent_state.awaiting_approval' => '⏸ Awaiting approval',
    'tasks.agent_state.implementing'     => '⚙ Implementing',
    'tasks.agent_state.review'           => '👀 On review',
    'tasks.agent_state.blocked'          => '⛔ Blocked',
```

`uk.php`:

```php
    'tasks.agent_state.researching'      => '🔍 Дослідження',
    'tasks.agent_state.awaiting_approval' => '⏸ Очікує підтвердження',
    'tasks.agent_state.implementing'     => '⚙ Реалізація',
    'tasks.agent_state.review'           => '👀 На рев’ю',
    'tasks.agent_state.blocked'          => '⛔ Заблоковано',
```

`pl.php`:

```php
    'tasks.agent_state.researching'      => '🔍 Badanie',
    'tasks.agent_state.awaiting_approval' => '⏸ Czeka na zatwierdzenie',
    'tasks.agent_state.implementing'     => '⚙ Wdrażanie',
    'tasks.agent_state.review'           => '👀 W przeglądzie',
    'tasks.agent_state.blocked'          => '⛔ Zablokowane',
```

- [ ] **Step 2: Проверить паритет**

Run: `make unit`
Expected: PASS `test_i18n.php`.

- [ ] **Step 3: Бейдж на канбан-карточке**

В `views/partials/kanban-card.php` заменить условие строки 16 и добавить блок после `sub_status`:

```php
  <?php if ($tags || !empty($t['sub_status']) || !empty($t['agent_state'])): ?>
```

и внутри `.kanban-card__row`, сразу после блока `sub_status`:

```php
      <?php if (!empty($t['agent_state'])): ?>
        <span class="agent-state agent-state--<?= e($t['agent_state']) ?>">
          <?= e(t('tasks.agent_state.' . $t['agent_state'])) ?>
        </span>
      <?php endif; ?>
```

Фолбэк вторым аргументом здесь **не передавать**: сигнатура `t(string $key, array $args = [])` — второй параметр это массив подстановок `:name`, строка туда вызовет TypeError. Неизвестный ключ `t()` и так возвращает сам себя, так что незнакомая фаза отрисуется как `tasks.agent_state.<что-то>` — заметно и не падает.

Данные до карточки доезжают сами: доска кормится из `TaskRepository::listForProject()`, а там `SELECT t.*`. Правка запроса не нужна.

- [ ] **Step 4: Бейдж на странице задачи**

В `views/tasks/show.php` блок `sub_status` — это `if/else` внутри `<div class="task-header">`: ветка `if` оборачивает бейдж в `<div class="task-sub-status-row">`, ветка `else` рендерит скрытую заглушку для JS. Вставлять **после `<?php endif; ?>` этого блока (строка ~29) и до `<h1 class="task-title …>`**:

```php
      <?php if (!empty($task['agent_state'])): ?>
        <div class="task-agent-state-row">
          <span class="agent-state agent-state--<?= e($task['agent_state']) ?>" data-task-agent-state>
            <?= e(t('tasks.agent_state.' . $task['agent_state'])) ?>
          </span>
        </div>
      <?php else: ?>
        <span class="agent-state" data-task-agent-state hidden></span>
      <?php endif; ?>
```

Скрытая заглушка в ветке `else` повторяет приём соседнего `sub_status`: она даёт фронтенду стабильную точку `[data-task-agent-state]`, которую можно наполнить без перезагрузки страницы.

- [ ] **Step 5: Стили**

Правила живут в `public/assets/css/kanban.css`, рядом с `.sub-status` (строки 416–427 и 581–583). Форма бейджа копирует существующую один в один — семантических токенов цвета в проекте нет, соседние бейджи используют литеральные hex через `color-mix`, и отступать от этого здесь не за чем.

Вставить после строки 583:

```css
/* Agent execution phase badge — same shape as .sub-status, different palette */
.agent-state {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font: 700 var(--fz-micro)/1 var(--font-mono);
  letter-spacing: var(--ls-mono-strong);
  text-transform: uppercase;
  padding: 3px 7px;
  border-radius: 999px;
  border: 1px solid currentColor;
}
.agent-state[hidden] { display: none !important; }

.agent-state--researching       { color: #1D4ED8; background: color-mix(in srgb, #BFDBFE 60%, transparent); }
.agent-state--awaiting_approval { color: #9A3412; background: color-mix(in srgb, #FED7AA 60%, transparent); }
.agent-state--implementing      { color: #0F766E; background: color-mix(in srgb, #99F6E4 60%, transparent); }
.agent-state--review            { color: #4338CA; background: color-mix(in srgb, #C7D2FE 60%, transparent); }
.agent-state--blocked           { color: #B91C1C; background: color-mix(in srgb, #FECACA 60%, transparent); }

.task-header .agent-state { font-size: 11px; padding: 4px 10px; }
```

Цвета намеренно не пересекаются с `.sub-status--returned` (#B45309) и `--reopened` (#6D28D9): два бейджа могут стоять на одной карточке рядом, и они должны читаться как разные вещи.

- [ ] **Step 6: Проверить глазами**

Run: `make dev`; выставить фазу вручную:
`sqlite3 data/app.sqlite "UPDATE tasks SET agent_state='awaiting_approval' WHERE id=<любой>;"`
Открыть доску и страницу задачи.
Expected: бейдж виден в обоих местах, вёрстка карточки не разъехалась, тёмная тема читается.

- [ ] **Step 7: Коммит**

```bash
git add views/partials/kanban-card.php views/tasks/show.php public/assets/css system/i18n/en.php system/i18n/uk.php system/i18n/pl.php
git commit -m "feat(ui): agent phase badge on kanban cards and task page"
```

---

### Task 9: Контракт и документация

**Files:**
- Modify: `docs/openapi.yaml`
- Modify: `docs/API.md`
- Create: `docs/MCP.md`

**Interfaces:**
- Consumes: всё из задач 3–6.
- Produces: машиночитаемый контракт, совпадающий с роутингом (проверяется `test_openapi_drift.php`).

- [ ] **Step 1: Запустить дрейф-тест и увидеть расхождение**

Run: `php tests/run.php` (или `make unit`)
Expected: FAIL `test_openapi_drift.php` — `GET /api/v1/tasks` есть в роутере, но не в спеке.

- [ ] **Step 2: Дописать путь `/tasks` в openapi.yaml**

Рядом с `/tasks/{id}` (строка ~778):

```yaml
  /tasks:
    get:
      tags: [Tasks]
      summary: Tasks assigned to a user across all visible projects
      parameters:
        - $ref: '#/components/parameters/AfterCursor'
        - $ref: '#/components/parameters/Limit'
        - { name: assignee_id,   in: query, schema: { type: integer }, description: Defaults to the caller }
        - { name: agent_state,   in: query, schema: { type: string, enum: [researching, awaiting_approval, implementing, review, blocked] } }
        - { name: updated_after, in: query, schema: { type: string, format: date-time } }
      responses:
        '200':
          description: OK
          content:
            application/json:
              schema: { $ref: '#/components/schemas/PaginatedTasks' }
        '401': { $ref: '#/components/responses/Unauthorized' }
        '429': { $ref: '#/components/responses/RateLimited' }
```

- [ ] **Step 3: Обновить схемы**

В `TaskListItem.properties` добавить:

```yaml
        agent_state:
          type: [string, 'null']
          enum: [researching, awaiting_approval, implementing, review, blocked, null]
          description: Execution phase owned by the external agent bridge.
```

В `Task.properties` (внутри второго члена `allOf`):

```yaml
            from_form:
              type: boolean
              description: True when the task was auto-created from a public form submission.
```

В `Project.properties`:

```yaml
        description: { type: [string, 'null'], description: Sanitised HTML, authored in the WYSIWYG editor }
        repo_url: { type: [string, 'null'], example: 'git@github.com:org/repo.git' }
        default_branch: { type: [string, 'null'], example: main }
        dev_branch: { type: [string, 'null'], example: develop }
        dev_url: { type: [string, 'null'], example: 'https://dev.example.com' }
        agent_instructions: { type: [string, 'null'], description: Plain text, read by automated workers }
```

В `ProjectDetail.columns.items.properties` добавить `is_done` и `is_backlog` (`type: boolean`).

В теле `PATCH /projects/{id}` и `PATCH /tasks/{id}` добавить новые принимаемые поля.

- [ ] **Step 4: Прогнать дрейф-тест**

Run: `make unit`
Expected: PASS.

- [ ] **Step 5: Обновить `docs/API.md`**

- В §5.4 описать `GET /tasks` с примером `curl` и полем `agent_state`.
- В §5.2 добавить новые поля проекта.
- В §11.4 заменить «phase 2 placeholder» ссылкой на `docs/MCP.md`.

- [ ] **Step 6: Создать `docs/MCP.md`**

Заготовка, чтобы замысел не потерялся: цель моста, ~14 курируемых инструментов (`list_my_tasks`, `get_task`, `get_project_context`, `set_agent_state`, `move_task`, `add_comment`, `reply_comment`, `update_task`, `list_projects`, `new_comments`, `whoami`), stdio-транспорт, конфигурация через `OTACK_API_URL` / `OTACK_API_TOKEN`, и явное условие: строится **после** обкатки скилла, чтобы форма инструментов шла от реального использования, а не от догадок.

- [ ] **Step 7: Коммит**

```bash
git add docs/openapi.yaml docs/API.md docs/MCP.md
git commit -m "docs(api): contract for agent bridge endpoints and fields"
```

---

### Task 10: Релиз и выкладка на прод

Без этого шага бот работать не сможет: инстанс на шеред-хостинге `https://otack-manager.neoweblab.eu` обновляется только по тегам.

**Files:**
- Modify: `system/version.php`

- [ ] **Step 1: Полный прогон**

Run: `make test`
Expected: unit, api и e2e зелёные. При падении e2e — чинить, а не пропускать.

- [ ] **Step 2: Бампнуть версию**

В `system/version.php`: `const APP_VERSION = '1.7.0';` — минорная, потому что изменения аддитивные и расширяют API.

- [ ] **Step 3: Коммит и тег**

```bash
git add system/version.php
git commit -m "release(1.7.0): agent bridge server-side support"
git tag v1.7.0
```

- [ ] **Step 4: Пуш**

```bash
git push origin HEAD --tags
```

**Требует явного согласия владельца** — по действующей договорённости пуш делается только по запросу.

- [ ] **Step 5: Обновить прод**

В админке `https://otack-manager.neoweblab.eu` → Settings → Updates → применить 1.7.0. Апдейтер сам снимет бэкап кода и БД.

- [ ] **Step 6: Проверить прод**

```bash
curl -sS -H "Authorization: Bearer $OTACK_API_TOKEN" https://otack-manager.neoweblab.eu/api/v1/tasks | head
```

Expected: `200` и конверт с `items`. Дополнительно открыть форму редактирования проекта и убедиться, что новые поля на месте.

---

## Проверка плана против спеки

| Раздел спеки | Задача |
|---|---|
| §6.1 фикс роли | Task 1 |
| §6.2 кросс-проектный список | Task 4 |
| §6.3 `agent_state` | Tasks 2, 3, 8 |
| §6.4 мелкие дыры | Task 5 |
| §6.5 поля проекта | Tasks 6, 7 |
| §6.6 сопровождение | Tasks 9, 10 |
| §10 защита от задач из форм | Task 5 (флаг `from_form`) |

Разделы §3–§5 (модель исполнения, состояние, лок) и §7–§9 (CLI, скилл, границы автономии) — предмет второго плана, клиентского. Серверная часть от него не зависит и поставляется самостоятельно.
