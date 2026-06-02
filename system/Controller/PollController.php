<?php
declare(strict_types=1);
namespace App\Controller;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Repository\PollRepository;
use App\Repository\PollVoteRepository;
use App\Service\RolePolicy;

/**
 * Admin/manager-facing Polls.
 *
 *   GET  /polls                          → index, list with optional status filter
 *   GET  /polls/new                      → builder (new poll)
 *   GET  /polls/{id}                     → show: builder if draft, stats if active/closed
 *   POST /polls           /polls/{id}    → save (create / update; update only when draft)
 *   POST /polls/{id}/activate            → draft → active
 *   POST /polls/{id}/close               → active → closed
 *   POST /polls/{id}/delete | /copy | /rotate-hash
 *   POST /polls/{id}/create-summary-task → spawn a summary task in the attached project
 *
 * Editing is gated server-side on status = draft so a stale browser tab can't
 * sneak edits past the lifecycle lock.
 */
final class PollController extends BaseController
{
    public const CONTACT_KINDS = [PollRepository::CONTACT_EMAIL, PollRepository::CONTACT_PHONE];

    private PollRepository     $polls;
    private PollVoteRepository $votes;

    public function __construct($view, $user = null)
    {
        parent::__construct($view, $user);
        if (!RolePolicy::canManagePolls($this->user)) {
            Response::forbidden('Polls are managed by admins and managers'); exit;
        }
        $this->polls = App::make('polls');
        $this->votes = App::make('poll_votes');
    }

