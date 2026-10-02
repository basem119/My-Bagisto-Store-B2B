<?php

namespace Webkul\Marketplace\Exceptions;

use RuntimeException;

/**
 * Thrown when a vendor-scoped operation is attempted by a customer who is not an
 * authorized member of that vendor (or lacks the required role).
 */
class VendorMembershipException extends RuntimeException {}
