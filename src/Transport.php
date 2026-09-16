<?php

declare(strict_types=1);

namespace Izy\EcobankPayout;

/**
 * The seam between the protocol and the network.
 *
 * Deliberately one method and no abstractions over headers: the protocol needs exactly
 * a JSON POST with a handful of headers, and anything richer would be a framework of its
 * own. A host application that already has an HTTP client implements this in a dozen
 * lines and keeps its own timeouts, retries, proxying and logging.
 */
interface Transport
{
    /**
     * POST a JSON body and return the decoded response.
     *
     * Implementations must not throw on a non-2xx status: this API answers 200 to
     * refused requests and puts the verdict in the body, so the status alone is not a
     * verdict either way. Return it and let Client decide.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     *
     * @throws EcobankException on a transport failure — unreachable host, TLS, timeout.
     *                          That is not a refusal, and the caller must not read it as
     *                          one: a payment whose answer was lost may still have been
     *                          executed.
     */
    public function post(string $url, array $headers, array $body): TransportResponse;
}
