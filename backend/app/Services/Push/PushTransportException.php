<?php

declare(strict_types=1);

namespace App\Services\Push;

/** Expo could not be reached or asked us to slow down — a temporary condition; rows stay queued for retry. */
final class PushTransportException extends \RuntimeException {}
