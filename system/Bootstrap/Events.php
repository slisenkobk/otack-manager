<?php
declare(strict_types=1);

namespace App\Bootstrap;

use App\App;
use App\Events\TelegramListeners;
use App\Service\{NotificationLogger, TelegramNotifier};

/**
 * Thin wrapper that constructs the NotificationLogger from env config and
 * hands it to TelegramListeners to register the 17 outbound event handlers.
 *
 * Must be called AFTER Container::register() so 'events' and 'notif_log' are
 * resolvable.
 */
final class Events
{
    public static function register(): void
    {
        $tg = new NotificationLogger(
            new TelegramNotifier(App::env('TG_BOT_TOKEN'), App::env('TG_CHAT_ID')),
            App::make('notif_log')
        );
        (new TelegramListeners($tg))->register(App::make('events'));
    }
}
