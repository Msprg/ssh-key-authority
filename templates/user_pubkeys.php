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
?>
<h1>Public keys for <a href="<?php outurl('/users/'.urlencode($this->get('user')->uid))?>"><?php out($this->get('user')->name)?></a></h1>
<p>
	<a href="<?php outurl('/users/'.urlencode($this->get('user')->uid).'/pubkeys.txt') ?>" class="btn btn-default btn-xs">
		<span class="glyphicon glyphicon-console"></span> TXT
	</a>
	<a href="<?php outurl('/users/'.urlencode($this->get('user')->uid).'/pubkeys.json') ?>" class="btn btn-default btn-xs">
		<span class="glyphicon glyphicon-console"></span> JSON
	</a>
</p>
<?php if($this->get('allow_admin_add')) { ?>
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title">Add public key for <?php out($this->get('user')->name)?></h2>
	</div>
	<div class="panel-body">
		<form method="post" action="<?php outurl($this->data->relative_request_url) ?>">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<div class="mb-3">
				<label for="add_public_key">Public key</label>
				<textarea class="form-control" rows="4" id="add_public_key" name="add_public_key" required></textarea>
			</div>
			<p class="help-block">The key will be added to <?php out($this->get('user')->uid)?>.</p>
			<button type="submit" class="btn btn-primary">Add public key</button>
		</form>
	</div>
</div>
<?php } ?>
<?php foreach($this->get('pubkeys') as $pubkey) { ?>
<div class="panel panel-default">
	<dl class="panel-body">
		<dt>Key data</dt>
		<dd><pre><?php out($pubkey->export())?></pre></dd>
		<dt>Creation Date</dt>
		<dd><?php out($pubkey->format_creation_date()) ?></dd>
		<dt>Deletion Date</dt>
		<dd><?php out($pubkey->format_deletion_date()) ?></dd>
		<dt>Key size</dt>
		<dd><?php out($pubkey->keysize)?></dd>
		<dt>Fingerprint (MD5)</dt>
		<dd><?php out($pubkey->fingerprint_md5)?></dd>
		<dt>Fingerprint (SHA256)</dt>
		<dd><?php out($pubkey->fingerprint_sha256)?></dd>
		<dt>Status</dt>
		<dd><?php out($pubkey->deletion_date !== null ? 'Deleted' : 'Active') ?>
	</dl>
</div>
<?php } ?>
