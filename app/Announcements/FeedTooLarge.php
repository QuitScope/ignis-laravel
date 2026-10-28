<?php

namespace App\Announcements;

use RuntimeException;

/** Thrown from the download progress guard to abort a transfer that exceeds FeedHttp::MAX_BYTES. */
final class FeedTooLarge extends RuntimeException {}
