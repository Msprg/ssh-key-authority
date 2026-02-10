<?php
##
## Copyright 2013-2017 Opera Software AS
## Modifications Copyright 2021 Leitwerk AG
##
## Licensed under the Apache License, Version 2.0 (the "License");
## you may not use this file except in compliance with the License.
## You may obtain a copy of the License at
##
## http://www.apache.org/licenses/LICENSE-2.0
##
## Unless required by applicable law or agreed to in writing, software
## distributed under the License is distributed on an "AS IS" BASIS,
## WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
## See the License for the specific language governing permissions and
## limitations under the License.
##

// This template is used as a content section within the base template
// It provides the login form content
?>
<div class="login-container">
    <div class="mb-4">
        <h1 class="mb-1">SSH Key Authority</h1>
        <p class="text-muted mb-0">Please sign in to continue</p>
    </div>

    <?php if($this->get('error_message')) { ?>
        <div class="alert alert-danger" role="alert">
            <?php out($this->get('error_message'))?>
        </div>
    <?php } ?>

    <?php if($this->get('success_message')) { ?>
        <div class="alert alert-success" role="status" aria-live="polite">
            <?php out($this->get('success_message'))?>
        </div>
    <?php } ?>
    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?php out($this->get('csrf_token')) ?>">
        <div class="mb-3">
            <label for="username" class="form-label">Username</label>
            <input type="text" class="form-control" id="username" name="username" 
                   value="<?php out($_POST['username'] ?? '')?>" 
                   placeholder="Enter your username" autocomplete="username" required autofocus>
        </div>
        
        <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input type="password" class="form-control" id="password" name="password" 
                   placeholder="Enter your password" autocomplete="current-password" required>
        </div>
        
        <button type="submit" class="btn btn-login w-100">Login</button>
    </form>
    <div class="text-center mt-3">
        <small id="login-help" class="text-muted">
            Authentication is handled via LDAP
        </small>
    </div>
</div>
