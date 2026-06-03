<?php
declare(strict_types=1);
namespace App\Controller;

use App\App;
use App\View\Renderer;

abstract class BaseController {
    public function __construct(
        protected Renderer $view,
        protected ?array $user = null,
    ) {}

    protected function csrfToken(): string {
        return App::make('csrf')->token();
    }

    /**
     * Stash the validator's $e->fields map in the session so the next page
     * render (after a POST→redirect→GET) can highlight each field with the
     * .field--invalid wrapper + .field__error inline message. The view reads
     * via consumeFieldErrors() — single-use, like other flashes.
     */
    protected function flashFieldErrors(array $errors): void {
        $session = App::make('session');
        $session->store['flash_field_errors'] = $errors;
    }

    /**
     * Returns and removes the field-error map flashed by flashFieldErrors().
     * Always returns an array (possibly empty) so views can `isset($errors['x'])`
     * without a defined() dance.
     */
    protected function consumeFieldErrors(): array {
        $session = App::make('session');
        $out = $session->store['flash_field_errors'] ?? [];
        unset($session->store['flash_field_errors']);
        return is_array($out) ? $out : [];
    }
}
