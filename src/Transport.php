<?php

declare(strict_types=1);

namespace Izy\EcobankPayout;

/** The seam between the protocol and the network. */
interface Transport
{
    /**
     * POST a JSON body and return the decoded response.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     * @throws EcobankException on a transport failure — unreachable host, TLS, timeout.
     */
    public function post(string $url, array $headers, array $body): TransportResponse;
}
