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
        $comments = App::make('comments');
        $batch = $comments->recentForUser($userId, $isAdmin, $limit + 1, $offset);
        $hasMore = count($batch) > $limit;
        if ($hasMore) array_pop($batch);

        $items = [];
        foreach ($batch as $c) {
            $entityUrl = $c['entity_type'] === 'project'
                ? '/projects/' . (int)$c['entity_id']
                : '/tasks/' . (int)$c['entity_id'];
            $items[] = [
                'created_at'   => $c['created_at'],
                'author_name'  => $c['author_name'],
                'entity_type'  => $c['entity_type'],
                'entity_id'    => (int)$c['entity_id'],
                'entity_url'   => $entityUrl,
                'body_snippet' => mb_strimwidth(strip_tags((string)$c['body']), 0, 80, '…'),
            ];
        }
        Response::json(['items' => $items, 'has_more' => $hasMore]);
    }

    public function index(Request $req, array $params = []): void {
        $isAdmin = $this->user['role'] === 'admin';
        $userId  = (int)$this->user['id'];
        $projects = App::make('projects');
        $tasks    = App::make('tasks');
        $comments = App::make('comments');

        $stats = [
            'open_projects' => $projects->countOpenForUser($userId, $isAdmin),
            'my_tasks'      => $tasks->countOpenForAssignee($userId),
            'activity'      => count($comments->recentForUser($userId, $isAdmin, 50)),
        ];

        $myTasks        = $tasks->listForAssignee($userId, 6);
        $recentProjects = $projects->recentForUser($userId, $isAdmin, 3);
        $recentComments = $comments->recentForUser($userId, $isAdmin, 10);

        $csrfToken = App::make('csrf')->token();
        $sidebar = $this->view->render('partials/sidebar', [
            'user'      => $this->user,
            'activeNav' => 'dashboard',
            'csrfToken' => $csrfToken,
        ]);
        $topbar = $this->view->render('partials/topbar', [
            'user'  => $this->user,
            'crumb' => 'Dashboard',
        ]);
        Response::html($this->view->render('layouts/main', [
            'title'     => 'Dashboard',
            'csrfToken' => $csrfToken,
            'sidebar'   => $sidebar,
            'topbar'    => $topbar,
            'content'   => $this->view->render('dashboard/index', [
                'user'           => $this->user,
                'stats'          => $stats,
                'myTasks'        => $myTasks,
                'recentProjects' => $recentProjects,
                'recentComments' => $recentComments,
            ]),
        ]));
    }
}
