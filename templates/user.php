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
<h1><span class="ska-icon ska-icon-user" title="User"></span> <?php out($this->get('user')->name)?> <small>(<?php out($this->get('user')->uid)?>)</small><?php if(!$this->get('user')->active) out(' <span class="badge text-bg-secondary">Inactive</span>', ESC_NONE)?></h1>
<dl>
	<dt>Account type</dt>
	<dd><?php out($this->get('user')->auth_realm)?></dd>
</dl>
<ul class="ska-tabs" role="tablist">
	<li class="ska-tab-item active" role="presentation"><a href="#details" id="user_details_tab" class="ska-tab-link active" role="tab" data-bs-toggle="tab" aria-controls="details" aria-selected="true">Details</a></li>
	<?php if($this->get('user')->auth_realm == 'LDAP' && $this->get('admin')) { ?>
	<li class="ska-tab-item" role="presentation"><a href="#settings" id="user_settings_tab" class="ska-tab-link" role="tab" data-bs-toggle="tab" aria-controls="settings" aria-selected="false" tabindex="-1">Settings</a></li>
	<?php } ?>
</ul>
<!-- Tab panes -->
<div class="ska-tab-content">
	<div class="ska-tab-pane fade in active show" id="details" role="tabpanel" aria-labelledby="user_details_tab" aria-hidden="false">
		<h2 class="visually-hidden">Details</h2>
		<h3><a href="<?php outurl('/users/'.urlencode($this->get('user')->uid).'/pubkeys')?>">Public keys</a></h3>
		<p>
			<a href="<?php outurl('/users/'.urlencode($this->get('user')->uid).'/pubkeys.txt') ?>" class="btn btn-secondary btn-sm">
				<span class="ska-icon ska-icon-console"></span> TXT
			</a>
			<a href="<?php outurl('/users/'.urlencode($this->get('user')->uid).'/pubkeys.json') ?>" class="btn btn-secondary btn-sm">
				<span class="ska-icon ska-icon-console"></span> JSON
			</a>
		</p>
		<?php if(count($this->get('active_user_keys')) == 0) { ?>
		<p><?php out($this->get('user')->name)?> has no active public keys.</p>
		<?php } else { ?>
		<?php if($this->get('admin')) { ?>
		<form method="post" action="<?php outurl($this->data->relative_request_url) ?>">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
		<?php } ?>
		<div class="ska-scroll-container">
			<table class="table">
				<thead>
					<tr>
						<th>Type</th>
						<th class="fingerprint">Fingerprint</th>
						<th></th>
						<th>Creation Date</th>
						<th>Size</th>
						<th>Comment</th>
						<?php if($this->get('admin')) { ?>
							<th>Actions</th>
						<?php } ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach($this->get('active_user_keys') as $key) { ?>
					<tr>
						<td><?php out($key->type) ?></td>
						<td>
							<a href="<?php outurl('/pubkeys/'.urlencode($key->id).'#info')?>">
								<span class="fingerprint_md5"><?php out($key->fingerprint_md5) ?></span>
								<span class="fingerprint_sha256"><?php out($key->fingerprint_sha256) ?></span>
							</a>
						</td>
						<td>
							<?php if(count($key->list_signatures()) > 0) { ?><a href="<?php outurl('/pubkeys/'.urlencode($key->id).'#sig')?>"><span class="ska-icon ska-icon-pencil" title="Signed key"></span></a><?php } ?>
							<?php if(count($key->list_destination_rules()) > 0) { ?><a href="<?php outurl('/pubkeys/'.urlencode($key->id).'#dest')?>"><span class="ska-icon ska-icon-pushpin" title="Destination-restricted"></span></a><?php } ?>
						</td>
						<td><?php out($key->format_creation_date()) ?></td>
						<td><?php out($key->keysize) ?></td>
						<td><?php out($key->comment) ?></td>
						<?php if($this->get('admin')) { ?>
						<td>
							<button type="submit" name="delete_public_key" value="<?php out($key->id) ?>" class="btn btn-secondary btn-sm"><span class="ska-icon ska-icon-trash"></span> Delete public key</button>
						</td>
						<?php } ?>
					</tr>
					<?php } ?>
				</tbody>
			</table>
		</div>
		<?php if($this->get('admin')) { ?>
			</form>
		<?php } ?>
	<?php } ?>
		<?php
			$num_deleted = $this->get('user')->count_deleted_public_keys();
			if ($num_deleted > 0) {
				$keys_plural = $num_deleted == 1 ? 'key' : 'keys';
		?>
		<p><a href="<?php outurl('/users/'.urlencode($this->get('user')->uid).'/pubkeys')?>">Show all public keys</a> (Including <?php out($this->get('user')->count_deleted_public_keys()) ?> deleted <?php out($keys_plural) ?>)</p>
		<?php } ?>
		<?php if($this->get('admin')) { ?>
		<h3>Groups</h3>
		<?php if(count($this->get('user_groups')) == 0 && count($this->get('user_admined_groups')) == 0) { ?>
		<p><?php out($this->get('user')->name)?> is not a member or administrator of any groups.</p>
		<?php } ?>
		<?php if(count($this->get('user_groups')) > 0) { ?>
		<p><?php out($this->get('user')->name)?> is a member of the following groups:</p>
		<div class="ska-scroll-container">
			<table class="table">
				<thead>
					<tr>
						<th>Group</th>
						<th>Members</th>
						<th>Admins</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach($this->get('user_groups') as $group) {?>
					<tr>
						<td><a href="<?php outurl('/groups/'.urlencode($group->name)) ?>" class="group"><?php out($group->name) ?></a></td>
						<td><?php out(number_format($group->member_count))?></td>
						<td><?php out($group->admins)?></td>
					</tr>
					<?php } ?>
				</tbody>
			</table>
		</div>
		<?php } ?>
		<?php if(count($this->get('user_admined_groups')) > 0) { ?>
		<p><?php out($this->get('user')->name)?> is an administrator of the following groups:</p>
		<div class="ska-scroll-container">
			<table class="table">
				<thead>
					<tr>
						<th>Group</th>
						<th>Members</th>
						<th>Admins</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach($this->get('user_admined_groups') as $group) {?>
					<tr>
						<td><a href="<?php outurl('/groups/'.urlencode($group->name)) ?>" class="group"><?php out($group->name) ?></a></td>
						<td><?php out(number_format($group->member_count))?></td>
						<td><?php out($group->admins)?></td>
					</tr>
					<?php } ?>
				</tbody>
			</table>
		</div>
		<?php } ?>
		<h3>Access</h3>
		<?php if(count($this->get('user_access')) == 0) { ?>
		<p><?php out($this->get('user')->name)?> has not been explicitly granted access to any entities.</p>
		<?php } else { ?>
		<p><?php out($this->get('user')->name)?> has been explicitly granted access to the following entities:</p>
		<div class="ska-scroll-container">
			<table class="table">
				<thead>
					<tr>
						<th>Entity</th>
						<th>Granted by</th>
						<th>Granted on</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach($this->get('user_access') as $access) { ?>
					<tr>
						<td>
							<?php
							switch(get_class($access->dest_entity)) {
							case 'ServerAccount':
							?>
							<a href="<?php outurl('/servers/'.urlencode($access->dest_entity->server->hostname).'/accounts/'.urlencode($access->dest_entity->name))?>" class="serveraccount"><?php out($access->dest_entity->name.'@'.$access->dest_entity->server->hostname)?></a>
							<?php
								break;
							case 'Group':
							?>
							<a href="<?php outurl('/groups/'.urlencode($access->dest_entity->name))?>" class="group"><?php out($access->dest_entity->name)?></a>
							<?php
								break;
							}
							?>
						</td>
						<td><a href="<?php outurl('/users/'.urlencode($access->granted_by->uid))?>" class="user"><?php out($access->granted_by->uid)?></a></td>
						<td><?php out($access->grant_date) ?></td>
					</tr>
					<?php } ?>
				</tbody>
			</table>
		</div>
		<?php } ?>
		<h3>Server management</h3>
		<?php if(count($this->get('user_admined_servers')) == 0) { ?>
		<p><?php out($this->get('user')->name)?> is not a leader for any servers.</p>
		<?php } else { ?>
		<p><?php out($this->get('user')->name)?> is a leader for the following servers:</p>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<div class="ska-scroll-container">
				<table class="table" id="admined_servers">
					<thead>
						<tr>
							<th>Hostname</th>
							<th>Config</th>
							<th>Status</th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach($this->get('user_admined_servers') as $server) {
							if($server->key_management != 'keys') {
								$class = '';
							} else {
								switch($server->sync_status) {
								case 'not synced yet': $class = 'warning'; break;
								case 'sync failure':   $class = 'danger';  break;
								case 'sync success':   $class = 'success'; break;
								case 'sync warning':   $class = 'warning'; break;
								}
							}
							if($last_sync = $server->get_last_sync_event()) {
								$sync_details = json_decode($last_sync->details)->value;
							} else {
								$sync_details = ucfirst($server->sync_status);
							}
						?>
						<tr>
							<td>
								<a href="<?php outurl('/servers/'.urlencode($server->hostname)) ?>" class="server"><?php out($server->hostname) ?></a>
								<?php if($server->pending_requests > 0) { ?>
								<a href="<?php outurl('/servers/'.urlencode($server->hostname).'#requests') ?>"><span class="badge text-bg-secondary" title="Pending requests"><?php out(number_format($server->pending_requests)) ?></span></a>
								<?php } ?>
							</td>
							<td>
								<?php
								switch($server->key_management) {
								case 'keys':
									switch($server->authorization) {
									case 'manual': out('Manual account management'); break;
									case 'automatic LDAP': out('LDAP accounts - automatic'); break;
									case 'manual LDAP': out('LDAP accounts - manual'); break;
									}
									break;
								case 'other': out('Managed by another system'); break;
								case 'none': out('Unmanaged'); break;
								case 'decommissioned': out('Decommissioned'); break;
								}
								?>
							</td>
							<td class="<?php out($class)?>"><?php out($sync_details) ?></td>
						</tr>
						<?php } ?>
					</tbody>
				</table>
			</div>
			<p><button type="button" class="btn btn-secondary" data-reassign="admined_servers">Reassign servers</button></p>
		</form>
		<?php } ?>
		<?php } ?>
	</div>
	<?php if($this->get('user')->auth_realm == 'LDAP' && $this->get('admin')) { ?>
	<div class="ska-tab-pane fade" id="settings" role="tabpanel" aria-labelledby="user_settings_tab" aria-hidden="true">
		<h2 class="visually-hidden">Settings</h2>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>" class="ska-settings-form">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<div class="ska-setting-row">
				<div class="ska-setting-label">User status</div>
				<div class="ska-setting-control ska-choice-list">
					<label class="ska-choice"><input type="radio" name="force_disable" value="0"<?php if(!$this->get('user')->force_disable) out(' checked') ?>> <span>Use status from LDAP</span></label>
					<label class="ska-choice text-danger"><input type="radio" name="force_disable" value="1"<?php if($this->get('user')->force_disable) out(' checked') ?>> <span>Disable account (override LDAP)</span></label>
				</div>
			</div>
			<div class="ska-setting-actions">
					<button type="submit" name="edit_user" value="1" class="btn btn-primary">Change settings</button>
			</div>
		</form>
	</div>
	<?php } ?>
</div>
