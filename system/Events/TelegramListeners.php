<?php
declare(strict_types=1);

namespace App\Events;

use App\Service\{EventBus, NotificationLogger, TelegramNotifier};

/**
 * Registers all Telegram outbound notification listeners on the EventBus.
 *
 * Payload formats are kept VERBATIM with the inline closures they replaced —
 * including the 🔥 IMPORTANT prefix on form.submitted, the special reply-to
 * handling on comment.created, and the 200 / 280 char mb_substr previews.
 */
final class TelegramListeners
{
    public function __construct(private NotificationLogger $tg) {}

    public function register(EventBus $events): void
    {
        $tg     = $this->tg;
        $tgEsc  = fn ($s) => TelegramNotifier::escape((string)$s);
        $tgBold = fn ($s) => '<b>' . TelegramNotifier::escape((string)$s) . '</b>';

        $events->on('user.registered', function ($p) use ($tg, $tgEsc, $tgBold) {
            $tg->notify('user.registered', "[NEW] Registration request: " . $tgBold($p['name']) . " &lt;" . $tgEsc($p['email']) . "&gt;", null, $p);
        });
        $events->on('user.approved', function ($p) use ($tg, $tgEsc, $tgBold) {
            $tg->notify('user.approved', "[USER] " . $tgBold($p['name']) . " approved by " . $tgBold($p['actor_name']), null, $p);
        });
        $events->on('project.created', function ($p) use ($tg, $tgEsc, $tgBold) {
            $tg->notify('project.created', "[PROJECT] " . $tgBold($p['actor_name']) . " created \"" . $tgEsc($p['name']) . "\"", $p['url'] ?? null, $p);
        });
        $events->on('project.updated', function ($p) use ($tg, $tgEsc, $tgBold) {
            $tg->notify('project.updated', "[PROJECT] " . $tgBold($p['actor_name']) . " updated \"" . $tgEsc($p['name']) . "\"", $p['url'] ?? null, $p);
        });
        $events->on('task.created', function ($p) use ($tg, $tgEsc, $tgBold) {
            $tg->notify('task.created', "[TASK] " . $tgBold($p['actor_name']) . " added \"" . $tgEsc($p['title']) . "\" to " . $tgEsc($p['project_name']), $p['url'] ?? null, $p);
        });
        $events->on('task.status_changed', function ($p) use ($tg, $tgEsc, $tgBold) {
            $tg->notify('task.status_changed', "[TASK] " . $tgBold($p['actor_name']) . " moved \"" . $tgEsc($p['title']) . "\" → " . $tgEsc($p['new_column']), $p['url'] ?? null, $p);
        });
        $events->on('task.assignee_changed', function ($p) use ($tg, $tgEsc, $tgBold) {
            $tg->notify('task.assignee_changed', "[TASK] " . $tgBold($p['actor_name']) . " assigned \"" . $tgEsc($p['title']) . "\" to " . $tgBold($p['assignee_name']), $p['url'] ?? null, $p);
        });
        $events->on('comment.created', function ($p) use ($tg, $tgEsc, $tgBold) {
            $reply = !empty($p['reply_to_author'])
                ? " (reply to " . $tgBold($p['reply_to_author']) . ")"
                : "";
            $tg->notify('comment.created',
                "[COMMENT] " . $tgBold($p['author']) . " on " . $tgEsc($p['entity_label']) . " \"" . $tgEsc($p['target_name']) . "\"" . $reply . ": "
                . $tgEsc(mb_substr($p['body_text'] ?? '', 0, 200)),
                $p['url'] ?? null, $p);
        });
        $events->on('project.deleted', function ($p) use ($tg, $tgEsc, $tgBold) {
            $tg->notify('project.deleted', "[PROJECT] " . $tgBold($p['actor_name']) . " deleted \"" . $tgEsc($p['name']) . "\"", null, $p);
        });
        $events->on('task.deleted', function ($p) use ($tg, $tgEsc, $tgBold) {
            $tg->notify('task.deleted', "[TASK] " . $tgBold($p['actor_name']) . " deleted \"" . $tgEsc($p['title']) . "\" in " . $tgEsc($p['project_name']), null, $p);
        });
        $events->on('comment.deleted', function ($p) use ($tg, $tgEsc, $tgBold) {
            $tg->notify('comment.deleted', "[COMMENT] " . $tgBold($p['actor_name']) . " deleted a comment on " . $tgEsc($p['entity_label']) . " \"" . $tgEsc($p['target_name']) . "\"", $p['url'] ?? null, $p);
        });
        $events->on('task.linked', function ($p) use ($tg, $tgEsc, $tgBold) {
            $tg->notify('task.linked', "[LINK] " . $tgBold($p['actor_name']) . " linked \"" . $tgEsc($p['task_title']) . "\" ↔ \"" . $tgEsc($p['linked_title']) . "\" in " . $tgEsc($p['project_name']), $p['url'] ?? null, $p);
        });
        $events->on('task.unlinked', function ($p) use ($tg, $tgEsc, $tgBold) {
            $tg->notify('task.unlinked', "[LINK] " . $tgBold($p['actor_name']) . " unlinked \"" . $tgEsc($p['task_title']) . "\" ↔ \"" . $tgEsc($p['linked_title']) . "\" in " . $tgEsc($p['project_name']), $p['url'] ?? null, $p);
        });
        // Public form submissions — flagged IMPORTANT because they are customer
        // touchpoints (and surface in /forms-data immediately).
        $events->on('form.submitted', function ($p) use ($tg, $tgEsc, $tgBold) {
            $preview = !empty($p['preview']) ? "\n" . $tgEsc(mb_substr($p['preview'], 0, 280)) : '';
            $tg->notify(
                'form.submitted',
                "🔥 <b>IMPORTANT</b> · [FORM] new submission on \"" . $tgEsc($p['form_title']) . "\"" . $preview,
                $p['url'] ?? null,
                $p
            );
        });
        $events->on('form.created', function ($p) use ($tg, $tgEsc, $tgBold) {
            $tg->notify('form.created', "[FORM] " . $tgBold($p['actor_name']) . " created form \"" . $tgEsc($p['title']) . "\"", null, $p);
        });
        $events->on('form.updated', function ($p) use ($tg, $tgEsc, $tgBold) {
            $tg->notify('form.updated', "[FORM] " . $tgBold($p['actor_name']) . " updated form \"" . $tgEsc($p['title']) . "\"", null, $p);
        });
        $events->on('form.deleted', function ($p) use ($tg, $tgEsc, $tgBold) {
            $tg->notify('form.deleted', "[FORM] " . $tgBold($p['actor_name']) . " deleted form \"" . $tgEsc($p['title']) . "\"", null, $p);
        });
        // Compass: admin housekeeping actions (sessions cleared, asset bust, etc.).
        $events->on('compass.action', function ($p) use ($tg, $tgEsc, $tgBold) {
            $tg->notify(
                'compass.action',
                "[COMPASS] " . $tgBold($p['actor_name']) . ' ' . $tgEsc($p['summary']),
                null,
                $p
            );
        });
    }
}
