<?php

namespace App\WorldStorage;

use RuntimeException;

/**
 * An uploaded zip was refused. The message is shown to the admin.
 */
class UnsafeArchive extends RuntimeException {}
