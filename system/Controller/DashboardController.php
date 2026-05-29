<?php
declare(strict_types=1);
namespace App\Controller;

use App\App;
use App\Http\Request;
use App\Http\Response;

final class DashboardController extends BaseController {
    public function moreActivity(Request $req, array $params = []): void {
        $isAdmin = $this->user['role'] === 'admin';
        $userId  = (int)$this->user['id'];
        $offset  = max(0, (int)($req->query['offset'] ?? 0));
        $limit   = 10;
        $activity = App::make('activity');
        $batch = $activity->recentForUser($userId, $isAdmin, $limit + 1, $offset);
        $hasMore = count($batch) > $limit;
        if ($hasMore) array_pop($batch);

        $items = array_map([$this, 'formatActivity'], $batch);
        Response::json(['items' => $items, 'has_more' => $hasMore]);
    }

    private function formatActivity(array $row): array {
        $taskUrl    = $row['task_id'] ? '/tasks/' . (int)$row['task_id'] : null;
        $projectUrl = $row['project_id'] ? '/projects/' . (int)$row['project_id'] . '?tab=overview' : null;
        return [
            'created_at'   => $row['created_at'],
            'event'        => $row['event'],
            'actor_name'   => $row['actor_name'] ?? 'Someone',
            'project_id'   => $row['project_id'] !== null ? (int)$row['project_id'] : null,
            'project_name' => $row['project_name'] ?? null,
            'project_url'  => $projectUrl,
            'task_id'      => $row['task_id'] !== null ? (int)$row['task_id'] : null,
            'task_title'   => $row['task_title'] ?? null,
            'task_url'     => $taskUrl,
            'entity_url'   => $taskUrl ?? $projectUrl,
            'summary'      => mb_strimwidth((string)$row['summary'], 0, 120, '…'),
        ];
    }

    public function index(Request $req, array $params = []): void {
        $isAdmin = $this->user['role'] === 'admin';
        $userId  = (int)$this->user['id'];
        $projects = App::make('projects');
        $tasks    = App::make('tasks');
        $activity = App::make('activity');

        $counters = $tasks->dashboardCounters($userId, $isAdmin);
        $trend    = $tasks->dashboardWeekTrend($userId, $isAdmin);
        $stats = [
            'open_projects' => $projects->countOpenForUser($userId, $isAdmin),
            'my_tasks'      => $tasks->countOpenForAssignee($userId),
            'activity'      => $activity->countForUser($userId, $isAdmin),
            'open_tasks'    => $counters['open'],
            'backlog_tasks' => $counters['backlog'],
            'closed_tasks'  => $counters['closed'],
            'opened_week'   => $counters['opened_week'],
            'closed_week'   => $counters['closed_week'],
        ];

        $myTasks        = $tasks->listForAssignee($userId, 6);
        $recentProjects = $projects->recentForUser($userId, $isAdmin, 3);
        $projectTaskCounts = $tasks->countByProject(array_map(fn($p) => (int)$p['id'], $recentProjects));
        $recentActivity = array_map(
            [$this, 'formatActivity'],
            $activity->recentForUser($userId, $isAdmin, 10)
        );

        $csrfToken = App::make('csrf')->token();
        $sidebar = $this->view->render('partials/sidebar', [
            'user'      => $this->user,
            'activeNav' => 'dashboard',
            'csrfToken' => $csrfToken,
        ]);
        $topbar = $this->view->render('partials/topbar', [
            'user'  => $this->user,
            'crumb' => t('nav.dashboard'),
        ]);
        Response::html($this->view->render('layouts/main', [
            'title'     => t('nav.dashboard'),
            'csrfToken' => $csrfToken,
            'sidebar'   => $sidebar,
            'topbar'    => $topbar,
            'content'   => $this->view->render('dashboard/index', [
                'user'           => $this->user,
                'stats'          => $stats,
                'trend'          => $trend,
                'myTasks'        => $myTasks,
                'recentProjects' => $recentProjects,
                'recentActivity' => $recentActivity,
                'projectTaskCounts' => $projectTaskCounts,
            ]),
        ]));
    }
}
