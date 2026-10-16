<?php
/** Shared event form body. Needs $values (defaults/stored, local-time strings), $categories, $languages. */
$val = static fn (string $k) => old($k, $values[$k] ?? '');
$err = static function (string $k) {
    $m = field_error($k);
    return $m ? '<p class="error-text" id="' . e($k) . '-err">' . e($m) . '</p>' : '';
};
$inv = static fn (string $k) => field_error($k) ? ' aria-invalid="true" aria-describedby="' . e($k) . '-err"' : '';
$zones = DateTimeZone::listIdentifiers();
?>
<fieldset class="stack">
    <legend>The basics</legend>
    <div class="field"><label for="title">Event title</label>
        <input class="input" id="title" name="title" required maxlength="200" value="<?= e($val('title')) ?>"<?= $inv('title') ?>><?= $err('title') ?></div>
    <div class="form-grid">
        <div class="field"><label for="category">Type of event</label>
            <select class="input" id="category" name="category">
                <?php foreach ($categories as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($val('category'), $k) ?>><?= e($l) ?></option><?php endforeach; ?>
            </select></div>
        <div class="field"><label for="host_names">Hosted by</label>
            <input class="input" id="host_names" name="host_names" maxlength="200" value="<?= e($val('host_names')) ?>" placeholder="e.g. Asha &amp; Rohan"><?= $err('host_names') ?></div>
    </div>
    <div class="form-grid">
        <div class="field"><label for="start_at">Starts</label>
            <input class="input" id="start_at" name="start_at" type="datetime-local" required value="<?= e($val('start_at')) ?>"<?= $inv('start_at') ?>><?= $err('start_at') ?></div>
        <div class="field"><label for="end_at">Ends <span class="muted">(optional)</span></label>
            <input class="input" id="end_at" name="end_at" type="datetime-local" value="<?= e($val('end_at')) ?>"<?= $inv('end_at') ?>><?= $err('end_at') ?></div>
        <div class="field"><label for="timezone">Time zone</label>
            <select class="input" id="timezone" name="timezone" data-autotz="<?= $v->get('autotz') ? '1' : '' ?>">
                <?php foreach ($zones as $z): ?><option value="<?= e($z) ?>"<?= selected($val('timezone'), $z) ?>><?= e($z) ?></option><?php endforeach; ?>
            </select><p class="hint">Times are shown to guests in this zone.</p></div>
    </div>
    <div class="form-grid">
        <div class="field"><label for="venue_name">Venue name</label>
            <input class="input" id="venue_name" name="venue_name" maxlength="200" value="<?= e($val('venue_name')) ?>"></div>
        <div class="field"><label for="venue_address">Address</label>
            <input class="input" id="venue_address" name="venue_address" maxlength="300" value="<?= e($val('venue_address')) ?>"></div>
        <div class="field"><label for="map_url">Map link <span class="muted">(optional)</span></label>
            <input class="input" id="map_url" name="map_url" type="url" inputmode="url" maxlength="500" value="<?= e($val('map_url')) ?>" placeholder="https://maps.google.com/…"<?= $inv('map_url') ?>>
            <p class="hint">Leave empty and we will link to a map search for the address.</p><?= $err('map_url') ?></div>
    </div>
    <div class="field"><label for="description">Description</label>
        <textarea class="input" id="description" name="description" rows="4" maxlength="5000"><?= e($val('description')) ?></textarea></div>
    <div class="form-grid">
        <div class="field"><label for="language">Invitation language</label>
            <select class="input" id="language" name="language">
                <?php foreach ($languages as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($val('language'), $k) ?>><?= e($l) ?></option><?php endforeach; ?>
            </select><p class="hint">Right-to-left languages switch the layout automatically.</p></div>
    </div>
</fieldset>

<fieldset class="stack">
    <legend>Who can open the invitation</legend>
    <label class="check"><input type="radio" name="visibility" value="public"<?= checked($val('visibility') === 'public') ?>>
        <span><strong>Anyone with the link</strong><br><span class="muted">Share one link or QR code. Guests give their name when they RSVP.</span></span></label>
    <label class="check"><input type="radio" name="visibility" value="private"<?= checked($val('visibility') === 'private') ?>>
        <span><strong>Private: invited guests only</strong><br><span class="muted">Each guest gets a personal link. The general link shows nothing unless you set an access code.</span></span></label>
    <div class="field"><label for="access_code">Access code <span class="muted">(optional, private events)</span></label>
        <input class="input w-auto" id="access_code" name="access_code" autocomplete="off" maxlength="40" placeholder="<?= !empty($settings['access_code_hash']) ? 'A code is set. Type to replace it.' : 'e.g. a family word' ?>"<?= $inv('access_code') ?>>
        <?php if (!empty($settings['access_code_hash'])): ?><label class="check"><input type="checkbox" name="clear_access_code" value="1"> <span>Remove the access code</span></label><?php endif; ?>
        <?= $err('access_code') ?></div>
</fieldset>

<fieldset class="stack">
    <legend>RSVP settings</legend>
    <label class="check"><input type="checkbox" name="rsvp_open" value="1"<?= checked(form_bool('rsvp_open', (bool) ($values['rsvp_open'] ?? true))) ?>> <span>Accept RSVPs</span></label>
    <div class="form-grid">
        <div class="field"><label for="rsvp_deadline">RSVP deadline <span class="muted">(optional)</span></label>
            <input class="input" id="rsvp_deadline" name="rsvp_deadline" type="datetime-local" value="<?= e($val('rsvp_deadline')) ?>"></div>
        <div class="field"><label for="capacity">Maximum headcount <span class="muted">(optional)</span></label>
            <input class="input" id="capacity" name="capacity" type="number" min="1" max="1000000" value="<?= e($val('capacity')) ?>"<?= $inv('capacity') ?>>
            <p class="hint">Guests, plus-ones and children all count.</p><?= $err('capacity') ?></div>
        <div class="field"><label for="plus_one_limit">Plus-ones per guest</label>
            <input class="input" id="plus_one_limit" name="plus_one_limit" type="number" min="0" max="10" value="<?= e($val('plus_one_limit') === '' ? 0 : $val('plus_one_limit')) ?>"></div>
    </div>
    <label class="check"><input type="checkbox" name="allow_children" value="1"<?= checked(form_bool('allow_children', (bool) ($values['allow_children'] ?? false))) ?>> <span>Children are welcome (asked separately from adults)</span></label>
    <div class="field"><label for="max_children">Most children per guest</label>
        <input class="input w-auto" id="max_children" name="max_children" type="number" min="0" max="10" value="<?= e($val('max_children') === '' ? 4 : $val('max_children')) ?>"></div>
    <label class="check"><input type="checkbox" name="allow_maybe" value="1"<?= checked(form_bool('allow_maybe', (bool) ($values['allow_maybe'] ?? true))) ?>> <span>Offer a “Maybe” option</span></label>
    <label class="check"><input type="checkbox" name="allow_waitlist" value="1"<?= checked(form_bool('allow_waitlist', (bool) ($values['allow_waitlist'] ?? true))) ?>> <span>Waitlist guests when the event is full (they move up automatically when places open)</span></label>
    <label class="check"><input type="checkbox" name="allow_response_edit" value="1"<?= checked(form_bool('allow_response_edit', (bool) ($values['allow_response_edit'] ?? true))) ?>> <span>Let guests change their response from their link</span></label>
    <label class="check"><input type="checkbox" name="collect_email" value="1"<?= checked(form_bool('collect_email', (bool) ($values['collect_email'] ?? true))) ?>> <span>Ask for an email address (optional for guests)</span></label>
    <label class="check"><input type="checkbox" name="collect_phone" value="1"<?= checked(form_bool('collect_phone', (bool) ($values['collect_phone'] ?? false))) ?>> <span>Ask for a phone number (optional for guests)</span></label>
    <div class="field"><label for="thank_you_message">Message shown after RSVP <span class="muted">(optional)</span></label>
        <input class="input" id="thank_you_message" name="thank_you_message" maxlength="500" value="<?= e($val('thank_you_message')) ?>"></div>
</fieldset>

<fieldset class="stack">
    <legend>Photo album</legend>
    <label class="check"><input type="checkbox" name="album_enabled" value="1"<?= checked(form_bool('album_enabled', (bool) ($values['album_enabled'] ?? false))) ?>> <span>Let guests share photos in an event album</span></label>
    <label class="check"><input type="checkbox" name="album_moderated" value="1"<?= checked(form_bool('album_moderated', (bool) ($values['album_moderated'] ?? true))) ?>> <span>Review photos before they appear (recommended; photos from the public QR poster are always reviewed)</span></label>
</fieldset>

<fieldset class="stack">
    <legend>Privacy</legend>
    <div class="field"><label for="guest_list_visibility">What can guests see about other guests?</label>
        <select class="input" id="guest_list_visibility" name="guest_list_visibility">
            <?php foreach (['hidden' => 'Nothing (recommended)', 'total' => 'Only the total number attending', 'first_names' => 'First names of guests who are attending', 'accepted_names' => 'Full names of guests who are attending'] as $k => $l): ?>
                <option value="<?= e($k) ?>"<?= selected($val('guest_list_visibility'), $k) ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select><p class="hint">Names are only ever shown for guests who are attending.</p></div>
    <label class="check"><input type="checkbox" name="indexable" value="1"<?= checked(form_bool('indexable', (bool) ($values['indexable'] ?? false))) ?>> <span>Allow search engines to list this public invitation (off by default)</span></label>
</fieldset>
