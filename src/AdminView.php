<?php
declare(strict_types=1);

final class AdminView
{
    private $rootDir;

    public function __construct($rootDir)
    {
        $this->rootDir = rtrim(str_replace('\\', '/', (string) $rootDir), '/');
    }

    public function render($template, $data)
    {
        $templateFile = $this->rootDir . '/templates/' . $template . '.php';
        if (!is_file($templateFile)) {
            throw new RuntimeException('Template not found.');
        }

        $flash = isset($data['flash']) ? $data['flash'] : array();
        $currentAdmin = isset($data['currentAdmin']) ? $data['currentAdmin'] : null;
        $title = isset($data['title']) ? $data['title'] : 'Admin';
        $csrfToken = isset($data['csrfToken']) ? $data['csrfToken'] : '';

        extract($data, EXTR_SKIP);
        ob_start();
        require $templateFile;
        $content = ob_get_clean();
        require $this->rootDir . '/templates/layout.php';
    }
}
