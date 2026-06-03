<?php
declare(strict_types=1);
namespace App\Api\V1\Handlers;

use App\Api\V1\ApiResponse;
use App\Service\RolePolicy;
use App\Http\Request;

/**
 * Read-only Polls surface. Mirrors PollController gating:
 *   - canManagePolls → admin + manager only
 *   - manager scope: own polls only (admin sees all)
 */
final class PollsHandler extends BaseHandler
{
    /** GET /api/v1/polls */
    public function index(Request $req): array
    {
        if (!RolePolicy::canManagePolls($this->user())) return $this->forbidden();

        $status = (string)($req->query['status'] ?? '');
        $status = in_array($status, ['draft', 'active', 'closed'], true) ? $status : null;
        $query  = trim((string)($req->query['q'] ?? ''));

        $isAdmin = RolePolicy::isAdmin($this->user());
        $rows = $this->svc('polls')->listForUser($this->userId(), $isAdmin, $status, $query);

        $items = array_map(function ($p) {
            $row = $this->serializePoll($p);
            $row['vote_count'] = $this->svc('poll_votes')->countTotal((int)$p['id']);
            return $row;
        }, $rows);

        return ApiResponse::ok(['items' => $items]);
    }

    /** GET /api/v1/polls/{id} */
    public function show(Request $req): array
    {
        if (!RolePolicy::canManagePolls($this->user())) return $this->forbidden();

        $id   = $this->pathId($req, 3);
        $poll = $this->svc('polls')->findById($id);
        if (!$poll) return $this->notFound();
        if (!$this->canSeePoll($poll)) return $this->notFound();

        $fields = json_decode((string)($poll['fields_json'] ?? '[]'), true) ?: [];
        $tally  = $this->svc('poll_votes')->tallyByChoice($id);
        $total  = $this->svc('poll_votes')->countTotal($id);

        $countByKey = [];
        foreach ($tally as $r) { $countByKey[(string)$r['choice_key']] = (int)$r['count']; }

        // Show every option from the poll definition, including zero-vote ones,
        // so consumers can render full bars/percentages.
        $stats = [];
        foreach ($this->choiceOptions($fields) as $opt) {
            $key   = (string)$opt['key'];
            $count = $countByKey[$key] ?? 0;
            $stats[] = [
                'key'   => $key,
                'label' => (string)$opt['label'],
                'count' => $count,
                'pct'   => $total > 0 ? round($count * 100 / $total, 1) : 0.0,
            ];
        }

        $out = $this->serializePoll($poll);
        $out['fields'] = $fields;
        $out['footer'] = json_decode((string)($poll['footer_json'] ?? '{}'), true) ?: [];
        $out['contact_field']   = (string)($poll['contact_field'] ?? 'email');
        $out['success_message'] = $poll['success_message'] ?? null;
        $out['activated_at']    = $poll['activated_at'] ?? null;
        $out['closed_at']       = $poll['closed_at'] ?? null;
        $out['summary_task_id'] = isset($poll['summary_task_id']) && $poll['summary_task_id'] !== null
            ? (int)$poll['summary_task_id'] : null;
        $out['stats'] = [
            'total'   => $total,
            'options' => $stats,
        ];
        return ApiResponse::ok($out);
    }

    /** GET /api/v1/polls/{id}/voters */
    public function voters(Request $req): array
    {
        if (!RolePolicy::canManagePolls($this->user())) return $this->forbidden();

        $id   = $this->pathId($req, 3);
        $poll = $this->svc('polls')->findById($id);
        if (!$poll) return $this->notFound();
        if (!$this->canSeePoll($poll)) return $this->notFound();

        $limit = min(200, max(1, (int)($req->query['limit'] ?? 50)));
        // Cursor pagination by id DESC: `?after={id}` returns rows older than
        // the given id. This matches every other endpoint's `next_cursor`
        // semantics (last item's id); the previous offset-based cursor was a
        // contract violation — `next_cursor` was the next offset, not an id.
        $after = isset($req->query['after']) ? max(0, (int)$req->query['after']) : 0;

        // Fetch limit+1 to detect whether more rows exist.
        $batch   = $this->svc('poll_votes')->listVotersAfterId($id, $after, $limit + 1);
        $hasMore = count($batch) > $limit;
        if ($hasMore) $batch = array_slice($batch, 0, $limit);

        $labels = $this->choiceLabels(json_decode((string)($poll['fields_json'] ?? '[]'), true) ?: []);
        $items  = array_map(function ($v) use ($labels) {
            return [
                'id'           => (int)$v['id'],
                'contact'      => (string)$v['contact'],
                'choice_key'   => (string)$v['choice_key'],
                'choice_label' => $labels[(string)$v['choice_key']] ?? (string)$v['choice_key'],
                'remote_ip'    => $v['remote_ip'] ?? null,
                'created_at'   => $v['created_at'] ?? null,
            ];
        }, $batch);

        $next = $hasMore && !empty($items) ? (int)end($items)['id'] : null;
        return ApiResponse::paginated($items, $next);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    /** Admin: any poll. Manager: only polls they created. */
    private function canSeePoll(array $poll): bool
    {
        if (RolePolicy::isAdmin($this->user())) return true;
        return (int)$poll['created_by'] === $this->userId();
    }

    private function serializePoll(array $p): array
    {
        return [
            'id'          => (int)$p['id'],
            'hash'        => (string)$p['hash'],
            'title'       => (string)$p['title'],
            'description' => $p['description'] ?? null,
            'status'      => (string)$p['status'],
            'locale'      => (string)($p['locale'] ?? 'en'),
            'project_id'  => isset($p['project_id']) && $p['project_id'] !== null ? (int)$p['project_id'] : null,
            'created_by'  => (int)$p['created_by'],
            'created_at'  => $p['created_at'] ?? null,
            'updated_at'  => $p['updated_at'] ?? null,
        ];
    }

    /** @return array<int,array{key:string,label:string}> */
    private function choiceOptions(array $fields): array
    {
        foreach ($fields as $f) {
            if (($f['key'] ?? '') !== 'choice') continue;
            $out = [];
            foreach ((array)($f['options'] ?? []) as $opt) {
                if (is_array($opt) && isset($opt['key'])) {
                    $out[] = ['key' => (string)$opt['key'], 'label' => (string)($opt['label'] ?? $opt['key'])];
                }
            }
            return $out;
        }
        return [];
    }

    /** @return array<string,string> key => label */
    private function choiceLabels(array $fields): array
    {
        $out = [];
        foreach ($this->choiceOptions($fields) as $opt) {
            $out[$opt['key']] = $opt['label'];
        }
        return $out;
    }
}
