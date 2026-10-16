<?php
$v->set('title', 'Details: ' . $event['title']);
$tz = $event['timezone'];
$values = [
    'title' => $event['title'], 'category' => $event['category'], 'host_names' => $event['host_names'], 'description' => $event['description'],
    'start_at' => to_local_input($event['start_at'], $tz), 'end_at' => to_local_input($event['end_at'], $tz), 'timezone' => $tz,
    'venue_name' => $event['venue_name'], 'venue_address' => $event['venue_address'], 'map_url' => $event['map_url'],
    'language' => $event['language'], 'visibility' => $event['visibility'], 'guest_list_visibility' => $settings['guest_list_visibility'],
    'plus_one_limit' => $settings['plus_one_limit'], 'max_children' => $settings['max_children'], 'capacity' => $settings['capacity'],
    'rsvp_deadline' => to_local_input($settings['rsvp_deadline'], $tz), 'thank_you_message' => $settings['thank_you_message'],
    'rsvp_open' => (bool) $settings['rsvp_open'], 'allow_maybe' => (bool) $settings['allow_maybe'], 'allow_waitlist' => (bool) $settings['allow_waitlist'],
    'allow_response_edit' => (bool) $settings['allow_response_edit'], 'allow_children' => (bool) $settings['allow_children'],
    'album_enabled' => (bool) $settings['album_enabled'], 'album_moderated' => (bool) $settings['album_moderated'], 'collect_email' => (bool) $settings['collect_email'], 'collect_phone' => (bool) $settings['collect_phone'], 'indexable' => (bool) $settings['indexable'],
];
?>
<?= $v->partial('partials/event_head', compact('event', 'can', 'active')) ?>
<form method="post" action="/app/events/<?= e($event['uuid']) ?>/details" class="stack" novalidate>
    <?= csrf_field() ?>
    <?= $v->partial('events/_fields', ['values' => $values, 'categories' => $categories, 'languages' => $languages, 'settings' => $settings]) ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save changes</button></div>
</form>