    public function index(Request $req, array $params = []): void
    {
        $status = (string)($req->query['status'] ?? '');
        if (!in_array($status, [PollRepository::STATUS_DRAFT, PollRepository::STATUS_ACTIVE, PollRepository::STATUS_CLOSED], true)) {
            $status = '';
        }
        $query   = trim((string)($req->query['q'] ?? ''));
        $isAdmin = RolePolicy::isAdmin($this->user);
        $rows    = $this->polls->listForUser((int)$this->user['id'], $isAdmin, $status ?: null, $query);

        $counts = [];
        foreach ($rows as $r) { $counts[(int)$r['id']] = $this->votes->countTotal((int)$r['id']); }

        $csrf    = $this->csrfToken();
        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'polls', 'csrfToken' => $csrf,
        ]);
        $topbar  = $this->view->render('partials/topbar', [
            'user' => $this->user, 'crumb' => 'Polls',
        ]);
        Response::html($this->view->render('layouts/main', [
            'title'     => 'Polls',
            'csrfToken' => $csrf,
            'sidebar'   => $sidebar,
            'topbar'    => $topbar,
            'content'   => $this->view->render('polls/index', [
                'polls'  => $rows,
                'counts' => $counts,
                'status' => $status,
                'query'  => $query,
            ]),
        ]));
    }

    public function builder(Request $req, array $params = []): void
    {
        // /polls/new — fresh poll with locked preset fields seeded by the repo.
        $this->renderEditor(null);
    }

    public function show(Request $req, array $params): void
    {
        $id   = (int)$params['id'];
        $poll = $this->polls->findById($id);
        if (!$poll) { Response::notFound(); return; }
        if (!$this->canManage($poll)) { Response::forbidden('Not your poll'); return; }

        if ($poll['status'] === PollRepository::STATUS_DRAFT) {
            $this->renderEditor($poll);
            return;
        }
        $tab  = (string)($req->query['tab'] ?? 'stats');
        $page = max(1, (int)($req->query['page'] ?? 1));
        if (!in_array($tab, ['stats', 'voters'], true)) $tab = 'stats';
        $this->renderStats($poll, $tab, $page);
    }

    public function save(Request $req, array $params = []): void
    {
        $data = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') { Response::json(['error' => 'Title required'], 422); return; }

        $descriptionRaw = (string)($data['description'] ?? '');
        $description    = trim($descriptionRaw) === '' ? '' : \App\Service\HtmlSanitizer::clean($descriptionRaw);

        $locale = (string)($data['locale'] ?? '');
        if (!in_array($locale, available_locales(), true)) {
            $locale = (string)App::make('settings')->get('default_locale', 'en');
            if (!in_array($locale, available_locales(), true)) $locale = 'en';
        }

        $contactField = (string)($data['contact_field'] ?? PollRepository::CONTACT_EMAIL);
        if (!in_array($contactField, self::CONTACT_KINDS, true)) {
            $contactField = PollRepository::CONTACT_EMAIL;
        }

        $fields = $this->normaliseFields($data['fields'] ?? [], $contactField);
        if (!$this->fieldsHaveContactAndChoice($fields)) {
            Response::json(['error' => 'Poll must keep the contact + choice fields'], 422);
            return;
        }
        if (!$this->choiceHasAtLeastTwoOptions($fields)) {
            Response::json(['error' => 'Choice must have at least two options'], 422);
            return;
        }

        $projectId = !empty($data['project_id']) ? (int)$data['project_id'] : null;
        if ($projectId !== null && !$this->canAttachToProject($projectId)) $projectId = null;

        $successRaw     = trim((string)($data['success_message'] ?? ''));
        $successMessage = $successRaw === '' ? null : mb_substr($successRaw, 0, 2000);

        if (!empty($params['id'])) {
            $id = (int)$params['id'];
            $existing = $this->polls->findById($id);
            if (!$existing) { Response::json(['error' => 'Not found'], 404); return; }
            if (!$this->canManage($existing)) { Response::json(['error' => 'Forbidden'], 403); return; }
            if ($existing['status'] !== PollRepository::STATUS_DRAFT) {
                Response::json(['error' => 'Active or closed polls cannot be edited'], 409);
                return;
            }
            $this->polls->update($id, [
                'title'           => $title,
                'description'     => $description,
                'fields_json'     => json_encode($fields, JSON_UNESCAPED_UNICODE),
                'contact_field'   => $contactField,
                'locale'          => $locale,
                'project_id'      => $projectId,
                'success_message' => $successMessage,
            ]);
            App::make('activity')->log('poll.updated', (int)$this->user['id'], null, null,
                "updated poll '$title'", ['poll_id' => $id]);
            App::make('events')->fire('poll.updated', [
                'poll_id' => $id, 'title' => $title, 'actor_name' => $this->user['name'],
            ]);
            Response::json(['ok' => true, 'id' => $id]);
            return;
        }

        $id = $this->polls->create(
            $title,
            $description !== '' ? $description : null,
            $fields,
            [],
            (int)$this->user['id'],
            $locale,
            $contactField,
            $projectId,
            $successMessage
        );
        App::make('activity')->log('poll.created', (int)$this->user['id'], null, null,
            "created poll '$title'", ['poll_id' => $id]);
        App::make('events')->fire('poll.created', [
            'poll_id' => $id, 'title' => $title, 'actor_name' => $this->user['name'],
        ]);
        Response::json(['ok' => true, 'id' => $id]);
    }

    public function activate(Request $req, array $params): void
    {
        $poll = $this->loadOwn($params); if (!$poll) return;
        if (!$this->polls->activate((int)$poll['id'])) {
            Response::json(['error' => 'Only draft polls can be activated'], 409);
            return;
        }
        App::make('activity')->log('poll.activated', (int)$this->user['id'], null, null,
            "activated poll '{$poll['title']}'", ['poll_id' => (int)$poll['id']]);
        App::make('events')->fire('poll.activated', [
            'poll_id' => (int)$poll['id'], 'title' => $poll['title'], 'actor_name' => $this->user['name'],
        ]);
        Response::json(['ok' => true]);
    }

    public function close(Request $req, array $params): void
    {
        $poll = $this->loadOwn($params); if (!$poll) return;
        if (!$this->polls->close((int)$poll['id'])) {
            Response::json(['error' => 'Only active polls can be closed'], 409);
            return;
        }
        App::make('activity')->log('poll.closed', (int)$this->user['id'], null, null,
            "closed poll '{$poll['title']}'", ['poll_id' => (int)$poll['id']]);
        App::make('events')->fire('poll.closed', [
            'poll_id' => (int)$poll['id'], 'title' => $poll['title'], 'actor_name' => $this->user['name'],
        ]);
        Response::json(['ok' => true]);
    }

    public function delete(Request $req, array $params): void
    {
        $poll = $this->loadOwn($params); if (!$poll) return;
        $title = $poll['title'];
        $this->polls->delete((int)$poll['id']);
        App::make('activity')->log('poll.deleted', (int)$this->user['id'], null, null,
            "deleted poll '$title'", ['poll_id' => (int)$poll['id']]);
        Response::json(['ok' => true]);
    }

    public function copy(Request $req, array $params): void
    {
        $src = $this->loadOwn($params); if (!$src) return;
        $fields   = json_decode((string)$src['fields_json'], true) ?: [];
        $newTitle = mb_substr(trim($src['title']) . ' (copy)', 0, 200);

        // The attached project may be unreachable for the current user → drop it
        // rather than silently transferring (same rule as Forms::copy).
        $projectId = !empty($src['project_id']) ? (int)$src['project_id'] : null;
        if ($projectId !== null && !$this->canAttachToProject($projectId)) $projectId = null;

        $newId = $this->polls->create(
            $newTitle,
            $src['description'] !== '' ? (string)$src['description'] : null,
            $fields,
            [],
            (int)$this->user['id'],
            (string)($src['locale'] ?? 'en'),
            (string)($src['contact_field'] ?? PollRepository::CONTACT_EMAIL),
            $projectId,
            !empty($src['success_message']) ? (string)$src['success_message'] : null
        );
        App::make('activity')->log('poll.created', (int)$this->user['id'], null, null,
            "duplicated poll '{$src['title']}' as '$newTitle'",
            ['poll_id' => $newId, 'source_poll_id' => (int)$src['id']]);
        Response::json(['ok' => true, 'id' => $newId, 'url' => '/polls/' . $newId]);
    }

    public function regenerateHash(Request $req, array $params): void
    {
        $poll = $this->loadOwn($params); if (!$poll) return;
        $hash = $this->polls->rotateHash((int)$poll['id']);
        Response::json(['ok' => true, 'hash' => $hash, 'url' => \abs_url('/p/' . $hash)]);
    }

    public function createSummaryTask(Request $req, array $params): void
    {
        $poll = $this->loadOwn($params); if (!$poll) return;
        if ($poll['status'] !== PollRepository::STATUS_CLOSED) {
            Response::json(['error' => 'Summary task can only be created after the poll is closed'], 409);
            return;
        }
        $projectId = !empty($poll['project_id']) ? (int)$poll['project_id'] : 0;
        if ($projectId <= 0) {
            Response::json(['error' => 'Poll is not attached to a project'], 409);
            return;
        }
        if (!empty($poll['summary_task_id'])) {
            Response::json(['error' => 'Summary task already exists', 'task_id' => (int)$poll['summary_task_id']], 409);
            return;
        }

        $tally  = $this->votes->tallyByChoice((int)$poll['id']);
        $total  = $this->votes->countTotal((int)$poll['id']);
        $fields = json_decode((string)$poll['fields_json'], true) ?: [];
        $labels = $this->choiceLabels($fields);

        // Plain-text Markdown body is what TaskRepository stores; rendering happens
        // in the task view via its own Markdown pipeline.
        $lines   = ["Poll: **{$poll['title']}**", "Total votes: $total", ''];
        $lines[] = '| Choice | Votes | % |';
        $lines[] = '| --- | --- | --- |';
        foreach ($tally as $row) {
            $key   = (string)$row['choice_key'];
            $count = (int)$row['count'];
            $label = $labels[$key] ?? $key;
            $pct   = $total > 0 ? round($count * 100 / $total, 1) : 0;
            $lines[] = "| $label | $count | {$pct}% |";
        }
        $body = implode("\n", $lines);

        // Same destination rule as form auto-task: leftmost non-backlog column.
        $cols  = App::make('columns')->listForProject($projectId);
        $board = array_values(array_filter($cols, fn($c) => (int)($c['is_backlog'] ?? 0) === 0));
        usort($board, fn($a, $b) => (int)$a['position'] <=> (int)$b['position']);
        $todoCol = $board[0] ?? null;
        if (!$todoCol) {
            Response::json(['error' => 'Project has no non-backlog column to place the task in'], 409);
            return;
        }
        $taskId = App::make('tasks')->create(
            $projectId,
            (int)$todoCol['id'],
            mb_substr("Poll results — {$poll['title']}", 0, 200),
            (int)$this->user['id'],
            $body
        );
        $this->polls->attachSummaryTask((int)$poll['id'], $taskId);

        App::make('activity')->log('poll.summary_task_created', (int)$this->user['id'], $projectId, $taskId,
            "created summary task for poll '{$poll['title']}'", ['poll_id' => (int)$poll['id']]);
        Response::json(['ok' => true, 'task_id' => $taskId, 'url' => '/tasks/' . $taskId]);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    /**
     * Load a poll by {id} and ensure the current user can manage it.
     * Returns the poll row or null after writing a JSON error response.
     * When $writeError is false, returns null silently so GET endpoints can
     * decide their own response shape.
     */
    private function loadOwn(array $params, bool $writeError = true): ?array
    {
        $id = (int)($params['id'] ?? 0);
        $poll = $this->polls->findById($id);
        if (!$poll) {
            if ($writeError) Response::json(['error' => 'Not found'], 404);
            return null;
        }
        if (!$this->canManage($poll)) {
            if ($writeError) Response::json(['error' => 'Forbidden'], 403);
            return null;
        }
        return $poll;
    }

    private function canManage(array $poll): bool
    {
        if (RolePolicy::isAdmin($this->user)) return true;
        return (int)$poll['created_by'] === (int)$this->user['id'];
    }

    private function canAttachToProject(int $projectId): bool
    {
        $project = App::make('projects')->findById($projectId);
        if (!$project) return false;
        if (RolePolicy::isAdmin($this->user)) return true;
        return App::make('members')->isMember($projectId, (int)$this->user['id']);
    }

    private function renderEditor(?array $poll): void
    {
        $isAdmin  = RolePolicy::isAdmin($this->user);
        $projects = App::make('projects')->listForUser((int)$this->user['id'], $isAdmin);

        $csrf    = $this->csrfToken();
        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'polls', 'csrfToken' => $csrf,
        ]);
        $topbar  = $this->view->render('partials/topbar', [
            'user'  => $this->user,
            'crumb' => $poll ? ('Edit poll: ' . $poll['title']) : 'New poll',
        ]);
        Response::html($this->view->render('layouts/main', [
            'title'     => $poll ? 'Edit poll' : 'New poll',
            'csrfToken' => $csrf,
            'sidebar'   => $sidebar,
            'topbar'    => $topbar,
            'content'   => $this->view->render('polls/builder', [
                'poll'         => $poll,
                'csrfToken'    => $csrf,
                'projects'     => $projects,
                'defaultFields' => PollRepository::defaultFields(),
            ]),
        ]));
    }

    private function renderStats(array $poll, string $tab = 'stats', int $page = 1): void
    {
        $pollId = (int)$poll['id'];
        $total  = $this->votes->countTotal($pollId);
        $fields = json_decode((string)$poll['fields_json'], true) ?: [];
        $labels = $this->choiceLabels($fields);

        $rows = [];
        $voters = [];
        $perPage = 50;
        if ($tab === 'voters') {
            $voters = $this->votes->listVoters($pollId, $page, $perPage);
            foreach ($voters as &$v) {
                $v['choice_label'] = $labels[(string)$v['choice_key']] ?? (string)$v['choice_key'];
            }
            unset($v);
        } else {
            $tally = $this->votes->tallyByChoice($pollId);
            foreach ($tally as $r) {
                $key = (string)$r['choice_key'];
                $rows[] = [
                    'key'   => $key,
                    'label' => $labels[$key] ?? $key,
                    'count' => (int)$r['count'],
                    'pct'   => $total > 0 ? round((int)$r['count'] * 100 / $total, 1) : 0.0,
                ];
            }
        }

        $project = !empty($poll['project_id']) ? App::make('projects')->findById((int)$poll['project_id']) : null;

        $csrf    = $this->csrfToken();
        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'polls', 'csrfToken' => $csrf,
        ]);
        $topbar  = $this->view->render('partials/topbar', [
            'user' => $this->user, 'crumb' => 'Poll: ' . $poll['title'],
        ]);
        Response::html($this->view->render('layouts/main', [
            'title'     => $poll['title'],
            'csrfToken' => $csrf,
            'sidebar'   => $sidebar,
            'topbar'    => $topbar,
            'content'   => $this->view->render('polls/show', [
                'poll'     => $poll,
                'tally'    => $rows,
                'total'    => $total,
                'project'  => $project,
                'tab'      => $tab,
                'voters'   => $voters,
                'page'     => $page,
                'perPage'  => $perPage,
            ]),
        ]));
    }

    /**
     * Normalise the fields blob coming back from the builder.
     *
     * Rules specific to polls:
     *   - the first field must be a contact field of the matching type ('email' or 'tel');
     *     if missing we reinsert the locked default.
     *   - the second field must be a radio with key='choice'; if missing we
     *     reinsert the default radio.
     *   - extra fields are accepted but kept text-only (no nested forms).
     */
    private function normaliseFields(array $raw, string $contactKind): array
    {
        $defaults = PollRepository::defaultFields($contactKind);
        $contactField = null;
        $choiceField  = null;
        $extras = [];
        foreach ($raw as $f) {
            $key = (string)($f['key'] ?? '');
            if ($key === 'contact') {
                $contactField = $f;
            } elseif ($key === 'choice') {
                $choiceField = $f;
            } else {
                $extras[] = $f;
            }
        }
        // Force shape of the locked contact field.
        $contact = $defaults[0];
        if (is_array($contactField)) {
            $label = trim((string)($contactField['label'] ?? $contact['label']));
            if ($label !== '') $contact['label'] = mb_substr($label, 0, 200);
        }
        // Choice: keep label + options from the user; force key/type/required.
        $choice = $defaults[1];
        if (is_array($choiceField)) {
            $label = trim((string)($choiceField['label'] ?? $choice['label']));
            if ($label !== '') $choice['label'] = mb_substr($label, 0, 200);

            $opts = [];
            $usedKeys = [];
            foreach ((array)($choiceField['options'] ?? []) as $i => $opt) {
                if (is_array($opt)) {
                    $optKey   = (string)($opt['key'] ?? '');
                    $optLabel = trim((string)($opt['label'] ?? ''));
                } else {
                    $optKey   = '';
                    $optLabel = trim((string)$opt);
                }
                if ($optLabel === '') continue;
                $base = preg_replace('/[^a-z0-9_]+/', '_', strtolower($optKey)) ?: ('opt_' . ($i + 1));
                $key  = $base;
                $n = 2;
                while (isset($usedKeys[$key])) { $key = $base . '_' . $n; $n++; }
                $usedKeys[$key] = true;
                $opts[] = ['key' => $key, 'label' => mb_substr($optLabel, 0, 200)];
            }
            if ($opts) $choice['options'] = $opts;
        }
        return array_merge([$contact, $choice], $extras);
    }

    private function fieldsHaveContactAndChoice(array $fields): bool
    {
        $hasContact = $hasChoice = false;
        foreach ($fields as $f) {
            if (($f['key'] ?? '') === 'contact') $hasContact = true;
            if (($f['key'] ?? '') === 'choice')  $hasChoice  = true;
        }
        return $hasContact && $hasChoice;
    }

    private function choiceHasAtLeastTwoOptions(array $fields): bool
    {
        foreach ($fields as $f) {
            if (($f['key'] ?? '') === 'choice') {
                return count((array)($f['options'] ?? [])) >= 2;
            }
        }
        return false;
    }

    /** @return array<string,string> option_key => option_label */
    private function choiceLabels(array $fields): array
    {
        foreach ($fields as $f) {
            if (($f['key'] ?? '') !== 'choice') continue;
            $out = [];
            foreach ((array)($f['options'] ?? []) as $opt) {
                if (is_array($opt) && isset($opt['key'])) {
                    $out[(string)$opt['key']] = (string)($opt['label'] ?? $opt['key']);
                }
            }
            return $out;
        }
        return [];
    }
}
