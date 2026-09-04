<?php

return [
    'client_id' => env('LINKEDIN_CLIENT_ID'),
    'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
    'redirect_uri' => env('LINKEDIN_REDIRECT_URI'),
    'api_url' => rtrim((string) env('LINKEDIN_API_URL', 'https://api.linkedin.com'), '/'),
    'oauth_url' => rtrim((string) env('LINKEDIN_OAUTH_URL', 'https://www.linkedin.com'), '/'),
    'version' => (string) env('LINKEDIN_API_VERSION', '202606'),
    'scopes' => array_values(array_filter(explode(',', (string) env(
        'LINKEDIN_SCOPES',
        'openid,profile,r_organization_admin,w_organization_social'
    )))),
];
