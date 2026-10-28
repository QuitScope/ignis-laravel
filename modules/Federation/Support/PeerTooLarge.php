<?php

namespace Modules\Federation\Support;

use RuntimeException;

/** Thrown by PeerHttp's progress guard; Guzzle wraps it, send() looks through the chain. */
final class PeerTooLarge extends RuntimeException {}
