<?php
declare(strict_types=1);

class ApiException extends \Exception {
    private array $extra;
    public function __construct(string $message, int $statusCode = 400, array $extra = []) {
        parent::__construct($message, $statusCode);
        $this->extra = $extra;
    }
    public function getExtra(): array {
        return $this->extra;
    }
}
