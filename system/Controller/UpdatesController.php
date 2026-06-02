<?php
declare(strict_types=1);
namespace App\Controller;

use App\App;
use App\Http\AuthGuard;
use App\Http\Request;
use App\Http\Response;
use App\Service\Updater;

/**
 * Step-2 surface: just the manual "Check now" endpoint that the Updates
 * tab and (later) the topbar badge wire through. update / restore
 * actions arrive in steps 4-5 and will live here alongside.
 */
final class UpdatesController extends BaseController
{
    public function __construct($view, $user = null) {
        parent::__construct($view, $user);
        AuthGuard::requireAdmin($this->user);
        if (!Updater::isEnabled()) {
            Response::forbidden('Updates are disabled on this install (UPDATE_ENABLED=false)');
            exit;
        }
    }

    /**
     * GET /api/updates/check — forces a fresh GitHub lookup, refreshes
     * the cache, returns JSON. Errors (rate-limit, unreachable, wrong
     * repo URL) surface as 502 with the message so the admin sees them.
     */
    public function check(Request $req, array $params = []): void
    {
        /** @var Updater $updater */
        $updater = App::make('updater');
        try {
            $payload = $updater->check();
        } catch (\Throwable $e) {
            Response::json(['error' => $e->getMessage()], 502);
            return;
        }
        Response::json($payload);
    }
}
