<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php echo CSRF::meta(); ?>
    <title><?php echo Main::site_name(); ?></title>
    <link rel="stylesheet" href="/css/site.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/jquery@4.0.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.js"></script>
    <script src="/js/api.data.js"></script>
    <script>
    $(document).ready(function() {

        $('#login_form').show();
        $('#forgot_form').hide();

        $('#do_login').on('click', function() {
            ApiDataSvc.apiCall('post', 'login', {
                u_name: $('#u_name').val(),
                p_word: $('#p_word').val()
            }, function(data) {
                var obj = JSON.parse(data);
                if (obj.success) {
                    window.location = obj.reset_pw == 1 ? '/account/force_reset' : '/';
                } else {
                    toastr.error(obj.message);
                }
            });
        });

        $('#forgot_password').on('click', function() {
            $('#login_form').hide();
            $('#forgot_form').show();
        });

        $('#show_login').on('click', function() {
            $('#forgot_form').hide();
            $('#login_form').show();
        });

        $('#do_forgot').on('click', function() {
            ApiDataSvc.apiCall('post', 'forgot', {
                u_name: $('#forgot_u_name').val()
            }, function(data) {
                var obj = JSON.parse(data);
                if (obj.success) {
                    toastr.success(obj.message);
                } else {
                    toastr.error(obj.message);
                }
            });
        });

    });
    </script>
</head>
<body>
    <div class="container">
        <div class="row">
            <div class="col-md-12">
                <h1><?php echo Main::site_name(); ?></h1>
            </div>
        </div>
        <div class="row" id="login_form">
            <div class="col-md-12">
                <label for="u_name">Username</label>
                <input type="text" name="u_name" id="u_name" class="form-control">
            </div>
            <div class="col-md-12">
                <label for="p_word">Password</label>
                <input type="password" name="p_word" id="p_word" class="form-control">
            </div>
            <div class="col-md-6">
                <button type="button" class="btn btn-primary" id="do_login">Login</button>
            </div>
            <div class="col-md-6">
                <a href="#" id="forgot_password">Forgot password?</a>
            </div>
        </div>
        <div class="row" id="forgot_form" style="display:none;">
            <div class="col-md-12">
                <label for="forgot_u_name">Username</label>
                <input type="text" name="u_name" id="forgot_u_name" class="form-control">
            </div>
            <div class="col-md-6">
                <button type="button" class="btn btn-primary" id="do_forgot">Send reset link</button>
            </div>
            <div class="col-md-6">
                <a href="#" id="show_login">Back to login</a>
            </div>
        </div>
    </div>
</body>
</html>
