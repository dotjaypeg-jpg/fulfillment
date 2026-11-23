<?php

namespace Chargefield\Fulfillment\Exceptions;

/**
 * Thrown by {@see \Chargefield\Fulfillment\Contracts\LabelCarrier::createLabel()}
 * when a label can't be bought — misconfiguration, an unsupported destination, or
 * a carrier/API failure. Surfaced to the admin who requested the label.
 */
class LabelException extends \RuntimeException {}
