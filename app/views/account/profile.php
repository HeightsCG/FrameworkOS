<link rel="stylesheet" href="/css/account-profile.css">
<script>
$(function () {

    var password_rules = {
        length: function (v) { return v.length >= 8; },
        upper:  function (v) { return /[A-Z]/.test(v); },
        lower:  function (v) { return /[a-z]/.test(v); },
        number: function (v) { return /[0-9]/.test(v); },
        symbol: function (v) { return /[^A-Za-z0-9]/.test(v); },
        match: function (v) { return v === $('#confirm_password').val(); }
    };

    var password_labels  = ['Strength', 'Weak', 'Fair', 'Good', 'Strong'];
    var password_classes = ['', 'is-weak', 'is-fair', 'is-good', 'is-strong'];
    var password_bucket  = [0, 1, 1, 2, 3, 4];

    function password_render() {
        var v = $('#new_password').val() || '';
        var score = 0;
        $.each(password_rules, function (rule, test) {
            var ok = test(v);
            if (ok) { score++; }
            $('#password-reqs li[data-rule="' + rule + '"]').toggleClass('is-met', ok);
        });
        var bucket = v.length ? password_bucket[score] : 0;
        $('#meter').removeClass('is-weak is-fair is-good is-strong').addClass(password_classes[bucket]);
        $('#meterLabel').text(password_labels[bucket]);
    }

    function password_reset() {
        $('#password-reqs li').removeClass('is-met');
        $('#meter').removeClass('is-weak is-fair is-good is-strong');
        $('#meterLabel').text(password_labels[0]);
    }

    $("#new_password, #confirm_password").keyup(function(){
        password_render();
    });

    $('#save_profile').on('click', function () {

        if ($('#first_name').val() == '') {
            toastr.error('First name is required');
            return;
        }
        
        if ($('#last_name').val() == '') {
            toastr.error('Last name is required');
            return;
        }

        if ($('#user_email').val() == '') {
            toastr.error('Email is required');
            return;
        }

        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test($('#user_email').val())) {
            toastr.error('A valid email is required');
            return;
        }

        if ($('#u_name').val() == '') {
            toastr.error('Username is required');
            return;
        }

        ApiDataSvc.apiCall('post', 'update_profile', {
            first_name: $('#first_name').val(),
            last_name:  $('#last_name').val(),
            user_email: $('#user_email').val(),
            user_phone: $('#user_phone').val(),
            u_name: $('#u_name').val(),
            business_name: $('#business_name').val(),
            website_url: $('#website_url').val()
        }, function (data) {
            var o = JSON.parse(data);
            if (o.success) {
                toastr.success(o.message);
            } else {
                toastr.error(o.message);
            }
        });
    });

    $('#update_password').on('click', function () {

        if ($('#current_password').val() == '') {
            toastr.error('Current password is required');
            return;
        }
        
        if ($('#new_password').val() == '') {
            toastr.error('New password is required');
            return;
        }
        
        if ($('#confirm_password').val() == '') {
            toastr.error('Confirm password is required');
            return;
        }

        if ($('#new_password').val() !== $('#confirm_password').val()) {
            toastr.error('New passwords do not match');
            return;
        }

        if (!password_rules.length($('#new_password').val())) {
            toastr.error('New password must be at least 8 characters');
            return;
        }

        if (!password_rules.upper($('#new_password').val())) {
            toastr.error('New password must include an uppercase letter');
            return;
        }

        if (!password_rules.lower($('#new_password').val())) {
            toastr.error('New password must include a lowercase letter');
            return;
        }

        if (!password_rules.number($('#new_password').val())) {
            toastr.error('New password must include a number');
            return;
        }

        if (!password_rules.symbol($('#new_password').val())) {
            toastr.error('New password must include a symbol');
            return;
        }

        ApiDataSvc.apiCall('post', 'change_password', {
            current_password: $('#current_password').val(),
            p_word:           $('#new_password').val(),
            confirm_password: $('#confirm_password').val()
        }, function (data) {
            var o = JSON.parse(data);
            if (o.success) {
                toastr.success(o.message);
                $('#current_password, #new_password, #confirm_password').val('');
            } else {
                toastr.error(o.message);
            }
        });
    });

    $('#change_password').on('click', function () {
        $('#current_password').val('');
        $('#new_password').val('');
        $('#confirm_password').val('');
        $('#change_password_form').modal('show');
        setTimeout(function () {
            $('#current_password').focus();
        }, 600);
    });

    $('#delete_account').on('click', function () {
        $('#delete_account_form').modal('show');
    });

    $('#confirm_delete_account').on('click', function () {
        $('#delete_account_form').modal('hide');
        ApiDataSvc.apiCall('post', 'delete_my_account', {}, function (data) {
            var o = JSON.parse(data);
            if (o.success) {
                setTimeout(function () {
                    window.location.href = '/';
                }, 1000);
            } else {
                toastr.error(o.message);
            }
        });
    });

    $(document).on('keydown', '#current_password, #new_password, #confirm_password', function(e) {
        if (e.keyCode === 13) {
            $("#update_password").trigger('click');
        }
    });

    $(document).on('keydown', '#first_name, #last_name, #user_email, #user_phone, #u_name', function(e) {
        if (e.keyCode === 13) {
            $("#save_profile").trigger('click');
        }
    });

});
</script>

