<?php

return [
    'endpoint' => env('NEXAR_ENDPOINT', 'https://api.nexar.com/graphql/'),
    'identity_endpoint' => env('NEXAR_IDENTITY_ENDPOINT', 'https://identity.nexar.com/connect/token'),
    'client_id' => env('NEXAR_CLIENT_ID'),
    'client_secret' => env('NEXAR_CLIENT_SECRET'),
    'token' => env('NEXAR_SUPPLY_TOKEN'),
    //'current_internal_organization_id' => null, // This is used to determine the current organization for the Zoho API calls
    //and should dynamically set based on the organization context in your application.

];
