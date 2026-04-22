<?php

namespace NexarGraphQL\Facades;

use Illuminate\Support\Facades\Facade;

class NexarGraphQL extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'NexarGraphQL';
    }
}
