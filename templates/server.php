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
<h1><span class="ska-icon ska-icon-server" title="Server"></span> <?php out($this->get('server')->hostname)?><?php if($this->get('server')->key_management == 'decommissioned') out(' <span class="ska-badge ska-badge-muted">Inactive</span>', ESC_NONE) ?></h1>
<?php if($this->get('server')->key_management == 'keys') { ?>
<form method="post" action="<?php outurl($this->data->relative_request_url)?>">
	<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
	<dl class="oneline">
		<?php if(isset($this->get('inventory_config')['url']) && $this->get('server')->uuid) { ?>
		<dt>Inventory UUID:</dt>
		<dd><a href="<?php out(sprintf($this->get('inventory_config')['url'], $this->get('server')->uuid), ESC_HTML)?>/"><?php out($this->get('server')->uuid)?></a></dd>
		<?php } ?>
		<dt>Sync status:</dt>
		<dd id="server_sync_status"
		<?php if(count($this->get('sync_requests')) == 0) { ?>
		<?php if(is_null($this->get('last_sync'))) { ?>
		data-class="warning" data-message="Not synced yet"
		<?php } else { ?>
		data-class="<?php out($this->get('sync_class'))?>" data-message="<?php out(json_decode($this->get('last_sync')->details)->value) ?>"
		<?php } ?>
		<?php } ?>
		>
			<span></span>
			<div class="spinner"></div>
			<a href="<?php outurl('/help')?>" class="btn btn-info btn-sm d-none">Explain</a>
			<button name="sync" value="1" type="submit" class="btn btn-secondary btn-sm invisible">Sync now</button>
		</dd>
	</dl>
</form>
<?php if($this->get('server')->ip_address && count($this->get('matching_servers_by_ip')) > 1) { ?>
<div class="alert alert-danger">
	<p>The hostname <?php out($this->get('server')->hostname)?> resolves to the same IP address as the following:</p>
	<ul>
		<?php foreach($this->get('matching_servers_by_ip') as $matched_server) { ?>
		<?php if($matched_server->hostname != $this->get('server')->hostname) { ?>
		<li><a href="<?php outurl('/servers/'.urlencode($matched_server->hostname))?>" class="server alert-link"><?php out($matched_server->hostname)?></a></li>
		<?php } ?>
		<?php } ?>
	</ul>
</div>
<?php } ?>
<?php if($this->get('server')->host_key && count($this->get('matching_servers_by_host_key')) > 1) { ?>
<div class="alert alert-danger">
	<p>The server has the same SSH host key as the following:</p>
	<ul>
		<?php foreach($this->get('matching_servers_by_host_key') as $matched_server) { ?>
		<?php if($matched_server->hostname != $this->get('server')->hostname) { ?>
		<li><a href="<?php outurl('/servers/'.urlencode($matched_server->hostname))?>" class="server alert-link"><?php out($matched_server->hostname)?></a></li>
		<?php } ?>
		<?php } ?>
	</ul>
</div>
<?php } ?>
<?php if ($this->get('server')->key_supervision_error !== null) { ?>
<div class="alert alert-danger">
	<p>Failed to supervise external keys on this server:</p>
	<pre><?php out($this->get('server')->key_supervision_error) ?></pre>
</div>
<?php } ?>
<?php } ?>
<?php if($this->get('admin') || $this->get('server_admin')) { ?>
<ul class="nav nav-tabs" role="tablist">
	<li class="nav-item" role="presentation"><a href="#accounts" id="server_accounts_tab" class="nav-link active" role="tab" data-bs-toggle="tab" aria-controls="accounts" aria-selected="true">Accounts</a></li>
	<li class="nav-item" role="presentation"><a href="#admins" id="server_admins_tab" class="nav-link" role="tab" data-bs-toggle="tab" aria-controls="admins" aria-selected="false" tabindex="-1">Leaders</a></li>
	<li class="nav-item" role="presentation"><a href="#settings" id="server_settings_tab" class="nav-link" role="tab" data-bs-toggle="tab" aria-controls="settings" aria-selected="false" tabindex="-1">Settings</a></li>
	<li class="nav-item" role="presentation"><a href="#log" id="server_log_tab" class="nav-link" role="tab" data-bs-toggle="tab" aria-controls="log" aria-selected="false" tabindex="-1">Log</a></li>
	<?php if($this->get('admin')) { ?>
	<li class="nav-item" role="presentation"><a href="#notes" id="server_notes_tab" class="nav-link" role="tab" data-bs-toggle="tab" aria-controls="notes" aria-selected="false" tabindex="-1">Notes<?php if(count($this->get('server_notes')) > 0) out(' <span class="ska-badge ska-badge-muted">'.count($this->get('server_notes')).'</span>', ESC_NONE)?></a></li>
	<li class="nav-item" role="presentation"><a href="#contact" id="server_contact_tab" class="nav-link" role="tab" data-bs-toggle="tab" aria-controls="contact" aria-selected="false" tabindex="-1">Contact</a></li>
	<?php } ?>
</ul>

<!-- Tab panes -->
<div class="tab-content">
	<div class="tab-pane fade active show" id="accounts" role="tabpanel" aria-labelledby="server_accounts_tab">
		<h2 class="visually-hidden">
			<?php if($this->get('server')->authorization == 'manual') { ?>
				Accounts
			<?php } else { ?>
				Non-LDAP accounts
			<?php } ?>
		</h2>
		<?php if(count($this->get('server_accounts')) == 0) { ?>
		<p>No accounts have been created yet.</p>
		<?php } else { ?>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<div class="ska-scroll-container">
				<table class="table table-bordered">
					<thead>
						<tr>
							<th>Account</th>
							<?php if($this->get('server')->key_management == 'keys') { ?>
							<th>Sync status</th>
							<?php } ?>
							<th>Account actions</th>
							<th colspan="2">Access granted for</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach($this->get('server_accounts') as $account) { ?>
						<?php
						$access_list = $account->list_access();
						switch($account->sync_status) {
						case 'proposed': $sync_class = 'info'; $sync_message = 'Requested'; break;
						case 'sync success': $sync_class = 'success'; $sync_message = 'Synced'; break;
						case 'sync failure': $sync_class = 'danger'; $sync_message = 'Failed'; break;
						case 'sync warning':
						default: $sync_class = 'warning'; $sync_message = 'Not synced'; break;
						}
						?>
						<tr>
							<th rowspan="<?php out(max(1, count($access_list)))?>">
								<a href="<?php outurl($this->data->relative_request_url.'/accounts/'.urlencode($account->name))?>" class="serveraccount"><?php out($account->name) ?></a>
								<?php if($account->pending_requests > 0) { ?>
									<a href="<?php outurl($this->data->relative_request_url.'/accounts/'.urlencode($account->name))?>"><span class="ska-badge ska-badge-muted" title="Pending requests"><?php out(number_format($account->pending_requests))?></span></a>
								<?php } ?>
							</th>
							<?php if($this->get('server')->key_management == 'keys') { ?>
							<td rowspan="<?php out(max(1, count($access_list)))?>">
								<span id="server_account_sync_status_<?php out($account->name)?>" class="server_account_sync_status"
								<?php if(!$account->sync_is_pending()) { ?>
								data-class="<?php out($sync_class)?>" data-message="<?php out($sync_message)?>"
								<?php } ?>
								></span>
							</td>
							<?php } ?>
							<td rowspan="<?php out(max(1, count($access_list)))?>">
								<a href="<?php outurl($this->data->relative_request_url.'/accounts/'.urlencode($account->name))?>" class="btn btn-secondary btn-sm"><span class="ska-icon ska-icon-cog"></span> Manage account</a>
								<?php if(!array_key_exists($account->name, $this->get('default_accounts'))) { ?>
								<button type="submit" name="delete_account" value="<?php out($account->id) ?>" class="btn btn-secondary btn-sm" data-confirm="Are you sure you want to delete this account?"><span class="ska-icon ska-icon-trash"></span> Delete account</button>
								<?php } ?>
							</td>
							<?php if(empty($access_list)) { ?>
							<td colspan="3"><em>No-one</em></td>
							<?php } else { ?>
							<?php
							$count = 0;
							foreach($access_list as $access) {
								$entity = $access->source_entity;
								$count++;
								if($count > 1) out('</tr><tr>', ESC_NONE);
								switch(get_class($entity)) {
								case 'User':
							?>
							<td><a href="<?php outurl('/users/'.urlencode($entity->uid))?>" class="user"><?php out($entity->uid) ?></a></td>
							<td><?php out($entity->name); if(!$entity->active) out(' <span class="ska-badge ska-badge-muted">Inactive</span>', ESC_NONE)?></td>
							<?php
									break;
								case 'ServerAccount':
							?>
							<td><a href="<?php outurl('/servers/'.urlencode($entity->server->hostname).'/accounts/'.urlencode($entity->name))?>" class="serveraccount"><?php out($entity->name.'@'.$entity->server->hostname) ?></a></td>
							<td><em>Server-to-server access</em><?php if($entity->server->key_management == 'decommissioned') out(' <span class="ska-badge ska-badge-muted">Inactive</span>', ESC_NONE) ?></td>
							<?php
									break;
								case 'Group':
							?>
							<td><a href="<?php outurl('/groups/'.urlencode($entity->name))?>" class="group"><?php out($entity->name) ?></a></td>
							<td><em>Group access</em><?php if(!$entity->active) out(' <span class="ska-badge ska-badge-muted">Inactive</span>', ESC_NONE)?></td>
							<?php
									break;
								}
							}
							?>
							<?php } ?>
						</tr>
						<?php } ?>
					</tbody>
				</table>
			</div>
		</form>
		<?php } ?>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>" class="row g-3 align-items-end">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<h3>Create<?php if($this->get('server')->authorization != 'manual') out(' non-LDAP'); ?> account</h3>
			<div class="col-md-4">
				<label for="account_name" class="visually-hidden">Account name</label>
				<input type="text" id="account_name" name="account_name" class="form-control" placeholder="Account name" required pattern=".*[^\s].*">
			</div>
			<div class="col-md-auto">
				<button type="submit" name="add_account" value="1" class="btn btn-primary">Manage this account with SSH Key Authority</button>
			</div>
		</form>
	</div>
	<div class="tab-pane fade" id="admins" role="tabpanel" aria-labelledby="server_admins_tab" >
		<h2 class="visually-hidden">Server leaders</h2>
		<?php if(count($this->get('server_admins')) == 0) { ?>
		<p class="alert alert-danger">This server does not have any leaders assigned.</p>
		<?php } else { ?>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<div class="ska-scroll-container">
				<table class="table table-bordered table-striped">
					<thead>
						<tr>
							<th>Entity</th>
							<th>Name</th>
							<?php if($this->get('admin')) { ?>
							<th>Actions</th>
							<?php } ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach($this->get('server_admins') as $admin) { ?>
							<?php if(strtolower(get_class($admin)) == "user"){?>
								<tr>
									<td><a href="<?php outurl('/users/'.urlencode($admin->uid))?>" class="user"><?php out($admin->uid) ?></a></td>
									<td><?php out($admin->name); if(!$admin->active) out(' <span class="ska-badge ska-badge-muted">Inactive</span>', ESC_NONE) ?></td>
									<?php if($this->get('admin')) {?>
									<td>
										<button type="submit" name="delete_admin" value="<?php out($admin->id) ?>" class="btn btn-secondary btn-sm"><span class="ska-icon ska-icon-trash"></span> Remove leader</button>
									</td>
									<?php } ?>
								</tr>
							<?php } elseif(strtolower(get_class($admin)) == "group"){ ?>
								<tr>
									<td><a href="<?php outurl('/groups/'.urlencode($admin->name))?>" class="group"><?php out($admin->name) ?></a></td>
									<td><?php out($admin->name); if(!$admin->active) out(' <span class="ska-badge ska-badge-muted">Inactive</span>', ESC_NONE) ?></td>
									<?php if($this->get('admin')) { ?>
									<td>
										<button type="submit" name="delete_admin" value="<?php out($admin->id) ?>" class="btn btn-secondary btn-sm"><span class="ska-icon ska-icon-trash"></span> Remove leader</button>
									</td>
									<?php } ?>
								</tr>
							<?php }} ?>
					</tbody>
				</table>
			</div>
		</form>
		<?php } ?>
		<?php if($this->get('admin')) { ?>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>" class="row g-3 align-items-end">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<h3>Add leader</h3>
			<div class="col-md-4">
				<label for="user_name" class="visually-hidden">User or group name</label>
				<input type="text" id="user_name" name="user_name" class="form-control" placeholder="User or group name" required list="userlist">
				<datalist id="userlist">
					<?php foreach($this->get('all_users') as $user) { ?>
					<option value="<?php out($user->uid)?>" label="<?php out($user->name)?>">
					<?php } ?>
					<?php foreach($this->get('all_groups') as $group) { ?>
					<option value="<?php out($group->name)?>" label="<?php out($group->name)?>">
					<?php } ?>
				</datalist>
			</div>
			<div class="col-md-auto">
				<button type="submit" name="add_admin" value="1" class="btn btn-primary">Add leader to server</button>
			</div>
		</form>
		<?php } ?>
	</div>
	<div class="tab-pane fade" id="settings" role="tabpanel" aria-labelledby="server_settings_tab" >
		<h2 class="visually-hidden">Settings</h2>
		<form id="server_settings" method="post" action="<?php outurl($this->data->relative_request_url)?>" class="ska-settings-form">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<?php if($this->get('admin')) { ?>
			<div class="ska-setting-row">
				<label for="hostname" class="ska-setting-label">Hostname</label>
				<div class="ska-setting-control">
					<input type="text" id="hostname" name="hostname" value="<?php out($this->get('server')->hostname)?>" required class="form-control">
				</div>
			</div>
			<div class="ska-setting-row">
				<label for="port" class="ska-setting-label">SSH port number</label>
				<div class="ska-setting-control">
					<input type="number" id="port" name="port" value="<?php out($this->get('server')->port)?>" required class="form-control">
				</div>
			</div>
			<div class="ska-setting-row">
				<label for="host_key" class="ska-setting-label">Host key</label>
				<div class="ska-setting-control">
					<input type="text" id="host_key" name="host_key" value="<?php out($this->get('server')->host_key)?>" readonly class="form-control">
					<button type="button" class="btn btn-secondary" data-clear="host_key">Clear</button>
				</div>
			</div>
			<div class="ska-setting-row">
				<label for="jumphosts" class="ska-setting-label">Jumphosts (<a href="<?php outurl('/help#jumphost_format')?>">format</a>)</label>
				<div class="ska-setting-control">
					<input type="text" id="jumphosts" name="jumphosts" value="<?php out($this->get('server')->jumphosts)?>" pattern="([^@ >]+@[a-zA-Z0-9\-.\u0080-\uffff]+(:[0-9]+)?(,[^@ >]+@[a-zA-Z0-9\-.\u0080-\uffff]+(:[0-9]+)?)*)?( *-> *[a-zA-Z0-9\-.\u0080-\uffff]+)?" class="form-control">
				</div>
			</div>
			<div class="ska-setting-row">
				<div class="ska-setting-label">Key management</div>
				<div class="ska-setting-control ska-choice-list">
					<label class="form-check ska-choice ska-text-success"><input type="radio" class="form-check-input" name="key_management" value="keys"<?php if($this->get('server')->key_management == 'keys') out(' checked') ?>> <span class="form-check-label">SSH keys managed and synced by SSH Key Authority</span></label>
					<label class="form-check ska-choice ska-text-warning"><input type="radio" class="form-check-input" name="key_management" value="none"<?php if($this->get('server')->key_management == 'none') out(' checked') ?>> <span class="form-check-label">Disabled - server has no key management</span></label>
					<label class="form-check ska-choice"><input type="radio" class="form-check-input" name="key_management" value="other"<?php if($this->get('server')->key_management == 'other') out(' checked') ?>> <span class="form-check-label">Disabled - SSH keys managed by another system</span></label>
					<label class="form-check ska-choice ska-text-danger"><input type="radio" class="form-check-input" name="key_management" value="decommissioned"<?php if($this->get('server')->key_management == 'decommissioned') out(' checked') ?>> <span class="form-check-label">Disabled - server has been decommissioned (remove all user access keys)</span></label>
				</div>
			</div>
			<div class="ska-setting-row" id="supervision">
				<div class="ska-setting-label">Key supervision</div>
				<div class="ska-setting-control ska-choice-list">
					<label class="form-check ska-choice ska-text-success"><input type="radio" class="form-check-input" name="key_scan" value="full"<?php if($this->get('server')->key_scan == 'full') out(' checked') ?>> <span class="form-check-label">Scan keys of root and all user accounts</span></label>
					<label class="form-check ska-choice"><input type="radio" class="form-check-input" name="key_scan" value="rootonly"<?php if($this->get('server')->key_scan == 'rootonly') out(' checked') ?>> <span class="form-check-label">Scan only keys of the root account, no other user accounts</span></label>
					<label class="form-check ska-choice"><input type="radio" class="form-check-input" name="key_scan" value="off"<?php if($this->get('server')->key_scan == 'off') out(' checked') ?>> <span class="form-check-label">Disabled - Do not scan any keys</span></label>
				</div>
			</div>
			<div class="ska-setting-row<?php if($this->get('server')->key_management != 'keys') out(' ska-hide') ?>" id="authorization">
				<div class="ska-setting-label">Accounts</div>
				<div class="ska-setting-control ska-choice-list">
					<label class="form-check ska-choice"><input type="radio" class="form-check-input" name="authorization" value="manual"<?php if($this->get('server')->authorization == 'manual') out(' checked') ?>> <span class="form-check-label">All accounts on the server are manually created</span></label>
					<label class="form-check ska-choice"><input type="radio" class="form-check-input" name="authorization" value="automatic LDAP"<?php if($this->get('server')->authorization == 'automatic LDAP') out(' checked') ?>> <span class="form-check-label">Accounts will be linked to LDAP and created automatically on the server</span></label>
					<label class="form-check ska-choice"><input type="radio" class="form-check-input" name="authorization" value="manual LDAP"<?php if($this->get('server')->authorization == 'manual LDAP') out(' checked') ?>> <span class="form-check-label">Accounts will be based on LDAP usernames but created manually on the server</span></label>
				</div>
			</div>
				<?php $options = $this->get('ldap_access_options'); ?>
				<div class="ska-setting-row<?php if($this->get('server')->key_management != 'keys' || $this->get('server')->authorization == 'manual') out(' ska-hide') ?>" id="ldap_access_options">
				<div class="ska-setting-label">LDAP access options</div>
				<div class="ska-setting-control">
					<label class="form-check ska-choice"><input type="checkbox" class="form-check-input" name="access_option[command][enabled]"<?php if(isset($options['command'])) out(' checked'); ?>> <span class="form-check-label">Specify command (<code>command=&quot;command&quot;</code>)</span></label>
					<input type="text" id="command_value" name="access_option[command][value]" value="<?php if(isset($options['command'])) out($options['command']->value); ?>" class="form-control">
					<label class="form-check ska-choice"><input type="checkbox" class="form-check-input" name="access_option[from][enabled]"<?php if(isset($options['from'])) out(' checked'); ?>> <span class="form-check-label">Restrict source address (<code>from=&quot;<abbr title="A pattern-list is a comma-separated list of patterns.  Each pattern can be either a hostname or an IP address, with wildcards (* and ?) allowed.">pattern-list</abbr>&quot;</code>)</span></label>
					<input type="text" id="from_value" name="access_option[from][value]" value="<?php if(isset($options['from'])) out($options['from']->value); ?>" class="form-control">
					<label class="form-check ska-choice"><input type="checkbox" class="form-check-input" name="access_option[no-port-forwarding][enabled]"<?php if(isset($options['no-port-forwarding'])) out(' checked'); ?>> <span class="form-check-label">Disallow port forwarding (<code>no-port-forwarding</code>)</span></label>
					<label class="form-check ska-choice"><input type="checkbox" class="form-check-input" name="access_option[no-X11-forwarding][enabled]"<?php if(isset($options['no-X11-forwarding'])) out(' checked'); ?>> <span class="form-check-label">Disallow X11 forwarding (<code>no-X11-forwarding</code>)</span></label>
					<label class="form-check ska-choice"><input type="checkbox" class="form-check-input" name="access_option[no-pty][enabled]"<?php if(isset($options['no-pty'])) out(' checked'); ?>> <span class="form-check-label">Disable terminal (<code>no-pty</code>)</span></label>
					</div>
				</div>
				<?php
				$history_username_env_mode = $this->get('server')->history_username_env_mode;
				if($history_username_env_mode != 'enabled' && $history_username_env_mode != 'disabled') {
					$history_username_env_mode = 'inherit';
				}
				$history_username_env_format = trim((string)$this->get('server')->history_username_env_format);
				?>
				<div class="ska-setting-row<?php if($this->get('server')->key_management != 'keys') out(' ska-hide') ?>" id="history_username_env">
					<label for="history_username_env_format" class="ska-setting-label">History username env</label>
					<div class="ska-setting-control">
						<div class="ska-choice-list">
							<label class="form-check ska-choice"><input type="radio" class="form-check-input" name="history_username_env_mode" value="inherit"<?php if($history_username_env_mode == 'inherit') out(' checked') ?>> <span class="form-check-label">Inherit global default</span></label>
							<label class="form-check ska-choice"><input type="radio" class="form-check-input" name="history_username_env_mode" value="enabled"<?php if($history_username_env_mode == 'enabled') out(' checked') ?>> <span class="form-check-label">Force enabled</span></label>
							<label class="form-check ska-choice"><input type="radio" class="form-check-input" name="history_username_env_mode" value="disabled"<?php if($history_username_env_mode == 'disabled') out(' checked') ?>> <span class="form-check-label">Force disabled</span></label>
						</div>
						<label for="history_username_env_format">Format override (optional)</label>
						<input type="text" id="history_username_env_format" name="history_username_env_format" value="<?php out($history_username_env_format); ?>" class="form-control">
						<p class="ska-help-text">Supported placeholder: <code>{uid}</code>. Format must include both <code>=</code> and <code>{uid}</code>; invalid values fall back to <code>BASH_HISTORY_USERNAME={uid}</code>.</p>
					</div>
				</div>
				<div class="ska-setting-actions">
						<button type="submit" name="edit_server" value="1" class="btn btn-primary">Change settings</button>
				</div>
			<?php } else { ?>
			<dl>
				<dt>SSH port number</dt>
				<dd><?php out($this->get('server')->port)?></dd>
				<dt>Jumphosts</dt>
				<dd><?php out($this->get('server')->jumphosts)?></dd>
				<dt>Key management</dt>
				<dd>
					<?php
					switch($this->get('server')->key_management) {
					case 'keys': out('SSH keys managed and synced by SSH Key Authority'); break;
					case 'none': out('Disabled - server has no key management'); break;
					case 'other': out('Disabled - SSH keys managed by another system'); break;
					case 'decommissioned': out('Disabled - server has been decommissioned (remove all user access keys)'); break;
					}
					?>
				</dd>
				<dt>Accounts</dt>
				<dd>
					<?php
					switch($this->get('server')->authorization) {
					case 'manual': out('All accounts on the server are manually created'); break;
					case 'automatic LDAP': out('Accounts will be linked to LDAP and created automatically on the server'); break;
					case 'manual LDAP': out('Accounts will be based on LDAP usernames but created manually on the server'); break;
					}
					?>
				</dd>
					<?php if($this->get('server')->key_management == 'keys' && $this->get('server')->authorization != 'manual') { ?>
					<dt>LDAP access options</dt>
				<dd>
					<?php
					$optiontext = array();
					foreach($this->get('ldap_access_options') as $option) {
						$optiontext[] = $option->option.(is_null($option->value) ? '' : '="'.str_replace('"', '\\"', $option->value).'"');
					}
					if(count($optiontext) == 0) {
						out('No options set');
					} else {
						?>
						<code><?php out(implode(' ', $optiontext)) ?></code>
						<?php
					}
					?>
					</dd>
					<?php } ?>
					<?php if($this->get('server')->key_management == 'keys') { ?>
					<dt>History username env</dt>
					<dd>
						<?php
						$history_username_env_mode = $this->get('server')->history_username_env_mode;
						$history_username_env_format = trim((string)$this->get('server')->history_username_env_format);
						switch($history_username_env_mode) {
						case 'enabled':
							out('Force enabled');
							break;
						case 'disabled':
							out('Force disabled');
							break;
						default:
							out('Inherit global default');
						}
						out(' | Format: ');
						if($history_username_env_format === '') {
							out('Inherit global format');
						} else {
							out($history_username_env_format);
						}
						?>
					</dd>
					<?php } ?>
				</dl>
			<?php if($this->get('server_admin_can_reset_host_key')) { ?>
			<div class="ska-setting-row">
				<label for="host_key" class="ska-setting-label">Host key</label>
				<div class="ska-setting-control">
					<input type="text" id="host_key" name="host_key" value="<?php out($this->get('server')->host_key)?>" readonly class="form-control">
					<button type="button" class="btn btn-secondary" data-clear="host_key">Clear</button>
				</div>
			</div>
			<div class="ska-setting-actions">
					<button type="submit" name="edit_server" value="1" class="btn btn-primary">Change settings</button>
			</div>
			<?php } ?>
			<?php } ?>
		</form>
	</div>
	<div class="tab-pane fade" id="log" role="tabpanel" aria-labelledby="server_log_tab" >
		<h2 class="visually-hidden">Log</h2>
		<div class="ska-scroll-container">
			<table class="table table-sm">
				<col></col>
				<col></col>
				<col></col>
				<col class="date"></col>
				<thead>
					<tr>
						<th>Entity</th>
						<th>User</th>
						<th>Activity</th>
						<th>Date (<abbr title="Coordinated Universal Time">UTC</abbr>)</th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach($this->get('server_log') as $event) {
						show_event($event);
					}
					?>
				</tbody>
			</table>
		</div>
	</div>
	<?php if($this->get('admin')) { ?>
	<div class="tab-pane fade" id="notes" role="tabpanel" aria-labelledby="server_notes_tab" >
		<h2 class="visually-hidden">Notes</h2>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<?php foreach($this->get('server_notes') as $note) { ?>
			<div class="ska-card ska-note-card">
				<div class="ska-card-body pre-formatted"><?php out($this->get('output_formatter')->comment_format($note->note), ESC_NONE)?></div>
				<div class="ska-card-footer">
					Added <?php out($note->date)?> by <?php out($note->user->name)?>
					<button name="delete_note" value="<?php out($note->id)?>" class="float-end btn btn-secondary btn-sm"><span class="ska-icon ska-icon-trash"></span> Delete</button>
				</div>
			</div>
			<?php } ?>
		</form>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<div class="mb-3">
				<label for="note">Note</label>
				<textarea class="form-control" rows="4" id="note" name="note" required></textarea>
			</div>
			<div class="mb-3">
				<button type="submit" name="add_note" value="1" class="btn btn-primary btn-lg w-100">Add note</button>
			</div>
		</form>
	</div>
	<div class="tab-pane fade" id="contact" role="tabpanel" aria-labelledby="server_contact_tab" >
		<h2 class="visually-hidden">Contact</h2>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<div class="mb-3">
				<label for="anonymous">From</label>
				<select class="form-control" id="anonymous" name="anonymous">
					<option value="0"><?php out("{$this->get('active_user')->name} <{$this->get('active_user')->email}>");?></option>
					<option value="1"><?php out($this->get('email_config')['from_name'])?> &lt;<?php out($this->get('email_config')['from_address'])?>&gt; (Reply-to: <?php out($this->get('email_config')['admin_address']) ?>)</option>
				</select>
			</div>
			<div class="mb-3">
				<label>Recipients</label>
				<div class="form-check">
					<label class="form-check-label">
						<input type="radio" class="form-check-input" name="recipients" value="admins" checked>
						<span>Server leaders of <?php out($this->get('server')->hostname) ?></span>
					</label>
				</div>
				<div class="form-check">
					<label class="form-check-label">
						<input type="radio" class="form-check-input" name="recipients" value="root_users">
						<span>All users with access to root@<?php out($this->get('server')->hostname) ?></span>
					</label>
				</div>
				<div class="form-check">
					<label class="form-check-label">
						<input type="radio" class="form-check-input" name="recipients" value="users">
						<span>All users with access to accounts on <?php out($this->get('server')->hostname) ?></span>
					</label>
				</div>
			</div>
			<div class="mb-3">
				<div class="form-check">
					<label class="form-check-label">
						<input type="checkbox" class="form-check-input" id="hide_recipients" name="hide_recipients">
						<span>Hide recipient list</span>
					</label>
				</div>
			</div>
			<div class="mb-3">
				<label for="subject">Subject</label>
				<input type="text" class="form-control" id="subject" name="subject" required value="Server <?php out('"'.$this->get('server')->hostname.'"') ?>">
			</div>
			<div class="mb-3">
				<label for="body">Body</label>
				<textarea class="form-control" rows="20" id="body" name="body" required></textarea>
			</div>
			<div class="mb-3"><button type="submit" name="send_mail" value="1" data-confirm="Send mail? Are you sure?" class="btn btn-primary btn-lg w-100">Send mail</button></div>
		</form>
	</div>
	<?php } ?>
</div>
<?php } else { ?>
<?php if($this->get('server')->authorization == 'manual') { ?>
<?php if(count($this->get('access_accounts')) == 1) { ?>
<?php $accounts = $this->get('access_accounts'); $account = reset($accounts) ?>
<p>You have access to the <i><?php out($account) ?></i> account on this server.</p>
<?php } elseif(count($this->get('access_accounts')) > 1) { ?>
<p>You have access to the following accounts on this server: <?php out(implode(', ', $this->get('access_accounts'))) ?>
</p>
<?php } ?>
<form method="post" action="<?php outurl($this->data->relative_request_url)?>">
	<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
	<h4>Request access to account</h4>
	<div class="row">
		<div class="col-sm-5">
			<label for="account_name" class="visually-hidden">Account name</label>
			<div class="input-group">
				<span class="input-group-text"><span class="ska-icon ska-icon-serveraccount" title="Server account"></span></span>
				<input type="text" id="account_name" name="account_name" class="form-control" placeholder="Account name" list="accountlist" required pattern=".*[^\s].*">
				<span class="input-group-text">@<?php out($this->get('server')->hostname)?></span>
				<datalist id="accountlist">
					<?php foreach($this->get('all_accounts') as $accounts) { ?>
					<option value="<?php out($accounts->name)?>">
					<?php } ?>
				</datalist>
			</div>
		</div>
		<div class="col-sm-7">
			<button type="submit" name="request_access" value="user" class="btn btn-primary">Request access</button>
			<a href="<?php outurl('/help#getting_access')?>" class="btn btn-info">Help</a>
		</div>
	</div>
</form>
<form method="post" action="<?php outurl($this->data->relative_request_url)?>">
	<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
	<h4>Request server-to-server access</h4>
	<div class="row">
		<div class="col-sm-3 mb-3">
			<div class="input-group">
				<span class="input-group-text">From: </span>
				<span class="input-group-text"><label for="account_remote"><span class="ska-icon ska-icon-serveraccount" title="Server account"></span><span class="visually-hidden">Account name</span></label></span>
				<input type="text" id="account_remote" name="account_remote" class="form-control" placeholder="Account name" required pattern=".*[^\s].*">
			</div>
		</div>
		<div class="col-sm-3 mb-3">
			<div class="input-group">
				<span class="input-group-text"><label for="hostname_remote">@<span class="visually-hidden">Hostname</span></label></span>
				<input type="text" id="hostname_remote" name="hostname_remote" class="form-control" placeholder="Hostname" list="serverlist" required>
				<datalist id="serverlist">
					<?php foreach($this->get('all_servers') as $server) { ?>
					<option value="<?php out($server->hostname)?>">
					<?php } ?>
				</datalist>
			</div>
		</div>
	</div>
	<div class="row">
		<div class="col-sm-5">
			<label for="account_name_s2s" class="visually-hidden">Account name</label>
			<div class="input-group">
				<span class="input-group-text">To: </span>
				<span class="input-group-text"><span class="ska-icon ska-icon-serveraccount" title="Server account"></span></span>
				<input type="text" id="account_name_s2s" name="account_name" class="form-control" placeholder="Account name" list="accountlist" required pattern=".*[^\s].*">
				<span class="input-group-text">@<?php out($this->get('server')->hostname)?></span>
			</div>
		</div>
		<div class="col-sm-3">
			<button type="submit" name="request_access" value="server_account" class="btn btn-primary">Request access</button>
			<a href="<?php outurl('/help#getting_access')?>" class="btn btn-info">Help</a>
		</div>
	</div>
</form>
<form method="post" action="<?php outurl($this->data->relative_request_url)?>">
	<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
	<h4>Request group access</h4>
	<div class="row">
		<div class="col-sm-5 mb-3">
			 <div class="input-group">
				<span class="input-group-text"><label for="group_account"><span class="ska-icon ska-icon-group" title="Group account"></span><span class="visually-hidden">Group name</span></label></span>
				<input type="text" id="group_account" name="group_account" class="form-control" placeholder="Group name" list="grouplist" required>
				<datalist id="grouplist">
					<?php foreach($this->get('all_groups') as $group) { ?>
					<option value="<?php out($group->name)?>">
					<?php } ?>
				</datalist>
			</div>
		</div>
	</div>
	<div class="row">
		<div class="col-sm-5">
			<label for="account_name_group" class="visually-hidden">Account name</label>
			<div class="input-group">
				<span class="input-group-text">To: </span>
				<span class="input-group-text"><span class="ska-icon ska-icon-serveraccount" title="Server account"></span></span>
				<input type="text" id="account_name_group" name="account_name" class="form-control" placeholder="Account name" list="accountlist" required pattern=".*[^\s].*">
				<span class="input-group-text">@<?php out($this->get('server')->hostname)?></span>
			</div>
		</div>
		<div class="col-sm-3">
			<button type="submit" name="request_access" value="group" class="btn btn-primary">Request access</button>
			<a href="<?php outurl('/help#getting_access')?>" class="btn btn-info">Help</a>
		</div>
	</div>
</form>
<?php } elseif($this->get('server')->authorization == 'automatic LDAP') { ?>
<p>Access to this server is based on LDAP accounts.</p>
<?php } elseif($this->get('server')->authorization == 'manual LDAP') { ?>
<p>Access to this server is based on LDAP accounts.  Contact the server leaders to get access.</p>
<?php } ?>
<?php if(count($this->get('admined_accounts')) > 0) { ?>
<h2>Managed accounts</h2>
<p>You are a leader for the following accounts on this server:</p>
<div class="ska-scroll-container">
	<table class="table table-bordered table-striped">
		<thead>
			<tr>
				<th>Account</th>
				<th>Sync status</th>
				<th>Account actions</th>
				<th colspan="2">Access granted for</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach($this->get('admined_accounts') as $account) { ?>
			<?php
			$access_list = $account->list_access();
			?>
			<tr>
				<th rowspan="<?php out(max(1, count($access_list)))?>">
					<a href="<?php outurl($this->data->relative_request_url.'/'.urlencode($account->name))?>" class="serveraccount"><?php out($account->name) ?></a>
					<?php if($account->pending_requests > 0) { ?>
					<a href="<?php outurl($this->data->relative_request_url.'/'.urlencode($account->name))?>"><span class="ska-badge ska-badge-muted" title="Pending requests"><?php out(number_format($account->pending_requests))?></span></a>
					<?php } ?>
				</th>
				<td rowspan="<?php out(max(1, count($access_list)))?>">
					<?php if($account->sync_is_pending()) { ?>
					<span class="ska-text-warning">Pending</span>
					<?php } elseif($this->get('server')->sync_status == 'sync success' || ($account->name == 'root' && $this->get('server')->sync_status == 'sync warning')) { ?>
					<span class="ska-text-success">Synced</span>
					<?php } elseif($this->get('server')->sync_status == 'sync warning') { ?>
					<span class="ska-text-warning">Not synced</span>
					<?php } elseif($this->get('server')->sync_status == 'sync failure') { ?>
					<span class="ska-text-danger">Failed</span>
					<?php } ?>
				</td>
				<td rowspan="<?php out(max(1, count($access_list)))?>">
					<a href="<?php outurl($this->data->relative_request_url.'/accounts/'.urlencode($account->name))?>" class="btn btn-secondary btn-sm"><span class="ska-icon ska-icon-cog"></span> Manage account</a>
				</td>
				<?php if(empty($access_list)) { ?>
				<td colspan="3"><em>No-one</em></td>
				<?php } else { ?>
				<?php
				$count = 0;
				foreach($access_list as $access) {
					$entity = $access->source_entity;
					$count++;
					if($count > 1) out('</tr><tr>', ESC_NONE);
					switch(get_class($entity)) {
					case 'User':
				?>
				<td><a href="<?php outurl('/users/'.urlencode($entity->uid))?>" class="user"><?php out($entity->uid) ?></a></td>
				<td><?php out($entity->name); if(!$entity->active) out(' <span class="ska-badge ska-badge-muted">Inactive</span>', ESC_NONE)?></td>
				<?php
						break;
					case 'ServerAccount':
				?>
				<td><a href="<?php outurl('/servers/'.urlencode($entity->server->hostname).'/accounts/'.urlencode($entity->name))?>" class="serveraccount"><?php out($entity->name.'@'.$entity->server->hostname) ?></a></td>
				<td><em>Server-to-server access</em><?php if($entity->server->key_management == 'decommissioned') out(' <span class="ska-badge ska-badge-muted">Inactive</span>', ESC_NONE) ?></td>
				<?php
						break;
					case 'Group':
				?>
				<td><a href="<?php outurl('/groups/'.urlencode($entity->name))?>" class="group"><?php out($entity->name) ?></a></td>
				<td><em>Group access</em></td>
				<?php
						break;
					}
				}
				?>
				<?php } ?>
			</tr>
			<?php } ?>
		</tbody>
	</table>
</div>
<?php } ?>
<?php } ?>