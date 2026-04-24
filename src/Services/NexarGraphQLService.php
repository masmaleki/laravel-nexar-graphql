<?php

namespace NexarGraphQL\Services;

use Carbon\CarbonInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use NexarGraphQL\App\Models\NexarToken;
use RuntimeException;
use Throwable;

class NexarGraphQLService
{
    protected Client $client;

    /** Cached supply token in memory for the lifetime of this instance. */
    protected ?string $token = null;

    protected int|string|null $organizationId;
    protected ?string $client_id;
    protected ?string $client_secret;
    protected ?string $identity_endpoint;
    protected ?string $nexar_endpoint;

    /** Default Guzzle timeouts; keep tight so a Nexar outage cannot hang requests. */
    protected float $connectTimeoutSeconds = 5.0;
    protected float $requestTimeoutSeconds = 15.0;

    public function __construct(
        ?string $client_id = null,
        ?string $client_secret = null,
        ?string $identity_endpoint = null,
        ?string $nexar_endpoint = null,
        int|string|null $organizationId = null
    ) {
        $this->organizationId = $organizationId
            ?? config('nexar.current_internal_organization_id', null);

        $this->client_id = $client_id
            ?? config('nexar.client_id_' . $this->organizationId)
            ?? config('nexar.client_id');

        $this->client_secret = $client_secret
            ?? config('nexar.client_secret_' . $this->organizationId)
            ?? config('nexar.client_secret');

        $this->identity_endpoint = $identity_endpoint
            ?? config('nexar.identity_endpoint');

        $this->nexar_endpoint = $nexar_endpoint
            ?? config('nexar.endpoint');

        $this->client = new Client([
            'connect_timeout' => $this->connectTimeoutSeconds,
            'timeout' => $this->requestTimeoutSeconds,
            'http_errors' => true,
        ]);
    }

    /**
     * Fetch (or reuse) a Nexar supply token.
     *
     * Returns null if credentials are not configured or the identity
     * endpoint refused the request — callers MUST handle the null path
     * rather than crashing on missing tokens.
     */
    protected function getToken(): ?string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        if (! $this->hasCredentials()) {
            Log::warning('Nexar credentials missing for organization', [
                'organization_id' => $this->organizationId,
                'has_identity_endpoint' => (bool) $this->identity_endpoint,
            ]);

            return null;
        }

        $cacheKey = $this->cacheKey();

        if (Cache::has($cacheKey)) {
            $tokenData = Cache::get($cacheKey);
            if (is_array($tokenData) && isset($tokenData['token'], $tokenData['expires_at'])) {
                $expiresAt = $this->normalizeExpiresAt($tokenData['expires_at']);
                if ($expiresAt && $expiresAt->isFuture()) {
                    return $this->token = (string) $tokenData['token'];
                }
            }
        }

