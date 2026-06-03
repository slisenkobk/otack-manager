<?php
declare(strict_types=1);

namespace App\Bootstrap;

use App\App;
use App\Routing\Router;

/**
 * Web Router definitions.
 *
 * NOTE: /api/v1/* is intentionally NOT registered here — those requests are
 * handed off to ApiKernel directly from public/index.php before this router
 * is consulted.
 */
final class Routes
{
    public static function build(): Router
    {
        $router = new Router();

        $router->get('/', 'Dashboard@index');
        $router->get('/api/activity', 'Dashboard@moreActivity');

        if (App::env('APP_DEBUG') === 'true') {
            $router->get('/ui-sandbox', 'Smoke@uiSandbox');
        }

        $router->get('/login',    'Auth@loginForm');
        $router->post('/login',   'Auth@login');
        $router->get('/register', 'Auth@registerForm');
        $router->post('/register','Auth@register');
        $router->get('/pending',  'Auth@pending');
        $router->post('/logout',  'Auth@logout');

        $router->get('/users', 'User@index');
        $router->post('/users', 'User@create');
        $router->get('/users/{id}', 'User@show');
        $router->post('/users/{id}', 'User@update');
        $router->post('/users/{id}/approve', 'User@approve');
        $router->post('/users/{id}/block', 'User@block');
        $router->post('/users/{id}/role', 'User@setRole');
        $router->post('/users/{id}/delete', 'User@delete');
        $router->post('/users/{id}/tokens/{tid}/revoke', 'User@revokeToken');
        $router->post('/users/{id}/tokens/revoke-all', 'User@revokeAllTokens');

        $router->get('/profile', 'Profile@show');
        $router->post('/profile', 'Profile@update');
        $router->post('/profile/password', 'Profile@updatePassword');
        $router->post('/profile/avatar', 'Profile@updateAvatar');
        $router->post('/profile/avatar/delete', 'Profile@removeAvatar');
        $router->post('/profile/locale', 'Profile@updateLocale');
        $router->get('/profile/tokens', 'Profile@tokens');
        $router->post('/profile/tokens', 'Profile@tokensCreate');
        $router->post('/profile/tokens/{id}/revoke', 'Profile@tokensRevoke');

        $router->get('/projects', 'Project@index');
        $router->post('/projects', 'Project@create');
        $router->get('/projects/{id}', 'Project@show');
        $router->get('/projects/{id}/edit', 'Project@editForm');
        $router->post('/projects/{id}', 'Project@update');
        $router->post('/projects/{id}/delete', 'Project@delete');
        $router->post('/api/projects/{id}/pin', 'Project@togglePin');

        $router->get('/tasks/{id}', 'Task@show');
        $router->post('/tasks/{id}', 'Task@update');
        $router->post('/projects/{id}/tasks', 'Task@create');
        $router->post('/tasks/{id}/delete', 'Task@delete');
        $router->post('/api/tasks/{id}/promote-to-project', 'Task@promoteToProject');
        $router->post('/api/tasks/{id}/move', 'Task@move');
        $router->get('/api/projects/{id}/tasks/search', 'Task@search');
        $router->get('/api/projects/{id}/columns/{cid}/tasks', 'Task@listForColumn');
        $router->get('/api/tasks/{id}/links/search', 'Task@searchLinkable');
        $router->post('/api/tasks/{id}/links', 'Task@link');
        $router->post('/api/tasks/{id}/links/{otherId}/delete', 'Task@unlink');

        $router->post('/api/projects/{id}/members', 'Project@addMember');
        $router->post('/api/projects/{id}/members/{userId}/delete', 'Project@removeMember');

        $router->post('/api/comments', 'Comment@create');
        $router->post('/api/comments/{id}/delete', 'Comment@delete');

        $router->post('/api/attachments', 'Attachment@upload');
        $router->post('/api/attachments/{id}/delete', 'Attachment@delete');

        $router->post('/api/tags', 'Tag@create');
        $router->post('/api/projects/{id}/tags', 'Tag@attachToProject');
        $router->post('/api/projects/{id}/tags/{tagId}/delete', 'Tag@detachFromProject');
        $router->post('/api/tasks/{id}/tags', 'Tag@attachToTask');
        $router->post('/api/tasks/{id}/tags/{tagId}/delete', 'Tag@detachFromTask');

        $router->get('/admin/settings', 'Settings@show');
        $router->post('/admin/settings', 'Settings@update');

        $router->get('/api/updates/check', 'Updates@check');
        $router->post('/admin/updates/run', 'Updates@run');
        $router->post('/admin/updates/restore/{id}', 'Updates@restore');

        $router->get('/admin/compass',                          'Compass@index');
        $router->get('/admin/compass/migrations',               'Compass@migrations');
        $router->post('/admin/compass/migrations/run',          'Compass@runMigrations');
        $router->get('/admin/compass/cache',                    'Compass@cache');
        $router->post('/admin/compass/cache/sessions/clear',    'Compass@clearSessions');
        $router->post('/admin/compass/cache/uploads/orphans/clear', 'Compass@clearOrphanUploads');
        $router->post('/admin/compass/cache/bust',              'Compass@bustAssetCache');
        $router->post('/admin/compass/cache/activity-log/prune', 'Compass@pruneActivityLog');
        $router->get('/admin/compass/db-stats',                 'Compass@stats');
        $router->get('/admin/compass/logs',                     'Compass@logs');
        $router->post('/admin/compass/logs/clear',              'Compass@clearErrorLog');
        $router->get('/admin/compass/db-migrate',               'Compass@dbMigrate');
        $router->post('/admin/compass/db-migrate/test',         'Compass@dbMigrateTest');
        $router->post('/admin/compass/db-migrate/start',        'Compass@dbMigrateStart');
        $router->get('/admin/compass/db-migrate/verify',        'Compass@dbMigrateVerify');

        $router->get('/forms', 'Form@index');
        $router->get('/forms/new', 'Form@builder');
        $router->post('/forms', 'Form@save');
        $router->get('/forms/{id}', 'Form@builder');
        $router->post('/forms/{id}', 'Form@save');
        $router->post('/forms/{id}/delete', 'Form@delete');
        $router->post('/forms/{id}/copy', 'Form@copy');
        $router->post('/forms/{id}/rotate-hash', 'Form@regenerateHash');

        $router->get('/forms-data', 'FormData@index');
        $router->get('/forms-data/{id}', 'FormData@show');
        $router->post('/api/forms-data/{id}/status', 'FormData@setStatus');
        $router->post('/api/forms-data/{id}/promote', 'FormData@promote');
        $router->post('/api/forms-data/{id}/delete', 'FormData@delete');

        $router->get('/f/{hash}', 'PublicForm@show');
        $router->post('/f/{hash}', 'PublicForm@submit');

        $router->get('/s/{slug}', 'PublicLink@redirect');

        $router->get('/links', 'Link@index');
        $router->post('/links', 'Link@create');
        $router->get('/links/{id}', 'Link@show');
        $router->post('/links/{id}', 'Link@update');
        $router->post('/links/{id}/delete', 'Link@delete');
        $router->post('/links/{id}/toggle', 'Link@toggle');
        $router->post('/links/{id}/rotate-slug', 'Link@rotateSlug');

        $router->get('/polls', 'Poll@index');
        $router->get('/polls/new', 'Poll@builder');
        $router->post('/polls', 'Poll@save');
        $router->get('/polls/{id}', 'Poll@show');
        $router->post('/polls/{id}', 'Poll@save');
        $router->post('/polls/{id}/delete', 'Poll@delete');
        $router->post('/polls/{id}/copy', 'Poll@copy');
        $router->post('/polls/{id}/rotate-hash', 'Poll@regenerateHash');
        $router->post('/polls/{id}/activate', 'Poll@activate');
        $router->post('/polls/{id}/close', 'Poll@close');
        $router->post('/polls/{id}/project', 'Poll@updateProject');
        $router->post('/polls/{id}/create-summary-task', 'Poll@createSummaryTask');
        $router->get('/api/polls/{id}/voters', 'Poll@votersJson');

        $router->get('/p/{hash}', 'PublicPoll@show');
        $router->post('/p/{hash}', 'PublicPoll@submit');

        $router->get('/admin/tags', 'TagAdmin@index');
        $router->post('/api/admin/tags/{id}', 'TagAdmin@update');
        $router->post('/api/admin/tags/{id}/delete', 'TagAdmin@delete');

        $router->post('/api/columns', 'Column@create');
        $router->post('/api/columns/{id}', 'Column@update');
        $router->post('/api/columns/{id}/delete', 'Column@delete');
        $router->post('/api/projects/{id}/columns/reorder', 'Column@reorder');

        return $router;
    }
}
