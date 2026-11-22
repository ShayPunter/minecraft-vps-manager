<?php

namespace App\Minecraft;

use RuntimeException;

/** Mojang, PaperMC or Fabric couldn't be reached or answered something unusable. The message is safe to show. */
class VersionSourceException extends RuntimeException {}
