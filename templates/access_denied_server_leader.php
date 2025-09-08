<?php
##
## Copyright 2013-2017 Opera Software AS
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
?>
<h1>Access Denied</h1>
<div class="alert alert-warning">
	<p><strong>You need to be a server leader, or an account leader to view this page.</strong> <br />
	To request access for a specific user account on the server, please visit the <a href="<?php outurl($this->get('server_url'))?>" class="alert-link">server page for <?php out($this->get('server_hostname'))?></a>.</p>
</div>
<div class="alert alert-info">
    <p>You might already have access to this server. You can find list of servers and accounts you have access to on <a href="<?php outurl($this->get('home_url'))?>" class="alert-link">your home page</a>.<br />
    If you believe you should be a leader for this server, or for a particular account on the server, please contact your system administrator.</p>
</div>