<div class="container">
    <div class="row mb-3">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h5 class="card-title">Profile Details</h5>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6 mb-3">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="business_name" value="<?php echo htmlspecialchars((string) $this->user['business_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                <label for="business_name">Business Name (Optional)</label>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="user_phone" value="<?php echo htmlspecialchars((string) $this->user['user_phone'], ENT_QUOTES, 'UTF-8'); ?>">
                                <label for="user_phone">Phone Number (Optional)</label>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="first_name" value="<?php echo htmlspecialchars((string) $this->user['first_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                <label for="first_name">First Name</label>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="last_name" value="<?php echo htmlspecialchars((string) $this->user['last_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                <label for="last_name">Last Name</label>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="form-floating">
                                <input type="email" class="form-control" id="user_email" value="<?php echo htmlspecialchars((string) $this->user['user_email'], ENT_QUOTES, 'UTF-8'); ?>">
                                <label for="user_email">Email Address</label>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="u_name" value="<?php echo htmlspecialchars((string) $this->user['u_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                <label for="u_name">Username</label>
                            </div>
                        </div>
                        <div class="col-md-12 mb-3">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="website_url" value="<?php echo htmlspecialchars((string) $this->user['website_url'], ENT_QUOTES, 'UTF-8'); ?>">
                                <label for="website_url">Website URL (Optional)</label>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <button type="button" class="btn btn-primary" id="save_profile">Update Profile</button>
                            <button type="button" class="btn btn-secondary float-end" id="change_password">Change Password</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row mb-3">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h5 class="card-title">Danger Zone</h5>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-12 mb-3">
                            <p>This action is irreversible and will permanently delete your account and all associated data.</p>
                        </div>
                        <div class="col-md-12">
                            <button type="button" class="btn btn-danger" id="delete_account">Delete Account</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="delete_account_form" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Account</h5>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete your account? This action is irreversible and will permanently delete your account and all associated data.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirm_delete_account">Confirm</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="change_password_form" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Change Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-12">
                        <div class="form-floating mb-3">
                            <input type="password" class="form-control" id="current_password" autocomplete="current-password">
                            <label for="current_password">Current Password</label>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <div class="form-floating mb-3">
                            <input type="password" class="form-control" id="new_password" autocomplete="new-password">
                            <label for="new_password">New Password</label>
                        </div>
                    </div>
                    <div class="col-md-12 mb-3">
                        <div class="password-meter" id="meter">
                            <div class="password-meter__bar mb-2">
                                <span></span>
                                <span></span>
                                <span></span>
                                <span></span>
                            </div>
                            <p class="password-meter__label mb-2" id="meterLabel">Password Strength</p>
                            <ul class="password-reqs" id="password-reqs">
                                <li data-rule="length"><i class="password-reqs__dot"></i>At least 8 characters</li>
                                <li data-rule="upper"><i class="password-reqs__dot"></i>An uppercase letter</li>
                                <li data-rule="lower"><i class="password-reqs__dot"></i>A lowercase letter</li>
                                <li data-rule="number"><i class="password-reqs__dot"></i>A number</li>
                                <li data-rule="symbol"><i class="password-reqs__dot"></i>A symbol</li>
                                <li data-rule="match"><i class="password-reqs__dot"></i>Matches the confirm password</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <div class="form-floating mb-3">
                            <input type="password" class="form-control" id="confirm_password" autocomplete="new-password">
                            <label for="confirm_password">Confirm Password</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="update_password">Update Password</button>
            </div>
        </div>
    </div>
</div>
