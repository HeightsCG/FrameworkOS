<?php
/**
 * /contact: a message form that posts to /api/contact_send (ApiSupportController), which emails support
 * with reply-to set to the sender. The support address is shown as text too.
 */
$site  = Main::site_name();
$email = PagesController::LEGAL_CONTACT;
echo Sections::panel_hero(array(
    'title' => 'Contact Support',
    'lead'  => 'Questions about billing, payouts, your account or a bug. Send a message and we reply by email.',
));
?>
<section class="sx sx--alt"><div class="ld-wrap sx__in ct">
    <form class="ct-form" id="ct_form" novalidate>
        <div class="ct-row">
            <div class="ct-field">
                <label class="ct-label" for="ct_name">Name</label>
                <input class="ct-input" id="ct_name" name="name" type="text" maxlength="100" autocomplete="name" placeholder="Jane Smith" required>
            </div>
            <div class="ct-field">
                <label class="ct-label" for="ct_email">Email</label>
                <input class="ct-input" id="ct_email" name="email" type="email" maxlength="190" autocomplete="email" placeholder="you@example.com" required>
            </div>
        </div>
        <div class="ct-field">
            <label class="ct-label" for="ct_topic">Topic</label>
            <select class="ct-input" id="ct_topic" name="topic" required>
                <option value="">Choose a topic</option>
<?php foreach (PagesController::CONTACT_TOPICS as $t_key => $t_label): ?>
                <option value="<?php echo Sections::e($t_key); ?>"><?php echo Sections::e($t_label); ?></option>
<?php endforeach; ?>
            </select>
        </div>
        <div class="ct-field">
            <label class="ct-label" for="ct_message">Message</label>
            <textarea class="ct-input ct-input--area" id="ct_message" name="message" rows="7" maxlength="5000" placeholder="Order date, amount and what happened" required></textarea>
        </div>
        <div class="ct-hp" aria-hidden="true">
            <label for="ct_company">Company</label>
            <input id="ct_company" name="company" type="text" tabindex="-1" autocomplete="off">
        </div>
        <div class="ct-acts">
            <button class="sx-btn sx-btn--primary" id="ct_send" type="submit">Send Message</button>
        </div>
    </form>
    <div class="ct-done" id="ct_done" hidden>
        <?php echo Sections::icon('check', 24); ?>
        <h2 class="ct-h">Message Sent</h2>
        <p class="sx-p">Thanks. We reply to the email address you gave.</p>
    </div>
    <aside class="ct-side">
        <h2 class="ct-h">Email Us</h2>
        <p class="sx-p"><a href="mailto:<?php echo Sections::e($email); ?>"><?php echo Sections::e($email); ?></a></p>
        <h2 class="ct-h">Signed In?</h2>
        <p class="sx-p">Members can open a request from Support in the account menu and follow the replies there.</p>
        <p class="sx-p"><?php echo Sections::e($site); ?> is a Heights Consulting Group LLC product.</p>
    </aside>
</div></section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('ct_form');
    var btn = document.getElementById('ct_send');
    if (!form || typeof ApiDataSvc === 'undefined') { return; }
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var name = $('#ct_name').val().trim(), email = $('#ct_email').val().trim(), topic = $('#ct_topic').val(), message = $('#ct_message').val().trim();
        $('.ct-input').removeClass('is-bad');
        var bad = '';
        if (name == '') { bad = bad || 'Add your name'; $('#ct_name').addClass('is-bad'); }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { bad = bad || 'Add a valid email address'; $('#ct_email').addClass('is-bad'); }
        if (topic == '') { bad = bad || 'Choose a topic'; $('#ct_topic').addClass('is-bad'); }
        if (message.length < 10) { bad = bad || 'Write a few words about your question'; $('#ct_message').addClass('is-bad'); }
        if (bad != '') { toastr.error(bad); $('.ct-input.is-bad').first().trigger('focus'); return; }
        btn.disabled = true; btn.textContent = 'Sending...';
        ApiDataSvc.apiCall('post', 'contact_send', {
            name: name, email: email, topic: topic, message: message, company: $('#ct_company').val()
        }, function (data) {
            var obj = null;
            try { obj = JSON.parse(data); } catch (err) { obj = null; }
            if (obj && obj.success) {
                form.hidden = true;
                document.getElementById('ct_done').hidden = false;
            } else {
                toastr.error(obj ? obj.message : 'Something went wrong. Please try again.');
                btn.disabled = false; btn.textContent = 'Send Message';
            }
        });
    });
});
</script>
