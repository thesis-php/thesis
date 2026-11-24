<?php

declare(strict_types=1);

namespace Thesis\Http;

/**
 * @api
 */
abstract class AbstractBody implements Body
{
    final public function buffer(int $limit = 8 * 1024 * 1024): string
    {
        $result = '';

        try {
            while (($bytes = $this->read(8192)) !== null) {
                if (\strlen($bytes) > $limit - \strlen($result)) {
                    throw new \OverflowException("The body exceeds {$limit} bytes.");
                }

                $result .= $bytes;
            }
        } catch (\Throwable $error) {
            $this->close();

            throw $error;
        }

        return $result;
    }
}
