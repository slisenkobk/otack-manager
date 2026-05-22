<?php
declare(strict_types=1);
namespace App\Controller;

use App\View\Renderer;
use App\Http\Csrf;
use App\Http\Response;

final class SmokeController {
    public function __construct(
        private Renderer $view,
        private \PDO $db,
        private array &$session,
        private Csrf $csrf,
    ) {}

    public function hello(): void {
        Response::html($this->view->render('layouts/blank', [
            'title' => 'Otack Tasks',
            'content' => '<h1 style="font-family:sans-serif;padding:40px;">Otack Tasks is up.</h1>',
        ]));
    }

    public function uiSandbox(): void {
        Response::html($this->view->render('layouts/main', [
            'title'     => 'UI Sandbox',
            'csrfToken' => '',
            'content'   => '<h1>UI Sandbox</h1>',
        ]));
    }
}
