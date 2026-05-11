<?php

namespace App\Core;

class Router {
    private $routes = [];

    public function add($method, $path, $controller, $action) {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'controller' => $controller,
            'action' => $action
        ];
    }

    public function dispatch($method, $uri) {
        $uri = parse_url($uri, PHP_URL_PATH);
        
        // Find the position of '/api' and get everything after it
        $apiPos = strpos($uri, '/api');
        if ($apiPos !== false) {
            $uri = substr($uri, $apiPos + 4);
        }
        
        // Strip index.php if present
        $uri = str_replace('/index.php', '', $uri);
        
        if ($uri === '' || $uri === false) $uri = '/';
        
        // Ensure URI starts with a slash for matching
        if ($uri[0] !== '/') $uri = '/' . $uri;

        foreach ($this->routes as $route) {
            if ($route['method'] === $method) {
                $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<\1>[a-zA-Z0-9_-]+)', $route['path']);
                $pattern = "#^" . $pattern . "$#";
                
                if (preg_match($pattern, $uri, $matches)) {
                    $controller = "App\\Controllers\\" . $route['controller'];
                    $action = $route['action'];
                    
                    $params = [];
                    foreach ($matches as $key => $value) {
                        if (is_string($key)) {
                            $params[$key] = $value;
                        }
                    }

                    if (class_exists($controller)) {
                        $controllerInstance = new $controller();
                        if (method_exists($controllerInstance, $action)) {
                            return call_user_func_array([$controllerInstance, $action], [$params]);
                        }
                    }
                }
            }
        }

        http_response_code(404);
        echo json_encode(['error' => 'Endpoint not found']);
    }
}
