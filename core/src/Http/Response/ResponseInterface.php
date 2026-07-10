<?php

namespace Z77\Core\Http\Response;

interface ResponseInterface
{
    /**
     * Sends the response to the client.
     * Sets headers and writes output where applicable.
     * Never called from actions — called by AbstractBaseController after dispatch.
     */
    public function send(): void;
}
