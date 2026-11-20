<?php

/*
| Agent sites: landing pages and agent domains.
| An agent's domain must point at the frontend before it goes live. Admins see these values as
| the DNS instructions. Set the ones your host gives you:
|   Vercel:      AGENT_DOMAIN_A_RECORDS=76.76.21.21   AGENT_DOMAIN_CNAME_TARGET=cname.vercel-dns.com
|   Cloudflare:  AGENT_DOMAIN_CNAME_TARGET=<your SaaS fallback origin, e.g. cards.digicardo.in>
*/
return [
    'a_records' => array_values(array_filter(array_map('trim', explode(',', (string) env('AGENT_DOMAIN_A_RECORDS', ''))))),
    'cname_target' => strtolower(trim((string) env('AGENT_DOMAIN_CNAME_TARGET', ''))),
    // Redirect digicardo/{username} to the agent's domain for agent customers' cards.
    'redirect_cards' => (bool) env('AGENT_DOMAIN_REDIRECTS', true),
];
