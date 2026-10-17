<?php

namespace App\Ingestion\Exceptions;

use RuntimeException;

/**
 * The provider could not deliver usable data: transport error, error status or unexpected response.
 */
class ProviderException extends RuntimeException {}
