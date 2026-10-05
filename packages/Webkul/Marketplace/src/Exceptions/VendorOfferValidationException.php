<?php

namespace Webkul\Marketplace\Exceptions;

use RuntimeException;

/**
 * Thrown when a requested VendorProduct offer cannot be added to / updated
 * in the cart: it doesn't exist, doesn't belong to the requested product,
 * the vendor/offer isn't active, or the requested quantity exceeds what the
 * vendor has available.
 */
class VendorOfferValidationException extends RuntimeException {}
