<?php

namespace App\Services;

use App\Contracts\AgentDomainDnsCheckerInterface;

/**
 * Checks that an agent domain points at the frontend: a matching A record or CNAME.
 * When nothing is configured (local development) every domain is "unverified" and admins
 * use "Activate anyway".
 */
class AgentDomainDnsChecker implements AgentDomainDnsCheckerInterface
{
    public function check(string $domain): array
    {
        $aRecords = (array) config('agent_sites.a_records', []);
        $cname = (string) config('agent_sites.cname_target', '');
        $expected = array_values(array_filter([...$aRecords, $cname]));

        $found = [];
        $records = @dns_get_record($domain, DNS_A | DNS_CNAME);
        foreach (is_array($records) ? $records : [] as $r) {
            if (($r['type'] ?? '') === 'A' && ! empty($r['ip'])) {
                $found[] = $r['ip'];
            } elseif (($r['type'] ?? '') === 'CNAME' && ! empty($r['target'])) {
                $found[] = strtolower(rtrim($r['target'], '.'));
            }
        }

        $ok = $expected !== [] && (
            ($cname !== '' && in_array($cname, $found, true))
            || array_intersect($aRecords, $found) !== []
        );

        return ['ok' => $ok, 'found' => array_values(array_unique($found)), 'expected' => $expected];
    }
}
