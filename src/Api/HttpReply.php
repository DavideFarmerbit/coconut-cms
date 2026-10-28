<?php

namespace XyloIsCoding\CoconutCms\Api;

/** What an HTTP call to the API answers: a status and a JSON body. */
final readonly class HttpReply
{
    public function __construct(
        public int $status,
        public string $body,
    ) {
    }

    /** Sends it: the status, the content type, the body. */
    public function send(): void {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        echo $this->body;
    }
}
