<?php
declare(strict_types=1);
namespace App\Controller;

use App\Http\Request;
use App\Http\Response;

final class SmokeController extends BaseController {
    public function hello(Request $req, array $params = []): void {
        Response::html($this->view->render('layouts/blank', [
            'title'   => 'Otack Tasks',
            'content' => '<h1 style="font-family:sans-serif;padding:40px;">Otack Tasks is up.</h1>',
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
