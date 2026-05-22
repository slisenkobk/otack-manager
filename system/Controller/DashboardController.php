<?php
declare(strict_types=1);
namespace App\Controller;

use App\App;
use App\Http\Request;
use App\Http\Response;

final class DashboardController extends BaseController {
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
