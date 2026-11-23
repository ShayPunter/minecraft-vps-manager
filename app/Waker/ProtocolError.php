<?php

namespace App\Waker;

use RuntimeException;

/**
 * The other side didn't speak Minecraft's protocol (#5620): the waker hangs up.
 */
class ProtocolError extends RuntimeException {}
