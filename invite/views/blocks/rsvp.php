<?php
use App\Core\Lang;
use App\Services\QuestionService;

$personal = $eg !== null;
$hasResponse = $response !== null;
$name = $personal ? $eg['first_name'] : '';
$curStatus = (string) old('status', $response['status'] ?? '');
if ($curStatus === 'waitlist') { $curStatus = 'attending'; }
$plusLimit = (int) ($eg['plus_one_limit'] ?? $settings['plus_one_limit']);
$kidLimit = (int) $settings['allow_children'] ? (int) ($eg['child_limit'] ?? $settings['max_children']) : 0;
$plus = (int) old('plus_ones', $response['plus_ones'] ?? 0);
$kids = (int) old('children', $response['children'] ?? 0);
$comp = old('companions', null);
if (!is_array($comp)) {
    $comp = ['plus_one' => [], 'child' => []];
    foreach ($companions as $cp) { $comp[$cp['kind']][] = ['name' => $cp['name'], 'age' => $cp['age']]; }
}
$showName = form_bool('show_name', (bool) ($response['show_name'] ?? false));
$ans = old('answers', $answers);
$party = $hasResponse ? 1 + (int) $response['plus_ones'] + (int) $response['children'] : 0;
$disabled = $preview ? ' disabled' : '';
$fieldErr = static function (string $k) {
    $m = field_error($k);
    return $m ? '<p class="error-text" id="' . e($k) . '-err">' . e($m) . '</p>' : '';
};
$statusLabels = ['attending' => t('rsvp.attending'), 'declined' => t('rsvp.declined'), 'maybe' => t('rsvp.maybe')];
if (!(int) $settings['allow_maybe']) { unset($statusLabels['maybe']); }
?>
<section class="inv-section" id="rsvp" aria-labelledby="rsvp-h">
    <h2 id="rsvp-h"><?= e($c['heading'] ?: t('rsvp.heading')) ?></h2>
    <?php if ($c['intro']): ?><p><?= e($c['intro']) ?></p><?php endif; ?>

    <?php if ($emailSent): ?>
        <div class="alert alert-info" role="status"><?= e(t('rsvp.link_sent')) ?></div>
    <?php endif; ?>

    <?php if ($hasResponse && $saved): ?>
        <div class="confirm-box" role="status" tabindex="-1" data-focus-on-load>
            <div class="tick" aria-hidden="true">✓</div>
            <h3><?= e(t('rsvp.thanks', ['name' => $name])) ?></h3>
            <p><strong><?= e(t('status.' . $response['status'])) ?></strong><?php if (in_array($response['status'], ['attending', 'waitlist'], true)): ?> · <?= e(t('rsvp.party_total', ['n' => $party])) ?><?php endif; ?></p>
            <?php if ($response['status'] === 'waitlist'): ?><p><?= e(t('rsvp.waitlist_note')) ?></p><?php endif; ?>
            <?php if (!empty($settings['thank_you_message'])): ?><p><?= e($settings['thank_you_message']) ?></p><?php endif; ?>
            <?php if ($eg && !empty($eg['email'])): ?><p class="muted"><?= e(t('rsvp.email_sent')) ?></p><?php endif; ?>
            <div class="inv-actions">
                <a class="btn btn-secondary btn-sm" href="<?= e($ics) ?>"><?= e(t('calendar.ics')) ?></a>
                <a class="btn btn-secondary btn-sm" href="<?= e($google) ?>" target="_blank" rel="noopener noreferrer"><?= e(t('calendar.google')) ?></a>
                <?php if ($maps): ?><a class="btn btn-secondary btn-sm" href="<?= e($maps) ?>" target="_blank" rel="noopener noreferrer"><?= e(t('map.open')) ?></a><?php endif; ?>
                <?php if ($response['status'] === 'attending' && $token): ?><a class="btn btn-secondary btn-sm" href="/g/<?= e($token) ?>/pass"><?= e(t('pass.link')) ?></a><?php endif; ?>
            </div>
            <p class="muted"><?= e(t('rsvp.bookmark')) ?></p>
        </div>
    <?php elseif ($hasResponse && $blocked): ?>
        <p><strong><?= e(t('rsvp.your_response')) ?>:</strong> <?= e(t('status.' . $response['status'])) ?><?php if (in_array($response['status'], ['attending', 'waitlist'], true)): ?> · <?= e(t('rsvp.party_total', ['n' => $party])) ?><?php endif; ?></p>
    <?php elseif ($hasResponse): ?>
        <p class="muted"><?= e(t('rsvp.current')) ?>: <strong><?= e(t('status.' . $response['status'])) ?></strong>. <?= e(t('rsvp.change_below')) ?></p>
    <?php endif; ?>

    <?php if ($blocked && !$preview): ?>
        <p class="closed-banner" role="note"><?= e($blocked) ?></p>
    <?php else: ?>
        <?php $wrapOpen = !($hasResponse && $saved); ?>
        <?php if (!$wrapOpen): ?><details class="faq"><summary><?= e(t('rsvp.change')) ?></summary><?php endif; ?>
        <form method="post" action="<?= e($formAction) ?>" class="rsvp-form" data-rsvp novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="idem" value="<?= e($idem) ?>">
            <div class="sr-only" aria-hidden="true"><label for="website">Leave this field empty</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
            <fieldset class="choice-row"<?= $disabled ?>>
                <legend><?= e(t('rsvp.will_you')) ?></legend>
                <?php foreach ($statusLabels as $key => $label): ?>
                    <label class="choice"><input type="radio" name="status" value="<?= e($key) ?>"<?= checked($curStatus === $key) ?><?= $key === array_key_first($statusLabels) ? ' required' : '' ?>><span><?= e($label) ?></span></label>
                <?php endforeach; ?>
            </fieldset>
            <?= $fieldErr('status') ?>

            <?php if (!$personal): ?>
                <div class="field"><label for="name"><?= e(t('rsvp.your_name')) ?></label>
                    <input class="input" id="name" name="name" autocomplete="name" required maxlength="150" value="<?= e(old('name')) ?>"<?= $disabled ?>><?= $fieldErr('name') ?></div>
                <?php if ($settings['collect_email']): ?>
                <div class="field"><label for="email"><?= e(t('rsvp.email')) ?> <span class="muted">(<?= e(t('optional')) ?>)</span></label>
                    <input class="input" id="email" name="email" type="email" autocomplete="email" inputmode="email" value="<?= e(old('email')) ?>" aria-describedby="email-hint"<?= $disabled ?>>
                    <p class="hint" id="email-hint"><?= e(t('rsvp.email_hint')) ?></p><?= $fieldErr('email') ?></div>
                <?php endif; ?>
                <?php if ($settings['collect_phone']): ?>
                <div class="field"><label for="phone"><?= e(t('rsvp.phone')) ?> <span class="muted">(<?= e(t('optional')) ?>)</span></label>
                    <input class="input" id="phone" name="phone" type="tel" autocomplete="tel" inputmode="tel" value="<?= e(old('phone')) ?>"<?= $disabled ?>><?= $fieldErr('phone') ?></div>
                <?php endif; ?>
            <?php else: ?>
                <p class="muted"><?= e(t('rsvp.responding_as', ['name' => trim($eg['first_name'] . ' ' . ($eg['last_name'] ?? ''))])) ?></p>
            <?php endif; ?>

            <?php if ($plusLimit > 0 || $kidLimit > 0): ?>
            <div class="stack" data-show-for="attending,maybe">
                <?php if ($plusLimit > 0): ?>
                <div class="field"><label for="plus_ones"><?= e(t('rsvp.plus_ones')) ?> <span class="muted">(<?= e(t('rsvp.up_to', ['n' => $plusLimit])) ?>)</span></label>
                    <input class="input w-auto" id="plus_ones" name="plus_ones" type="number" inputmode="numeric" min="0" max="<?= $plusLimit ?>" value="<?= $plus ?>" data-stepper data-companion-count="plus_one"<?= $disabled ?>><?= $fieldErr('plus_ones') ?></div>
                <div class="companions" data-companions="plus_one">
                    <?php for ($i = 0; $i < $plusLimit; $i++): ?>
                        <div class="field" data-companion-index="<?= $i ?>"><label for="pn<?= $i ?>"><?= e(t('rsvp.companion_name', ['n' => $i + 1])) ?> <span class="muted">(<?= e(t('optional')) ?>)</span></label>
                            <input class="input" id="pn<?= $i ?>" name="companions[plus_one][<?= $i ?>][name]" maxlength="150" value="<?= e($comp['plus_one'][$i]['name'] ?? '') ?>"<?= $disabled ?>></div>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
                <?php if ($kidLimit > 0): ?>
                <div class="field"><label for="children"><?= e(t('rsvp.children')) ?> <span class="muted">(<?= e(t('rsvp.up_to', ['n' => $kidLimit])) ?>)</span></label>
                    <input class="input w-auto" id="children" name="children" type="number" inputmode="numeric" min="0" max="<?= $kidLimit ?>" value="<?= $kids ?>" data-stepper data-companion-count="child"<?= $disabled ?>><?= $fieldErr('children') ?></div>
                <div class="companions" data-companions="child">
                    <?php for ($i = 0; $i < $kidLimit; $i++): ?>
                        <div class="form-grid" data-companion-index="<?= $i ?>">
                            <div class="field"><label for="ch<?= $i ?>"><?= e(t('rsvp.child_name', ['n' => $i + 1])) ?> <span class="muted">(<?= e(t('optional')) ?>)</span></label>
                                <input class="input" id="ch<?= $i ?>" name="companions[child][<?= $i ?>][name]" maxlength="150" value="<?= e($comp['child'][$i]['name'] ?? '') ?>"<?= $disabled ?>></div>
                            <div class="field"><label for="ca<?= $i ?>"><?= e(t('rsvp.child_age')) ?></label>
                                <input class="input w-auto" id="ca<?= $i ?>" name="companions[child][<?= $i ?>][age]" type="number" min="0" max="17" inputmode="numeric" value="<?= e($comp['child'][$i]['age'] ?? '') ?>"<?= $disabled ?>></div>
                        </div>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($subEvents)): $subResp = $subResponses ?? []; ?>
            <fieldset class="stack-sm" data-show-for="attending,maybe"<?= $disabled ?>>
                <legend><?= e(t('rsvp.functions')) ?></legend>
                <p class="hint"><?= e(t('rsvp.functions_hint')) ?></p>
                <?php foreach ($subEvents as $s):
                    $isOn = $subResp ? in_array($subResp[(int) $s['id']] ?? '', ['attending', 'maybe', 'waitlist'], true) : true;
                    $fOld = old('functions', null);
                    if (is_array($fOld)) { $isOn = in_array($s['uuid'], $fOld, true); } ?>
                    <label class="check"><input type="checkbox" name="functions[]" value="<?= e($s['uuid']) ?>"<?= checked($isOn) ?><?= (int) $s['rsvp_open'] ? '' : ' disabled' ?>>
                        <span><strong><?= e($s['name']) ?></strong> · <?= e(fmt_dt($s['start_at'], $event['timezone'], 'j M, g:i A')) ?><?= $s['venue_name'] ? ' · ' . e($s['venue_name']) : '' ?></span></label>
                <?php endforeach; ?>
                <?= $fieldErr('functions') ?>
            </fieldset>
            <?php endif; ?>
            <?php foreach ($questions as $q):
                $uuid = $q['uuid']; $fid = 'q_' . $uuid; $val = $ans[$uuid] ?? '';
                $cond = $q['condition'];
                $attrs = '';
                if (!empty($cond['status'])) { $attrs .= ' data-cond-status="' . e(implode(',', $cond['status'])) . '"'; }
                if (!empty($cond['children_min'])) { $attrs .= ' data-cond-children-min="' . (int) $cond['children_min'] . '"'; }
                if (!empty($cond['plus_ones_min'])) { $attrs .= ' data-cond-plus-min="' . (int) $cond['plus_ones_min'] . '"'; }
                if (!empty($cond['question'])) { $attrs .= ' data-cond-q="' . e($cond['question']) . '" data-cond-equals="' . e($cond['equals']) . '"'; }
                $req = $q['is_required'] ? ' required' : '';
                $hasErr = field_error($fid) ? ' aria-invalid="true" aria-describedby="' . e($fid) . '-err"' : '';
                ?>
                <div class="field" data-question="<?= e($uuid) ?>"<?= $attrs ?>>
                    <?php if (in_array($q['type'], ['single_choice', 'meal', 'transport', 'accommodation'], true) && $q['options']): ?>
                        <fieldset class="choice-row"<?= $disabled ?>><legend><?= e($q['label']) ?><?= $q['is_required'] ? ' *' : '' ?></legend>
                            <?php foreach ($q['options'] as $o): ?>
                                <label class="choice"><input type="radio" name="answers[<?= e($uuid) ?>]" value="<?= e($o['label']) ?>"<?= checked((string) $val === $o['label']) ?>><span><?= e($o['label']) ?></span></label>
                            <?php endforeach; ?>
                        </fieldset>
                    <?php elseif ($q['type'] === 'multiple_choice'): ?>
                        <fieldset<?= $disabled ?>><legend><?= e($q['label']) ?><?= $q['is_required'] ? ' *' : '' ?></legend>
                            <?php foreach ($q['options'] as $o): ?>
                                <label class="check"><input type="checkbox" name="answers[<?= e($uuid) ?>][]" value="<?= e($o['label']) ?>"<?= checked(is_array($val) && in_array($o['label'], $val, true)) ?>> <span><?= e($o['label']) ?></span></label>
                            <?php endforeach; ?>
                        </fieldset>
                    <?php elseif ($q['type'] === 'dropdown'): ?>
                        <label for="<?= e($fid) ?>"><?= e($q['label']) ?><?= $q['is_required'] ? ' *' : '' ?></label>
                        <select class="input" id="<?= e($fid) ?>" name="answers[<?= e($uuid) ?>]"<?= $req . $hasErr . $disabled ?>><option value=""><?= e(t('choose')) ?></option>
                            <?php foreach ($q['options'] as $o): ?><option value="<?= e($o['label']) ?>"<?= selected($val, $o['label']) ?>><?= e($o['label']) ?></option><?php endforeach; ?>
                        </select>
                    <?php elseif ($q['type'] === 'consent'): ?>
                        <label class="check"><input type="checkbox" id="<?= e($fid) ?>" name="answers[<?= e($uuid) ?>]" value="1"<?= checked($val === 'yes') ?><?= $req . $hasErr . $disabled ?>> <span><?= e($q['label']) ?><?= $q['is_required'] ? ' *' : '' ?></span></label>
                    <?php elseif ($q['type'] === 'rating'): ?>
                        <fieldset class="choice-row"<?= $disabled ?>><legend><?= e($q['label']) ?><?= $q['is_required'] ? ' *' : '' ?></legend>
                            <?php for ($n = 1; $n <= 5; $n++): ?>
                                <label class="choice"><input type="radio" name="answers[<?= e($uuid) ?>]" value="<?= $n ?>"<?= checked((string) $val === (string) $n) ?>><span><?= $n ?> ★</span></label>
                            <?php endfor; ?>
                        </fieldset>
                    <?php elseif ($q['type'] === 'long_text' || $q['type'] === 'dietary'): ?>
                        <label for="<?= e($fid) ?>"><?= e($q['label']) ?><?= $q['is_required'] ? ' *' : '' ?></label>
                        <textarea class="input" id="<?= e($fid) ?>" name="answers[<?= e($uuid) ?>]" rows="3" maxlength="1500"<?= $req . $hasErr . $disabled ?>><?= e(is_array($val) ? '' : $val) ?></textarea>
                    <?php else:
                        $inputType = match ($q['type']) { 'number' => 'number', 'date' => 'date', default => 'text' }; ?>
                        <label for="<?= e($fid) ?>"><?= e($q['label']) ?><?= $q['is_required'] ? ' *' : '' ?></label>
                        <input class="input" id="<?= e($fid) ?>" name="answers[<?= e($uuid) ?>]" type="<?= $inputType ?>" maxlength="300" value="<?= e(is_array($val) ? '' : $val) ?>"<?= $req . $hasErr . $disabled ?>>
                    <?php endif; ?>
                    <?php if ($q['help_text']): ?><p class="hint"><?= e($q['help_text']) ?></p><?php endif; ?>
                    <?= $fieldErr($fid) ?>
                </div>
            <?php endforeach; ?>

            <div class="field"><label for="comment"><?= e(t('rsvp.message')) ?> <span class="muted">(<?= e(t('optional')) ?>)</span></label>
                <textarea class="input" id="comment" name="comment" rows="2" maxlength="500"<?= $disabled ?>><?= e(old('comment', $response['comment'] ?? '')) ?></textarea></div>

            <?php if (in_array($settings['guest_list_visibility'], ['first_names', 'accepted_names'], true)): ?>
                <label class="check" data-show-for="attending"><input type="checkbox" name="show_name" value="1"<?= checked($showName) ?><?= $disabled ?>> <span><?= e(t('rsvp.show_name')) ?></span></label>
            <?php endif; ?>

            <div class="sticky-cta">
                <button class="btn btn-primary btn-block" type="submit" data-busy-text="<?= e(t('sending')) ?>"<?= $disabled ?>><?= e($hasResponse ? t('rsvp.update') : t('rsvp.submit')) ?></button>
                <?php if (!$preview): ?><p class="hint center"><?= e(t('rsvp.privacy_note')) ?></p><?php endif; ?>
            </div>
        </form>
        <?php if (!$wrapOpen): ?></details><?php endif; ?>
    <?php endif; ?>

    <?php if ($publicList['mode'] === 'total' && $publicList['total'] > 0): ?>
        <p class="muted center"><?= e(t('rsvp.public_total', ['n' => $publicList['total']])) ?></p>
    <?php elseif (in_array($publicList['mode'], ['first_names', 'accepted_names'], true) && !empty($publicList['names'])): ?>
        <h3><?= e(t('rsvp.coming')) ?> (<?= (int) $publicList['total'] ?>)</h3>
        <p><?= e(implode(', ', $publicList['names'])) ?></p>
    <?php endif; ?>

    <?php if ($personal && !$preview && empty($eg['anonymized_at'])): ?>
        <details class="faq"><summary><?= e(t('privacy.title')) ?></summary>
            <p class="muted"><?= e(t('privacy.explain')) ?></p>
            <form method="post" action="/g/<?= e($token) ?>/delete-data" data-confirm="<?= e(t('privacy.confirm')) ?>"><?= csrf_field() ?>
                <button class="btn btn-ghost btn-sm" type="submit"><?= e(t('privacy.delete')) ?></button></form>
        </details>
    <?php endif; ?>
</section>
