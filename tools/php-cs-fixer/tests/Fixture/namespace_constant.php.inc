<?php

declare(strict_types=1);

namespace Example;

/** @api */
const LIMIT = 16;

function limit(): int
{
    /** @var positive-int $limit */
    $limit = LIMIT;

    return $limit;
}
-----
<?php

declare(strict_types=1);

namespace Example;

/**
 * @api
 */
const LIMIT = 16;

function limit(): int
{
    /** @var positive-int $limit */
    $limit = LIMIT;

    return $limit;
}
