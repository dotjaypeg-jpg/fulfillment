<?php

namespace Chargefield\Fulfillment;

/**
 * Provider-neutral fulfilment status. Each provider maps its own vocabulary
 * (Gelato "printed", Printful "inprocess", …) onto these shared cases so the
 * rest of the app never has to know which provider produced an order.
 */
enum FulfillmentStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case InProduction = 'in_production';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
    case Unknown = 'unknown';
}
