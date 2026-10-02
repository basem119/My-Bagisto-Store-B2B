<?php

namespace Webkul\Marketplace\Exceptions;

use RuntimeException;

/**
 * Thrown when a vendor lifecycle operation is attempted from an invalid status
 * (e.g. suspending a vendor that isn't active).
 */
class InvalidVendorStatusTransitionException extends RuntimeException {}
