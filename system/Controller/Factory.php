<?php
declare(strict_types=1);
namespace App\Controller;

use App\App;
use App\View\Renderer;

/**
 * Centralised controller instantiation. Each controller declares its deps
 * in the constructor (TYPED properties); this factory pulls them from the
 * container and hands a fully-wired instance back to the dispatcher.
 *
 * Switch-style is intentional — no Reflection, no magic, explicit. Adding a
 * new controller means adding one case here.
 */
final class Factory
{
    public static function make(string $controller, Renderer $view, ?array $user): object
    {
        return match ($controller) {
            'Smoke'      => new SmokeController(
                $view, $user,
                App::make('csrf'),
            ),
            'PublicLink' => new PublicLinkController(
                $view, $user,
                App::make('short_links'),
                App::make('short_link_visits'),
            ),
            'TagAdmin'   => new TagAdminController(
                $view, $user,
                App::make('tags'),
                App::make('csrf'),
            ),
            'Updates'    => new UpdatesController(
                $view, $user,
                App::make('updater'),
            ),
            'Link'       => new LinkController(
                $view, $user,
                App::make('short_links'),
                App::make('short_link_visits'),
                App::make('activity'),
            ),
            'Tag'        => new TagController(
                $view, $user,
                App::make('tags'),
                App::make('projects'),
                App::make('members'),
                App::make('tasks'),
            ),
            'PublicPoll' => new PublicPollController(
                $view, $user,
                App::make('polls'),
                App::make('poll_votes'),
                App::make('activity'),
                App::make('events'),
                App::make('db'),
            ),
            default      => throw new \RuntimeException("Unknown controller: $controller"),
        };
    }
}
