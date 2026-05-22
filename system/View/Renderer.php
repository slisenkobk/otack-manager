<?php
declare(strict_types=1);

namespace App\View;

final class Renderer
{
    public function __construct(private string $viewRoot) {}

    public function render(string $template, array $data = [], ?string $layout = null): string
    {
        require_once APP_ROOT . '/system/View/helpers.php';
        $content = $this->renderRaw($template, $data);
        if ($layout === null) return $content;
        return $this->renderRaw($layout, array_merge($data, ['content' => $content]));
    }

    private function renderRaw(string $template, array $data): string
    {
        $path = $this->viewRoot . '/' . $template . '.php';
        if (!is_file($path)) throw new \RuntimeException("View not found: $template");
        extract($data, EXTR_SKIP);
        ob_start();
        require $path;
        return ob_get_clean();
    }
}
