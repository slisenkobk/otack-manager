<?php
declare(strict_types=1);
namespace App\Controller;

use App\Http\Request;
use App\Http\Response;

final class SmokeController extends BaseController {
    public function hello(Request $req, array $params = []): void {
        $csrfToken = \App\App::make('csrf')->token();
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
            'title'     => 'Otack Tasks',
            'csrfToken' => $csrfToken,
            'sidebar'   => $sidebar,
            'topbar'    => $topbar,
            'content'   => '<h1 style="font-weight:700;font-size:36px;margin:0;">Welcome, ' . htmlspecialchars($this->user['name'] ?? '') . '</h1><p style="color:var(--ink-2);margin-top:8px;">Dashboard placeholder — coming in Task 26.</p>',
        ]));
    }

    public function uiSandbox(Request $req, array $params = []): void {
        Response::html($this->view->render('layouts/main', [
            'title'     => 'UI Sandbox',
            'csrfToken' => '',
            'content'   => '<h1>UI Sandbox</h1>',
        ]));
    }
}
