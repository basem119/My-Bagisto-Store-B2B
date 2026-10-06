<?php

namespace Webkul\Marketplace\Exceptions;

use RuntimeException;

/**
 * Covers all Phase 12C business-rule failures: credit-limit exceeded, payment
 * term exceeded, policy violations, overpayment, and payment-plan conflicts.
 */
class MarketplaceCreditException extends RuntimeException {}
