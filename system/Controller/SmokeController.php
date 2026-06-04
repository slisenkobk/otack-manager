<?php
declare(strict_types=1);
namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\View\Renderer;

final class SmokeController extends BaseController {
    public function __construct(
        Renderer $view,
        ?array $user,
    ) {
        parent::__construct($view, $user);
    }

    public function uiSandbox(Request $req, array $params = []): void {
        Response::html($this->view->render('layouts/main', [
            'title'     => 'UI Sandbox',
            'csrfToken' => '',
            'content'   => '<h1>UI Sandbox</h1>',
        ]));
    }
}
