<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * A {@see Semaphore} with one permit: at most one holder at a time.
 *
 * It adds no methods, only the guarantee. Accept a Mutex where exclusive access is the point
 * (a migration that must not run twice, a resource shared by several objects), so that a semaphore
 * with several permits cannot be passed by mistake.
 *
 * {@see LocalMutex} is the in-process implementation.
 *
 * @api
 */
interface Mutex extends Semaphore {}
