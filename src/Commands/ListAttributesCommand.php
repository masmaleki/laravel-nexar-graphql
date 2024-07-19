<?php

namespace NexarGraphQL\Commands;

use Illuminate\Console\Command;
use NexarGraphQL\Services\NexarGraphQLService;

class ListAttributesCommand extends Command
{
    protected $signature = 'nexar:list-attributes';
    protected $description = 'List part attributes from Nexar';

    protected $nexar;

    public function __construct(NexarGraphQLService $nexar)
    {
        parent::__construct();
        $this->nexar = $nexar;
    }

    public function handle()
    {
        $query = 'query ListAttributes { supAttributes { id name shortname group unitsName unitsSymbol } }';
        $response = $this->nexar->query($query);

        if (isset($response['data']['supAttributes'])) {
            $this->table(['ID', 'Name', 'Shortname', 'Group', 'Units Name', 'Units Symbol'], $response['data']['supAttributes']);
        } else {
            $this->error('Error fetching attributes.');
        }
    }
}
