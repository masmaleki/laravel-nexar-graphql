<?php

return [
    'endpoint' => env('NEXAR_ENDPOINT', 'https://api.nexar.com/graphql/'),
    'identity_endpoint' => env('NEXAR_IDENTITY_ENDPOINT', 'https://identity.nexar.com/connect/token'),
    'client_id' => env('NEXAR_CLIENT_ID'),
    'client_secret' => env('NEXAR_CLIENT_SECRET'),
    'token' => env('NEXAR_SUPPLY_TOKEN')
];
