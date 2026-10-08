<link rel="stylesheet" href="/css/founding.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/founding.css'); ?>">
<?php
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$c = (array) $this->claim;
$text = (string) ($c['testimonial_text'] ?? '');
$done = (string) ($c['testimonial_submitted_at'] ?? '') !== '';
?>
<div class="fnd">
    <header class="fnd__head">
        <h1 class="fnd__title">Your Founding Testimonial</h1>
        <p class="fnd__lead">You were one of the first creators to join. Tell us in a sentence or two what it has done for you. With your permission we will show it to creators who are deciding whether to join.</p>
    </header>

    <div class="fnd__done" id="fndDone"<?php echo $done ? '' : ' hidden'; ?>>
        <p class="fnd__thanks">Thank you</p>
        <blockquote class="fnd__quote" id="fndQuote"><?php echo $e($text); ?></blockquote>
        <p class="fnd__meta" id="fndConsent"><?php echo !empty($c['testimonial_consent']) ? 'Shown with your name and handle.' : 'Shown without your name and handle.'; ?></p>
        <button type="button" class="btn btn-secondary" id="fndEdit">Edit Testimonial</button>
    </div>

    <form class="fnd__form" id="fndForm" autocomplete="off" onsubmit="return false;"<?php echo $done ? ' hidden' : ''; ?>>
        <div class="form-floating">
            <textarea class="form-control" id="fndText" maxlength="300" placeholder="Your Sentence" required><?php echo $e($text); ?></textarea>
            <label for="fndText">Your Sentence</label>
        </div>
        <div class="form-check fnd__check">
            <input class="form-check-input" type="checkbox" id="fndConsentBox"<?php echo !empty($c['testimonial_consent']) ? ' checked' : ''; ?>>
            <label class="form-check-label" for="fndConsentBox">You may show my name and handle</label>
        </div>
        <div class="fnd__actions"><button type="submit" class="btn btn-primary" id="fndSubmit">Submit Testimonial</button></div>
    </form>
</div>
<script>
$(function () {
    function parse(d) { try { return JSON.parse(d); } catch (err) { return { success: false, message: 'Something went wrong' }; } }
    $('#fndEdit').on('click', function () { $('#fndDone').prop('hidden', true); $('#fndForm').prop('hidden', false); $('#fndText').trigger('focus'); });
    $('#fndForm').on('submit', function () {
        var text = String($('#fndText').val() || '').trim();
        if (text === '') { toastr.error('Write a sentence first.'); $('#fndText').trigger('focus'); return; }
        var $b = $('#fndSubmit').prop('disabled', true);
        ApiDataSvc.apiCall('post', 'founding_testimonial_save', { text: text, consent: $('#fndConsentBox').is(':checked') ? 1 : 0 }, function (data) {
            var o = parse(data);
            $b.prop('disabled', false);
            if (!o.success) { toastr.error(o.message); return; }
            $('#fndQuote').text(o.text);
            $('#fndConsent').text(o.consent ? 'Shown with your name and handle.' : 'Shown without your name and handle.');
            $('#fndForm').prop('hidden', true); $('#fndDone').prop('hidden', false);
            toastr.success(o.message);
        });
    });
});
</script>
