<?php

declare(strict_types=1);

/*
 * The reviewer's golden-flow scenarios that exercise live broadcasting
 * (webhook → activity log update, action approval → status change in real
 * time, multi-tab sync, sidebar Now Playing badge decay, admin connection
 * lifecycle across tabs) all require a running Reverb instance plus a
 * BROADCAST_CONNECTION=reverb test env. The default test config uses the
 * "null" broadcaster, so subscribing in a browser test would never see
 * messages.
 *
 * The non-realtime parts of those flows are already covered:
 *   - C1 webhook-survives-broken-broadcaster:      tests/Feature/Broadcasting/BroadcastEventTest.php
 *   - Action approval state machine + dispatch:    tests/Feature/Actions/* and tests/Browser/ActionRequestTest.php
 *   - Channel auth shape (incl. viewer access):    tests/Feature/Broadcasting/ChannelAuthorizationTest.php
 *   - Broadcast event payload shape:               tests/Feature/Broadcasting/BroadcastEventTest.php
 *
 * To wire up the live half, point a CI matrix job at a Reverb container
 * (BROADCAST_CONNECTION=reverb + REVERB_HOST/PORT pointed at it) and remove
 * the skip below. The broadcast tests already prove the events fire; what's
 * missing is a browser receiving them through a real socket.
 */
test('realtime golden-flow browser tests require a Reverb instance')
    ->skip('Requires BROADCAST_CONNECTION=reverb and a running Reverb broker — wire up in CI when available.');
