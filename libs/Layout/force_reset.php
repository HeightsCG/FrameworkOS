<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/google_analytics.php'; ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php echo CSRF::meta(); ?>
    <title>Change password &middot; <?php echo Main::site_name(); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.css">
    <link rel="stylesheet" href="/css/site.css?v=<?php echo @filemtime(Main::app_path().'/public/css/site.css'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/jquery@4.0.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.js"></script>
    <script src="/js/api.data.js?v=<?php echo @filemtime(Main::app_path().'/public/js/api.data.js'); ?>"></script>
    <script>
    $(document).ready(function() {

        $('#do_change').on('click', function() {
            if ($('#p_word').val() !== $('#p_word_confirm').val()) {
                toastr.error('Passwords do not match');
                return;
            }
            ApiDataSvc.apiCall('post', 'change_password', {
                current_password: $('#current_password').val(),
                p_word:           $('#p_word').val()
            }, function(data) {
                var response = JSON.parse(data);
                if (response.success) {
                    toastr.success(response.message);
                    window.location = '/';
                } else {
                    toastr.error(response.message);
                }
            });
        });

    });
    </script>
</head>
<body>
    <div class="container" style="max-width:420px; margin-top:80px;">
        <h1 class="h4 mb-2 text-center"><?php echo Main::site_name(); ?></h1>
        <p class="text-muted text-center mb-4">You must set a new password before continuing.</p>
        <div class="mb-2"><input type="password" id="current_password" class="form-control" placeholder="Current password"></div>
        <div class="mb-2"><input type="password" id="p_word" class="form-control" placeholder="New password"></div>
        <div class="mb-2"><input type="password" id="p_word_confirm" class="form-control" placeholder="Confirm new password"></div>
        <button type="button" id="do_change" class="btn btn-primary w-100">Update password</button>
    </div>
</body>
</html>
