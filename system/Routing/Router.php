<?php
declare(strict_types=1);
namespace App\Routing;

final class Router {
    private array $routes = [];

    public function get(string $path, string $target): void  { $this->add('GET',  $path, $target); }
    public function post(string $path, string $target): void { $this->add('POST', $path, $target); }

    private function add(string $method, string $path, string $target): void {
        $params = [];
        $regex = preg_replace_callback('#\{(\w+)\}#',
            function ($m) use (&$params) { $params[] = $m[1]; return '([^/]+)'; }, $path);
        $this->routes[] = ['method' => $method, 'regex' => '#^' . $regex . '$#', 'params' => $params, 'target' => $target];
    }

    public function match(string $method, string $path): ?array {
        foreach ($this->routes as $r) {
            if ($r['method'] !== $method) continue;
            if (!preg_match($r['regex'], $path, $m)) continue;
            $params = [];
            foreach ($r['params'] as $i => $name) $params[$name] = $m[$i + 1];
            [$controller, $action] = explode('@', $r['target'], 2);
            return ['controller' => $controller, 'action' => $action, 'params' => $params];
        }
        return null;
    }
}
