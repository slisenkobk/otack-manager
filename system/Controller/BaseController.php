<?php
declare(strict_types=1);
namespace App\Controller;

use App\View\Renderer;

abstract class BaseController {
    public function __construct(
        protected Renderer $view,
        protected ?array $user = null,
    ) {}
}
