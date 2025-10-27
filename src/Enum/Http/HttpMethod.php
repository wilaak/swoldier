<?php

declare(strict_types=1);

namespace Swoldier\Enum\Http;

/**
 * Standard HTTP methods enum.
 *
 * Note: For custom methods, you can use the string type directly in route definitions.
 */
enum HttpMethod: string
{
    case GET = 'GET';
    case POST = 'POST';
    case PUT = 'PUT';
    case PATCH = 'PATCH';
    case DELETE = 'DELETE';
    case HEAD = 'HEAD';
    case OPTIONS = 'OPTIONS';
}
