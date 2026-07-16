<?php

namespace App\Support\Auth;

final readonly class AuthenticatedIdentity
{
    /**
     * @param  list<string>  $roles
     * @param  array<string, mixed>  $claims
     */
    public function __construct(
        public string $subject,
        public ?string $email,
        public ?string $name,
        public ?string $preferredUsername,
        public array $roles,
        public array $claims,
    ) {}
}
