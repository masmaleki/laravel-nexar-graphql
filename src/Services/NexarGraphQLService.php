<?php

namespace NexarGraphQL\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;

class NexarGraphQLService
{
    protected $client;
    protected $token;

    public function __construct()
    {
        $this->client = new Client();
        $this->token = $this->getToken();
    }

    protected function getToken()
    {
        if (Cache::has('nexar_token')) {
            return Cache::get('nexar_token');
        }

        $response = $this->client->post(config('nexar.identity_endpoint'), [
            'form_params' => [
                'grant_type' => 'client_credentials',
                'client_id' => config('nexar.client_id'),
                'client_secret' => config('nexar.client_secret'),
                'scope' => 'supply.domain'
            ]
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $token = $data['access_token'];
        $expiresIn = $data['expires_in'];

        Cache::put('nexar_token', $token, $expiresIn / 60);

        return $token;
    }

    protected function query($query, $variables = [])
    {
        $response = $this->client->post(config('nexar.endpoint'), [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json'
            ],
            'json' => [
                'query' => $query,
                'variables' => $variables
            ]
        ]);

        return json_decode($response->getBody()->getContents(), true);
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
        $variables = ['searchTerm' => $searchTerm, 'limit' => $limit, 'filters' => $filters];
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
        $variables = ['searchTerm' => $searchTerm, 'limit' => $limit, 'filters' => $filters];
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
        $variables = ['searchTerm' => $searchTerm, 'limit' => $limit, 'filters' => $filters];
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
        $variables = ['searchTerm' => $searchTerm, 'limit' => $limit, 'filters' => $filters];
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
}
GQL;
        $variables = [
            'searchTerm' => $searchTerm,
            'country' => $country,
            'currency' => $currency,
            'filters' => $filters,
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
            'filters' => $filters,
            'queries' => $queries
        ];
        return $this->query($query, $variables);
    }
}