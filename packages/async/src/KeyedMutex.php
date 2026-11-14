<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * A {@see KeyedSemaphore} with one permit per key: at most one holder of a key at a time.
 *
 * It adds no methods, only the guarantee, like {@see Mutex}.
 *
 * {@see LocalKeyedMutex} is the in-process implementation.
 *
 * @api
 */
interface KeyedMutex extends KeyedSemaphore {}
