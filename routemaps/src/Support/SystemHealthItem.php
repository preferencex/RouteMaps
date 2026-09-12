<?php

declare(strict_types=1);

namespace RouteMaps\Core\Support;

final class SystemHealthItem {
    public function __construct(
        private string $id,
        private string $label,
        private string $status,
        private mixed $value,
        private string $message = ''
    ) {
    }

    public function id(): string { return $this->id; }
    public function label(): string { return $this->label; }
    public function status(): string { return $this->status; }
    public function value(): mixed { return $this->value; }
    public function message(): string { return $this->message; }

    /** @return array{id:string,label:string,status:string,value:mixed,message:string} */
    public function toArray(): array {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'status' => $this->status,
            'value' => $this->value,
            'message' => $this->message,
        ];
    }
}
