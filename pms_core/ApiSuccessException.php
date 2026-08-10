<?php
declare(strict_types=1);

class ApiSuccessException extends \Error {
    private array $data;
    private int $statusCode;

    public function __construct(array $data = [], int $statusCode = 200) {
        parent::__construct("Success");
        $this->data = $data;
        $this->statusCode = $statusCode;
    }

    public function getData(): array {
        return $this->data;
    }

    public function getStatusCode(): int {
        return $this->statusCode;
    }
}
