<?php

declare(strict_types=1);

namespace Swoldier\Http\Enum;

enum HttpRedirectStatus: int
{
    /**
     * 301 Moved Permanently
     * The resource has been permanently moved to a new URL.
     * Clients should update bookmarks/links.
     * May change request method to GET (e.g., POST to GET).
     */
    case MovedPermanently = 301;
    /**
     * 302 Found
     * Temporary redirect to a different URL.
     * Clients should continue to use the original URL for future requests.
     * May change request method to GET.
     */
    case Found = 302;
    /**
     * 303 See Other
     * Redirect to a different URL, typically after a POST.
     * Clients should use GET for the subsequent request.
     */
    case SeeOther = 303;
    /**
     * 307 Temporary Redirect
     * Temporary redirect to a different URL.
     * Clients should continue to use the original URL for future requests.
     * Preserves the request method and body (e.g., POST stays POST).
     */
    case TemporaryRedirect = 307;
    /**
     * 308 Permanent Redirect
     * The resource has been permanently moved to a new URL.
     * Clients should update bookmarks/links.
     * Preserves the request method and body (e.g., POST stays POST).
     */
    case PermanentRedirect = 308;
}
