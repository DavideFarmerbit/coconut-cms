<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Entities;

use XyloIsCoding\CoconutCms\Persistence\Query\Order;

/** Which way entities.query sorts by one path, as its input names it. */
enum SortDirection: string
{
    case Ascending = 'ascending';
    case Descending = 'descending';

    /** The query's own order. */
    public function order(): Order {
        return match ($this) {
            self::Ascending => Order::Ascending,
            self::Descending => Order::Descending,
        };
    }
}
