<?php

namespace App\Contracts;

interface AgentDomainDnsCheckerInterface
{
    /**
     * Does this domain point at our frontend (A record or CNAME as configured)?
     *
     * @return array{ok: bool, found: list<string>, expected: list<string>}
     */
    public function check(string $domain): array;
}
