<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * Nothing can run and nothing can wake a waiting fiber: no timer, stream or signal is waited for.
 * Thrown into the wait of the fiber that runs the scheduler, a group in {main} or `virtualTime()`,
 * with the place of every suspended fiber in the message. A group fails with it and cancels its tasks;
 * if they still cannot finish, it gives up and throws it.
 *
 * @api
 */
final class Deadlock extends \Error {}
