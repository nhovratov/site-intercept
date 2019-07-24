<?php

/*
 * This file is part of the package t3g/intercept.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

use Symfony\Component\HttpFoundation\Request;

return Request::create(
    '/docs',
    'POST',
    [],
    [],
    [],
    [],
    '
    {
      "ref": "refs/foo/latest"
    }
    '
);
