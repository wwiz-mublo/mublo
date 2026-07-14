<?php

namespace Mublo\Core\Event;

/**
 * Marker interface for events whose listener failures must stop the request.
 *
 * Best-effort events such as tracking or UI decoration can keep the default
 * swallow-and-log behavior. Critical domain events can implement this marker
 * to make listener exceptions bubble up to the caller.
 */
interface FailFastEventInterface
{
}
