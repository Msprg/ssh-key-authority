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

<style>
    body {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .login-container {
        background: white;
        border-radius: 10px;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        padding: 40px;
        width: 100%;
        max-width: 400px;
        margin: 0 auto;
    }
    .login-header {
        text-align: center;
        margin-bottom: 30px;
    }
    .login-header img {
        width: 60px;
        height: 60px;
        margin-bottom: 15px;
    }
    .login-header h1 {
        color: #333;
        font-size: 24px;
        margin: 0;
        font-weight: 600;
    }
    .form-group {
        margin-bottom: 20px;
    }
    .form-control {
        border: 2px solid #e1e5e9;
        border-radius: 8px;
        padding: 12px 15px;
        font-size: 16px;
        transition: border-color 0.3s ease;
    }
    .form-control:focus {
        border-color: #667eea;
        box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
    }
    .btn-login {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border: none;
        border-radius: 8px;
        padding: 12px;
        font-size: 16px;
        font-weight: 600;
        color: white;
        width: 100%;
        transition: transform 0.2s ease;
    }
    .btn-login:hover {
        transform: translateY(-2px);
        color: white;
    }
    .alert {
        border-radius: 8px;
        border: none;
    }
    .alert-danger {
        background-color: #fee;
        color: #c33;
        border-left: 4px solid #c33;
    }
    .alert-success {
        background-color: #efe;
        color: #363;
        border-left: 4px solid #363;
    }
</style>

<div class="login-container">
    <div class="login-header">
        <h1>SSH Key Authority</h1>
        <p class="text-muted">Please sign in to continue</p>
    </div>

    <?php if($this->get('error_message')) { ?>
        <div class="alert alert-danger">
            <?php out($this->get('error_message'))?>
        </div>
    <?php } ?>

    <?php if($this->get('success_message')) { ?>
        <div class="alert alert-success">
            <?php out($this->get('success_message'))?>
        </div>
    <?php } ?>

    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?php out($this->get('csrf_token')) ?>">
        <div class="form-group">
            <label for="username" class="form-label">Username</label>
            <input type="text" class="form-control" id="username" name="username" 
                   value="<?php out($_POST['username'] ?? '')?>" 
                   placeholder="Enter your username" required autofocus>
        </div>
        
        <div class="form-group">
            <label for="password" class="form-label">Password</label>
            <input type="password" class="form-control" id="password" name="password" 
                   placeholder="Enter your password" required>
        </div>
        
        <button type="submit" class="btn btn-login">Sign In</button>
    </form>
    
    <div class="text-center mt-3">
        <small class="text-muted">
            Authentication is handled via LDAP
        </small>
    </div>
</div>
