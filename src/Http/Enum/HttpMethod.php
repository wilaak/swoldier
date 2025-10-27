<?php

declare(strict_types=1);

namespace Swoldier\Http\Enum;

enum HttpMethod: string
{
    // Standard HTTP methods
    case GET = 'GET';
    case POST = 'POST';
    case PUT = 'PUT';
    case PATCH = 'PATCH';
    case DELETE = 'DELETE';
    case HEAD = 'HEAD';
    case OPTIONS = 'OPTIONS';

    // Less common / diagnostic / proxy methods
    case TRACE = 'TRACE';
    case CONNECT = 'CONNECT';

    // WebDAV methods (RFC 4918)
    case PROPFIND = 'PROPFIND';
    case PROPPATCH = 'PROPPATCH';
    case MKCOL = 'MKCOL';
    case COPY = 'COPY';
    case MOVE = 'MOVE';
    case LOCK = 'LOCK';
    case UNLOCK = 'UNLOCK';

    // WebDAV extensions / versioning / search
    case REPORT = 'REPORT';
    case SEARCH = 'SEARCH';
    case MKACTIVITY = 'MKACTIVITY';
    case CHECKOUT = 'CHECKOUT';
    case MERGE = 'MERGE';
}