        try {
            $response = $this->client->post(
                $this->identity_endpoint,
                [
                    'form_params' => [
                        'grant_type' => 'client_credentials',
                        'client_id' => $this->client_id,
                        'client_secret' => $this->client_secret,
                        'scope' => 'supply.domain',
                    ],
                ]
            );
        } catch (BadResponseException $e) {
            $status = $e->getResponse()?->getStatusCode();
            $body = (string) ($e->getResponse()?->getBody() ?? '');

            Log::error('Nexar identity endpoint rejected the credentials', [
                'organization_id' => $this->organizationId,
                'status' => $status,
                'body' => mb_substr($body, 0, 500),
            ]);

            return null;
        } catch (ConnectException|RequestException $e) {
            Log::warning('Nexar identity endpoint unreachable', [
                'organization_id' => $this->organizationId,
                'message' => $e->getMessage(),
            ]);

            return null;
        } catch (GuzzleException|Throwable $e) {
            Log::error('Unexpected error while requesting Nexar supply token', [
                'organization_id' => $this->organizationId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if (! is_array($data) || empty($data['access_token'])) {
            Log::error('Nexar identity endpoint returned a token-less payload', [
                'organization_id' => $this->organizationId,
                'body_excerpt' => mb_substr($body, 0, 200),
            ]);

            return null;
        }

        $token = (string) $data['access_token'];
        $expiresIn = (int) ($data['expires_in'] ?? 3600);
        if ($expiresIn <= 0) {
            $expiresIn = 3600;
        }

        $expiresAt = Carbon::now()->addSeconds($expiresIn);
        $scope = (string) ($data['scope'] ?? 'supply.domain');

        $this->persistToken($token, $expiresAt, $expiresIn, $scope);

        // Cache for slightly less than the actual TTL so we re-issue
        // before downstream Nexar calls start seeing 401s.
        $cacheTtl = max(60, $expiresIn - 60);

        Cache::put($cacheKey, [
            'token' => $token,
            'expires_at' => $expiresAt,
        ], $cacheTtl);

        return $this->token = $token;
    }

    /**
     * Public accessor — returns the current supply token, or null on failure.
     */
    public function getSupplyToken(): ?string
    {
        return $this->getToken();
    }

    /**
     * Force-refresh the supply token (useful when downstream sees 401).
     */
    public function refreshSupplyToken(): ?string
    {
        $this->token = null;
        Cache::forget($this->cacheKey());

        return $this->getToken();
    }

    protected function persistToken(string $token, CarbonInterface $expiresAt, int $expiresIn, string $scope): void
    {
        // The DB row is purely an audit trail. If the migration hasn't
        // been run yet (fresh install / partial deploy), don't take the
        // whole request down — log and continue using the cached token.
        try {
            NexarToken::create([
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'organization_id' => $this->organizationId,
                'supply_token' => $token,
                'expires_at' => $expiresAt,
                'expires_in' => $expiresIn,
                'scope' => $scope,
            ]);
        } catch (Throwable $e) {
            Log::warning('Could not persist nexar_tokens audit row (continuing with in-memory token)', [
                'organization_id' => $this->organizationId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function hasCredentials(): bool
    {
        return is_string($this->client_id) && $this->client_id !== ''
            && is_string($this->client_secret) && $this->client_secret !== ''
            && is_string($this->identity_endpoint) && $this->identity_endpoint !== ''
            && is_string($this->nexar_endpoint) && $this->nexar_endpoint !== '';
    }

    protected function cacheKey(): string
    {
        return 'nexar_token_' . ($this->client_id ?? 'noid') . '_' . ($this->organizationId ?? 'noorg');
    }

    /**
     * Cache drivers may serialize Carbon instances or store stringified
     * dates. Normalize either form to a Carbon instance so the future-check
     * is reliable across drivers (file/redis/memcached/array).
     */
    protected function normalizeExpiresAt(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (is_string($value) || is_numeric($value)) {
            try {
                return Carbon::parse($value);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<mixed>
     *
     * @throws RuntimeException when no supply token can be obtained.
     */
    protected function query(string $query, array $variables = []): array
    {
        $token = $this->getToken();
        if ($token === null) {
            throw new RuntimeException(
                'Nexar supply token unavailable for organization ' . ($this->organizationId ?? 'n/a')
            );
        }

        try {
            $response = $this->client->post(
                $this->nexar_endpoint,
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'query' => $query,
                        'variables' => (object) $variables,
                    ],
                ]
            );
        } catch (BadResponseException $e) {
            // 401 most commonly means the cached token expired between
            // our TTL check and Nexar's enforcement window. Refresh once
            // and retry transparently.
            if ($e->getResponse()?->getStatusCode() === 401) {
                $token = $this->refreshSupplyToken();
                if ($token !== null) {
                    $response = $this->client->post(
                        $this->nexar_endpoint,
                        [
                            'headers' => [
                                'Authorization' => 'Bearer ' . $token,
                                'Content-Type' => 'application/json',
                            ],
                            'json' => [
                                'query' => $query,
                                'variables' => (object) $variables,
                            ],
                        ]
                    );
                } else {
                    throw $e;
                }
            } else {
                throw $e;
            }
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function listAttributes()
    {
        $query = <<<GQL
query ListAttributes {
    supAttributes {
        id
        name
        shortname
        group
        unitsName
        unitsSymbol
    }
}
GQL;
        return $this->query($query);
    }

    public function listManufacturers()
    {
        $query = <<<GQL
query ListManufacturers {
    supManufacturers {
        id
        name
        slug
        isDistributorApi
        isVerified
        aliases
        displayFlag
        homepageUrl
    }
}
GQL;
        return $this->query($query);
    }

    public function manufacturersByIds($manufacturerIDs)
    {
        $query = <<<GQL
query ManufacturersByIDs (\$manufacturerIDs: [String!]) {
    supManufacturers (ids: \$manufacturerIDs) {
        id
        name
        slug
        isDistributorApi
        isVerified
        aliases
        displayFlag
        homepageUrl
    }
}
GQL;
        $variables = ['manufacturerIDs' => $manufacturerIDs];
        return $this->query($query, $variables);
    }

    public function listDistributors()
    {
        $query = <<<GQL
query ListDistributors {
    supSellers {
        id
        name
        slug
        isDistributorApi
        isVerified
        aliases
        displayFlag
        homepageUrl
    }
}
GQL;
        return $this->query($query);
    }

    public function distributorsByIds($distributorIDs)
    {
        $query = <<<GQL
query DistributorsByIDs (\$distributorIDs: [String!]) {
    supSellers (ids: \$distributorIDs) {
        id
        name
        slug
        isDistributorApi
        isVerified
        aliases
        displayFlag
        homepageUrl
    }
}
GQL;
        $variables = ['distributorIDs' => $distributorIDs];
        return $this->query($query, $variables);
    }

    public function listCategories()
    {
        $query = <<<GQL
query ListCategories {
    supCategories {
        id
        name
        parentId
        path
        numParts
    }
}
GQL;
        return $this->query($query);
    }

    public function categoriesByIds($categoryIDs)
    {
        $query = <<<GQL
query CategoriesByIDs (\$categoryIDs: [String!]) {
    supCategories (ids: \$categoryIDs) {
        id
        name
        parentId
        path
        numParts
    }
}
GQL;
        $variables = ['categoryIDs' => $categoryIDs];
        return $this->query($query, $variables);
    }

    public function categoriesByPaths($categoryPaths)
    {
        $query = <<<GQL
query CategoriesByPaths (\$categoryPaths: [String!]) {
    supCategories (paths: \$categoryPaths) {
        id
        name
        parentId
        path
        numParts
    }
}
GQL;
        $variables = ['categoryPaths' => $categoryPaths];
        return $this->query($query, $variables);
    }

    public function basicSearch($searchTerm, $limit = 2)
    {
        $query = <<<GQL
query BasicSearch (\$searchTerm: String!, \$limit: Int = 2) {
    supSearch (q: \$searchTerm, limit: \$limit) {
        hits
        results {
            part {
                id
                name
                mpn
                shortDescription
                manufacturer {
                    name
                }
            }
        }
    }
}
GQL;
        $variables = ['searchTerm' => $searchTerm, 'limit' => $limit];
        return $this->query($query, $variables);
    }

    public function basicSearchWithPaging($searchTerm, $limit, $start)
    {
        $query = <<<GQL
query BasicSearch (\$searchTerm: String!, \$limit: Int, \$start: Int) {
    supSearch (q: \$searchTerm, limit: \$limit, start: \$start) {
        hits
        results {
            part {
                id
                name
                mpn
                shortDescription
                manufacturer {
                    name
                }
            }
        }
    }
}
GQL;
        $variables = ['searchTerm' => $searchTerm, 'limit' => $limit, 'start' => $start];
        return $this->query($query, $variables);
    }

    public function basicMPNSearch($searchTerm, $limit = 2)
    {
        $query = <<<GQL
query BasicMPNSearch (\$searchTerm: String!, \$limit: Int = 2) {
    supSearchMpn (q: \$searchTerm, limit: \$limit) {
        hits
        results {
            part {
                id
                name
                mpn
                shortDescription
                manufacturer {
                    name
                }
            }
        }
    }
}
GQL;
        $variables = ['searchTerm' => $searchTerm, 'limit' => $limit];
        return $this->query($query, $variables);
    }

    public function basicMPNSearchWithPaging($searchTerm, $limit, $start)
    {
        $query = <<<GQL
query BasicMPNSearch (\$searchTerm: String!, \$limit: Int, \$start: Int) {
    supSearchMpn (q: \$searchTerm, limit: \$limit, start: \$start) {
        hits
        results {
            part {
                id
                name
                mpn
                shortDescription
                manufacturer {
                    name
                }
            }
        }
    }
}
GQL;
        $variables = ['searchTerm' => $searchTerm, 'limit' => $limit, 'start' => $start];
        return $this->query($query, $variables);
    }

    public function searchSuggestions($searchTerm)
    {
        $query = <<<GQL
query SearchSuggestions (\$searchTerm: String!) {
    supSuggest (q: \$searchTerm) {
        text
        inCategoryId
        inCategoryName
    }
}
GQL;
        $variables = ['searchTerm' => $searchTerm];
        return $this->query($query, $variables);
    }

    public function partSearchSuggestionsByCategory($searchTerm, $categoryID)
    {
        $query = <<<GQL
query PartSearchSuggestionsByCategory (\$searchTerm: String!, \$categoryID: String) {
    supSuggest (q: \$searchTerm, partNumbersOnly: true, categoryId: \$categoryID) {
        text
        inCategoryId
        inCategoryName
    }
}
GQL;
        $variables = ['searchTerm' => $searchTerm, 'categoryID' => $categoryID];
        return $this->query($query, $variables);
    }

    public function basicAggregations($searchTerm)
    {
        $query = <<<GQL
query BasicAggregations (\$searchTerm: String!) {
    supSearch (q: \$searchTerm) {
        categoryAgg {
            category {
                name
            }
            count
        }
        manufacturerAgg {
            company {
                name
            }
            count
        }
        distributorAgg {
            company {
                name
            }
            count
        }
        suggestedCategories {
            category {
                name
            }
            count
        }
        suggestedFilters {
            id
            name
            shortname
        }
    }
}
GQL;
        $variables = ['searchTerm' => $searchTerm];
        return $this->query($query, $variables);
    }

    public function aggregationsForSpecs($searchTerm, $attributes)
    {
        $query = <<<GQL
query AggregationsForSpecs (\$searchTerm: String!, \$attributes: [String!]!) {
    supSearch (q: \$searchTerm) {
        specAggs (attributeNames: \$attributes) {
            attribute {
                name
            }
            buckets {
                displayValue
                count
            }
            displayMin
            displayMax
        }
    }
}
GQL;
        $variables = ['searchTerm' => $searchTerm, 'attributes' => $attributes];
        return $this->query($query, $variables);
    }

    public function spellingCorrection($searchTerm)
    {
        $query = <<<GQL
query SpellingCorrection (\$searchTerm: String!) {
    supSpellingCorrection (q: \$searchTerm) {
        correctionString
        hits
    }
}
GQL;
        $variables = ['searchTerm' => $searchTerm];
        return $this->query($query, $variables);
    }

    public function filterByManufacturer($searchTerm, $limit, $filters)
    {
        $query = <<<GQL
query FilterByManufacturer (\$searchTerm: String!, \$limit: Int, \$filters: Map) {
    supSearchMpn (q: \$searchTerm, limit: \$limit, filters: \$filters) {
        hits
        results {
            part {
                id
                name
                mpn
                shortDescription
                manufacturer {
                    name
                }
            }
        }
    }
}
GQL;
        $variables = ['searchTerm' => $searchTerm, 'limit' => $limit, 'filters' => (object)$filters];
        return $this->query($query, $variables);
    }

    public function filterByDistributor($searchTerm, $limit, $filters)
    {
        $query = <<<GQL
query FilterByDistributor (\$searchTerm: String!, \$limit: Int, \$filters: Map) {
    supSearchMpn (q: \$searchTerm, limit: \$limit, filters: \$filters) {
        hits
        results {
            part {
                id
                name
                mpn
                shortDescription
                manufacturer {
                    name
                }
                sellers {
                    company {
                        name
                    }
                }
            }
        }
    }
}
GQL;
        $variables = ['searchTerm' => $searchTerm, 'limit' => $limit, 'filters' => (object)$filters];
        return $this->query($query, $variables);
    }

    public function filterByPartCategory($searchTerm, $limit, $filters)
    {
        $query = <<<GQL
query FilterByPartCategory (\$searchTerm: String!, \$limit: Int, \$filters: Map) {
    supSearchMpn (q: \$searchTerm, limit: \$limit, filters: \$filters) {
        hits
        results {
            part {
                id
                name
                mpn
                shortDescription
                category {
                    id
                    name
                }
                manufacturer {
                    name
                }
            }
        }
    }
}
GQL;
        $variables = ['searchTerm' => $searchTerm, 'limit' => $limit, 'filters' => (object)$filters];
        return $this->query($query, $variables);
    }

    public function filterByTechSpec($searchTerm, $limit, $filters)
    {
        $query = <<<GQL
query FilterByTechSpec (\$searchTerm: String!, \$limit: Int, \$filters: Map) {
    supSearchMpn (q: \$searchTerm, limit: \$limit, filters: \$filters) {
        hits
        results {
            part {
                id
                name
                mpn
                specs {
                    attribute {
                        name
                        shortname
                    }
                    displayValue
                }
            }
        }
    }
}
GQL;
        $variables = ['searchTerm' => $searchTerm, 'limit' => $limit, 'filters' => (object)$filters];
        return $this->query($query, $variables);
    }

    public function partSpecs($searchTerm, $limit)
    {
        $query = <<<GQL
query PartSpecs (\$searchTerm: String!, \$limit: Int) {
    supSearchMpn (q: \$searchTerm, limit: \$limit) {
        hits
        results {
            part {
                freeSampleUrl
                category {
                    id
                    parentId
                    name
                    ancestors {
                        id
                        parentId
                        name
                        numParts
                        blurb {
                            name
                            description
                            content
                            metaTitle
                            pathName
                            metaDescription
                        }
                        path
                    }
                    children {
                        id
                        parentId
                        name
                        numParts
                        blurb {
                            name
                            description
                            content
                            metaTitle
                            pathName
                            metaDescription
                        }
                        path
                    }
                    numParts
                    blurb {
                        name
                        description
                        content
                        metaTitle
                        pathName
                        metaDescription
                    }
                    path
                }

                akaMpns
                id
                name
                mpn
                manufacturer {
                    aliases
                    name
                    id
                }
                specs {
                    attribute {
                        name
                        id
                        shortname
                        unitsName
                        valueType
                        group
                    }
                    value
                    siValue
                    units
                    unitsName
                    unitsSymbol
                    displayValue
                }
            }
        }
    }
}
GQL;
        $variables = ['searchTerm' => $searchTerm, 'limit' => $limit];
        return $this->query($query, $variables);
    }

    public function sortingBySpec($searchTerm, $limit, $sortBy, $sortDir)
    {
        $query = <<<GQL
query SortingBySpec (\$searchTerm: String!, \$limit: Int, \$sortBy: String, \$sortDir: SupSortDirection) {
    supSearchMpn (q: \$searchTerm, limit: \$limit, sort: \$sortBy, sortDir: \$sortDir) {
        hits
        results {
            part {
                id
                name
                mpn
                specs {
                    attribute {
                        name
                        shortname
                    }
                    displayValue
                }
            }
        }
    }
}
GQL;
        $variables = ['searchTerm' => $searchTerm, 'limit' => $limit, 'sortBy' => $sortBy, 'sortDir' => $sortDir];
        return $this->query($query, $variables);
    }

    public function basicQuery($searchTerm, $limit, $inStockOnly)
    {
        $query = <<<GQL
query BasicQuery (\$searchTerm: String!, \$limit: Int, \$inStockOnly: Boolean) {
    supSearchMpn (q: \$searchTerm, limit: \$limit, inStockOnly: \$inStockOnly) {
        hits
        results {
            part {
                id
                name
                mpn
                sellers {
                    company {
                        name
                    }
                    offers {
                        id
                        moq
                        packaging
                        clickUrl
                        prices {
                            quantity
                            price
                            currency
                        }
                    }
                }
            }
        }
    }
}
GQL;
        $variables = ['searchTerm' => $searchTerm, 'limit' => $limit, 'inStockOnly' => $inStockOnly];
        return $this->query($query, $variables);
    }

    public function settingCountryAndCurrency($searchTerm, $limit, $inStockOnly, $countryCode, $currencyCode)
    {
        $query = <<<GQL
query BasicQuery (\$searchTerm: String!, \$limit: Int, \$inStockOnly: Boolean, \$countryCode: String!, \$currencyCode: String!) {
    supSearchMpn (q: \$searchTerm, limit: \$limit, inStockOnly: \$inStockOnly, country: \$countryCode, currency: \$currencyCode) {
        hits
        results {
            part {
                id
                name
                mpn
                sellers {
                    company {
                        name
                    }
                    offers {
                        id
                        moq
                        packaging
                        clickUrl
                        prices {
                            quantity
                            price
                            currency
                            convertedPrice
                            convertedCurrency
                        }
                    }
                }
            }
        }
    }
}
GQL;
        $variables = [
            'searchTerm' => $searchTerm,
            'limit' => $limit,
            'inStockOnly' => $inStockOnly,
            'countryCode' => $countryCode,
            'currencyCode' => $currencyCode
        ];
        return $this->query($query, $variables);
    }

    public function mpnSearch($searchTerm, $country, $currency, $filters, $inStockOnly, $limit, $start)
    {
        $query = <<<GQL
query MPNSearch (\$searchTerm: String!, \$country: String!, \$currency: String!, \$filters: Map, \$inStockOnly: Boolean, \$limit: Int, \$start: Int) {
    supSearchMpn (q: \$searchTerm, country: \$country, currency: \$currency, filters: \$filters, inStockOnly: \$inStockOnly, limit: \$limit, start: \$start) {
        hits
        categoryAgg {
            category {
                id
                name
            }
            count
        }
        manufacturerAgg {
            company {
                id
                name
            }
            count
        }
        distributorAgg {
            company {
                id
                name
                displayFlag
            }
            count
        }
        results {
            part {
                freeSampleUrl
                category {
                    id
                    parentId
                    name
                    ancestors {
                        id
                        parentId
                        name
                        numParts
                        blurb {
                            name
                            description
                            content
                            metaTitle
                            pathName
                            metaDescription
                        }
                        path
                    }
                    children {
                        id
                        parentId
                        name
                        numParts
                        blurb {
                            name
                            description
                            content
                            metaTitle
                            pathName
                            metaDescription
                        }
                        path
                    }
                    numParts
                    blurb {
                        name
                        description
                        content
                        metaTitle
                        pathName
                        metaDescription
                    }
                    path
                }
                akaMpns
                id
                name
                mpn
                shortDescription
                manufacturer {
                    aliases
                    name
                    id
                    displayFlag
                }
                medianPrice1000 {
                    quantity
                    currency
                    price
                    conversionRate
                    convertedCurrency
                    convertedPrice
                }
                bestDatasheet {
                    name
                    creditString
                    creditUrl
                    url
                }
                bestImage{
                    url
                  }
                images{
                    url
                  }
                manufacturerUrl
                specs {
                    attribute {
                        name
                        id
                        shortname
                        unitsName
                        valueType
                        group
                    }
                    value
                    siValue
                    units
                    unitsName
                    unitsSymbol
                    displayValue
                }
                sellers (
                    authorizedOnly:true
                    includeBrokers:false
                    ){
                    company {
                        id
                        name
                        isVerified
                        homepageUrl
                    }
                    isAuthorized
                    offers {
                        id
                        sku
                        inventoryLevel
                        clickUrl
                        moq
                        packaging
                        updated
                        prices {
                            quantity
                            currency
                            price
                            conversionRate
                            convertedCurrency
                            convertedPrice
                        }
                    }
                }
            }
        }
    }
}
GQL;
        $variables = [
            'searchTerm' => $searchTerm,
            'country' => $country,
            'currency' => $currency,
            'filters' => (object)$filters,
            'inStockOnly' => $inStockOnly,
            'limit' => $limit,
            'start' => $start
        ];
        return $this->query($query, $variables);
    }
    //general serach by mpn or specs or name or description
    public function genralSearch($searchTerm, $country, $currency, $filters, $inStockOnly, $limit, $start)
    {
        $query = <<<GQL
        query supSearch (\$searchTerm: String!, \$country: String!, \$currency: String!, \$filters: Map, \$inStockOnly: Boolean, \$limit: Int, \$start: Int) {
            supSearch (q: \$searchTerm, country: \$country, currency: \$currency, filters: \$filters, inStockOnly: \$inStockOnly, limit: \$limit, start: \$start) {
                hits
                categoryAgg {
                    category {
                        id
                        name
                    }
                    count
                }
                manufacturerAgg {
                    company {
                        id
                        name
                    }
                    count
                }
                distributorAgg {
                    company {
                        id
                        name
                        displayFlag
                    }
                    count
                }
                results {
                    akaMpn
                    description
                    part {
                        similarParts {
                            #For this query, we have chosen to return the similar part names, the octopartURL & MPN 
                            #Press CTRL+space to find out what else you can return
                            freeSampleUrl
                            category {
                                id
                                parentId
                                name
                                ancestors {
                                    id
                                    parentId
                                    name
                                    numParts
                                    blurb {
                                        name
                                        description
                                        content
                                        metaTitle
                                        pathName
                                        metaDescription
                                    }
                                    path
                                }
                                children {
                                    id
                                    parentId
                                    name
                                    numParts
                                    blurb {
                                        name
                                        description
                                        content
                                        metaTitle
                                        pathName
                                        metaDescription
                                    }
                                    path
                                }
                                numParts
                                blurb {
                                    name
                                    description
                                    content
                                    metaTitle
                                    pathName
                                    metaDescription
                                }
                                path
                            }
                            akaMpns
                            id
                            name
                            mpn
                            shortDescription
                            manufacturer {
                                aliases
                                name
                                id
                                displayFlag
                            }
                            medianPrice1000 {
                                quantity
                                currency
                                price
                                conversionRate
                                convertedCurrency
                                convertedPrice
                            }
                            bestDatasheet {
                                name
                                creditString
                                creditUrl
                                url
                            }
                            bestImage{
                                url
                            }
                            images{
                                url
                            }
                            manufacturerUrl
                            specs {
                                attribute {
                                    name
                                    id
                                    shortname
                                    unitsName
                                    valueType
                                    group
                                }
                                value
                                siValue
                                units
                                unitsName
                                unitsSymbol
                                displayValue
                            }
                            sellers (
                                authorizedOnly:true
                                includeBrokers:false
                                ){
                                company {
                                    id
                                    name
                                    isVerified
                                    homepageUrl
                                }
                                isAuthorized
                                offers {
                                    id
                                    sku
                                    inventoryLevel
                                    clickUrl
                                    moq
                                    packaging
                                    updated
                                    prices {
                                        quantity
                                        currency
                                        price
                                        conversionRate
                                        convertedCurrency
                                        convertedPrice
                                    }
                                }
                            }
                        }
                        freeSampleUrl
                        category {
                            id
                            parentId
                            name
                            ancestors {
                                id
                                parentId
                                name
                                numParts
                                blurb {
                                    name
                                    description
                                    content
                                    metaTitle
                                    pathName
                                    metaDescription
                                }
                                path
                            }
                            children {
                                id
                                parentId
                                name
                                numParts
                                blurb {
                                    name
                                    description
                                    content
                                    metaTitle
                                    pathName
                                    metaDescription
                                }
                                path
                            }
                            numParts
                            blurb {
                                name
                                description
                                content
                                metaTitle
                                pathName
                                metaDescription
                            }
                            path
                        }
                        akaMpns
                        id
                        name
                        mpn
                        shortDescription
                        manufacturer {
                            aliases
                            name
                            id
                            displayFlag
                        }
                        medianPrice1000 {
                            quantity
                            currency
                            price
                            conversionRate
                            convertedCurrency
                            convertedPrice
                        }
                        bestDatasheet {
                            name
                            creditString
                            creditUrl
                            url
                        }
                        bestImage{
                            url
                        }
                        images{
                            url
                        }
                        manufacturerUrl
                        specs {
                            attribute {
                                name
                                id
                                shortname
                                unitsName
                                valueType
                                group
                            }
                            value
                            siValue
                            units
                            unitsName
                            unitsSymbol
                            displayValue
                        }
                        sellers (
                            authorizedOnly:true
                            includeBrokers:false
                            ){
                            company {
                                id
                                name
                                isVerified
                                homepageUrl
                            }
                            isAuthorized
                            offers {
                                id
                                sku
                                inventoryLevel
                                clickUrl
                                moq
                                packaging
                                updated
                                factoryLeadDays
                                prices {
                                    quantity
                                    currency
                                    price
                                    conversionRate
                                    convertedCurrency
                                    convertedPrice
                                }
                            }
                        }
                    }
                }
            }
        }
        GQL;
        $variables = [
            'searchTerm' => $searchTerm,
            'country' => $country,
            'currency' => $currency,
            'filters' => (object)$filters,
            'inStockOnly' => $inStockOnly,
            'limit' => $limit,
            'start' => $start
        ];
        return $this->query($query, $variables);
    }


    public function multiMPNSearch($country, $currency, $requireStockAvailable, $filters, $queries)
    {
        $query = <<<GQL
query MultiMPNSearch (\$country: String!, \$currency: String!, \$requireStockAvailable: Boolean, \$filters: Map, \$queries: [SupPartMatchQuery!]!) {
    supMultiMatch (country: \$country, currency: \$currency, options: { requireStockAvailable: \$requireStockAvailable, filters: \$filters }, queries: \$queries) {
        hits
        parts {
            id
            name
            mpn
            shortDescription
            manufacturer {
                name
                displayFlag
            }
            medianPrice1000 {
                quantity
                currency
                price
                conversionRate
                convertedCurrency
                convertedPrice
            }
            bestDatasheet {
                name
                creditString
                creditUrl
                url
            }
            manufacturerUrl
            cad {
                has3dModel
            }
            specs {
                attribute {
                    id
                    name
                    shortname
                }
                displayValue
            }
            sellers {
                company {
                    id
                    name
                    homepageUrl
                }
                isAuthorized
                offers {
                    id
                    sku
                    inventoryLevel
                    clickUrl
                    moq
                    packaging
                    updated
                    prices {
                        quantity
                        currency
                        price
                        conversionRate
                        convertedCurrency
                        convertedPrice
                    }
                }
            }
        }
    }
}
GQL;
        $variables = [
            'country' => $country,
            'currency' => $currency,
            'requireStockAvailable' => $requireStockAvailable,
            'filters' => (object)$filters,
            'queries' => $queries
        ];
        return $this->query($query, $variables);
    }


    public function availabilities($mpn, $filters, $limit)
    {
        $query = <<<GQL

        #Returns the total availability of a component on the market in a specified country
        query totalAvailability (\$mpn: String!, \$limit: Int!, \$filters: Map) { 
          supSearchMpn(
            #The value can be a partial match - the MPN of the parts returned all contain "acs770"
            #Change the value "acs770" to return a part of your own
            q: \$mpn,    
            #Total availability defaults to US. Set your ISO country code below
            filters: \$filters,
            limit: \$limit
            currency: "USD"
            ){
            results {
              description
              part {
              #For this query, we return the part total availability & full MPN
                #Press CTRL+space to find out what else you can return
                mpn
                shortDescription
                manufacturer{
                  id
                  name
                }
                sellers (
                    authorizedOnly:true
                    includeBrokers:false
                ){
                  company{
                    name
                    isVerified
                  }
                  offers{
                    id
                    sku
                    packaging
                    moq
                    inventoryLevel
                    updated
                    prices{
                      quantity
                      price
                      currency
                      convertedPrice
                      conversionRate
                      convertedCurrency
                    }
                  }
                }
              }
            }
          }
        }
GQL;
        $variables = [
            'mpn' => $mpn,
            'limit' => $limit,
            'filters' => (object)$filters
        ];
        return $this->query($query, $variables);
    }
}
