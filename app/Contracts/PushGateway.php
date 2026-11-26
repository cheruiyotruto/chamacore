<?php

namespace App\Contracts;

interface PushGateway
{
    /**
     * @param  array<int, string>  $tokens
     * @return array{success:bool, reference:?string, error:?string}
     */
    public function send(array $tokens, string $title, string $body, array $data = []): array;
}
