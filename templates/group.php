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

$membercounts = array('User' => 0, 'ServerAccount' => 0, 'Group' => 0);
foreach($this->get('group_members') as $member) {
	$membercounts[get_class($member)]++;
}
?>
<h1><span class="glyphicon glyphicon-list-alt" title="Group"></span> <?php out($this->get('group')->name)?><?php if($this->get('group')->active == 0) out(' <span class="badge text-bg-secondary">Inactive</span>', ESC_NONE) ?></h1>
<?php if($this->get('admin') || $this->get('group_admin')) { ?>
<ul class="nav nav-tabs" role="tablist">
	<li class="nav-item active" role="presentation"><a href="#members" id="group_members_tab" class="nav-link active" role="tab" data-bs-toggle="tab" aria-controls="members" aria-selected="true">Members</a></li>
	<li class="nav-item" role="presentation"><a href="#access" id="group_access_tab" class="nav-link" role="tab" data-bs-toggle="tab" aria-controls="access" aria-selected="false" tabindex="-1">Access</a></li>
	<li class="nav-item" role="presentation"><a href="#outbound" id="group_outbound_tab" class="nav-link" role="tab" data-bs-toggle="tab" aria-controls="outbound" aria-selected="false" tabindex="-1">Outbound access</a></li>
	<li class="nav-item" role="presentation"><a href="#admins" id="group_admins_tab" class="nav-link" role="tab" data-bs-toggle="tab" aria-controls="admins" aria-selected="false" tabindex="-1">Administrators</a></li>
	<?php if($this->get('admin')) { ?>
	<li class="nav-item" role="presentation"><a href="#settings" id="group_settings_tab" class="nav-link" role="tab" data-bs-toggle="tab" aria-controls="settings" aria-selected="false" tabindex="-1">Settings</a></li>
	<?php } ?>
	<li class="nav-item" role="presentation"><a href="#log" id="group_log_tab" class="nav-link" role="tab" data-bs-toggle="tab" aria-controls="log" aria-selected="false" tabindex="-1">Log</a></li>
</ul>

<!-- Tab panes -->
<div class="tab-content">
	<div class="tab-pane fade in active show" id="members" role="tabpanel" aria-labelledby="group_members_tab" aria-hidden="false">
		<h2 class="visually-hidden">Group members</h2>
		<p><a href="<?php outurl('/groups/'.urlencode($this->get('group')->name).'/members.json') ?>" class="btn btn-secondary btn-sm">
			<span class="glyphicon glyphicon-console"></span> JSON
		</a></p>
		<?php if(count($this->get('group_members')) == 0) { ?>
		<p>No members have been added to this group yet.</p>
		<?php } else { ?>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<?php if($this->get('group')->system) { ?>
			<div class="alert alert-info">
				This is a system group. Its membership list cannot be edited.
			</div>
			<?php } ?>
			<div class="ska-scroll-container">
				<table class="table table-bordered table-striped">
					<thead>
						<tr>
							<th colspan="2">Member</th>
							<th>Status</th>
							<?php if(!$this->get('group')->system) { ?>
							<th>Actions</th>
							<?php } ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach($this->get('group_members') as $member) { ?>
						<tr>
							<?php
							switch(get_class($member)) {
							case 'User':
							?>
							<td><a href="<?php outurl('/users/'.urlencode($member->uid))?>" class="user"><?php out($member->uid)?></a></td>
							<td><?php out($member->name); if(!$member->active) out(' <span class="badge text-bg-secondary">Inactive</span>', ESC_NONE)?></td>
							<?php
								break;
							case 'ServerAccount':
							?>
							<td><a href="<?php outurl('/servers/'.urlencode($member->server->hostname).'/accounts/'.urlencode($member->name))?>" class="serveraccount"><?php out($member->name.'@'.$member->server->hostname)?></a></td>
							<td><em>Server account</em><?php if($member->server->key_management == 'decommissioned') out(' <span class="badge text-bg-secondary">Inactive</span>', ESC_NONE) ?></td>
							<?php
								break;
							case 'Group':
							?>
							<td><a href="<?php outurl('/groups/'.urlencode($member->name))?>" class="group"><?php out($member->name)?></a></td>
							<td><em>Group</em></td>
							<?php
								break;
							}
							?>
							<td>Added on <?php out($member->add_date) ?> by <a href="<?php outurl('/users/'.urlencode($member->added_by->uid))?>" class="user"><?php out($member->added_by->uid) ?></a></td>
							<?php if(!$this->get('group')->system) { ?>
							<td>
								<button type="submit" name="delete_member" value="<?php out($member->entity_id)?>" class="btn btn-secondary btn-sm"><span class="glyphicon glyphicon-ban-circle"></span> Remove from group</button>
							</td>
							<?php } ?>
							<?php } ?>
						</tr>
					</tbody>
				</table>
			</div>
		</form>
		<?php } ?>
		<?php if(!$this->get('group')->system) { ?>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<h3>Add user</h3>
			<div class="row">
				<div class="col-md-9 mb-3">
					<div class="input-group">
						<span class="input-group-text"><label for="username"><span class="glyphicon glyphicon-user" title="User"></span><span class="visually-hidden">User name</span></label></span>
						<input type="text" id="username" name="username" class="form-control" placeholder="User name" required list="userlist">
					</div>
				</div>
				<div class="col-md-3 mb-3">
					<button type="submit" name="add_member" value="1" class="btn btn-primary w-100">Add user to group</button>
				</div>
			</div>
		</form>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<h3>Add server account</h3>
			<div class="row">
				<div class="col-md-2 mb-3">
					<div class="input-group">
						<span class="input-group-text"><label for="account"><span class="glyphicon glyphicon-log-in" title="Server account"></span><span class="visually-hidden">Account</span></label></span>
						<input type="text" id="account" name="account" class="form-control" placeholder="Account name" required>
					</div>
				</div>
				<div class="col-md-7 mb-3">
					<div class="input-group">
						<span class="input-group-text"><label for="hostname">@</label></span>
						<input type="text" id="hostname" name="hostname" class="form-control" placeholder="Hostname" required list="<?php out($this->get('admin') ? 'serverlist' : 'adminedserverlist')?>">
					</div>
				</div>
				<div class="col-md-3 mb-3">
					<button type="submit" name="add_member" value="1" class="btn btn-primary w-100">Add server account to group</button>
				</div>
			</div>
		</form>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<h3>Add multiple server accounts</h3>
			<p>Enter a list of accounts, one per line, in the form accountname@hostname</p>
			<div class="row">
				<div class="col-md-12 mb-3">
					<textarea name="accounts" class="form-control"></textarea>
				</div>
			</div>
			<div class="row">
				<div class="col-md-3 mb-3">
					<button type="submit" name="add_members" value="1" class="btn btn-primary w-100">Add server accounts to group</button>
				</div>
			</div>
		</form>
		<?php } ?>
	</div>
	<div class="tab-pane fade" id="access" role="tabpanel" aria-labelledby="group_access_tab" aria-hidden="true">
		<h2 class="visually-hidden">Access</h2>
		<?php if(count($this->get('group_access')) == 0) { ?>
		<?php if($membercounts['ServerAccount'] > 0 || $membercounts['Group'] > 0) { ?>
		<p>No access has been granted to this group's resources.</p>
		<?php } ?>
		<?php } else { ?>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<div class="ska-scroll-container">
				<table class="table table-bordered table-striped">
					<thead>
						<tr>
							<th colspan="2">Access for</th>
							<th>Status</th>
							<th>Options</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach($this->get('group_access') as $access) { ?>
						<?php $entity = $access->source_entity; ?>
						<tr>
							<?php
							$options = $access->list_options();
							switch(get_class($entity)) {
							case 'User':
							?>
							<td><a href="<?php outurl('/users/'.urlencode($entity->uid))?>" class="user"><?php out($entity->uid)?></a></td>
							<td><?php out($entity->name); if(!$entity->active) out(' <span class="badge text-bg-secondary">Inactive</span>', ESC_NONE)?></td>
							<?php
								break;
							case 'ServerAccount':
							?>
							<td><a href="<?php outurl('/servers/'.urlencode($entity->server->hostname).'/accounts/'.urlencode($entity->name))?>" class="serveraccount"><?php out($entity->name.'@'.$entity->server->hostname)?></a></td>
							<td><em>Server account</em><?php if($entity->server->key_management == 'decommissioned') out(' <span class="badge text-bg-secondary">Inactive</span>', ESC_NONE) ?></td>
							<?php
								break;
							case 'Group':
							?>
							<td><a href="<?php outurl('/groups/'.urlencode($entity->name))?>" class="group"><?php out($entity->name)?></a></td>
							<td><em>Group</em></td>
							<?php
								break;
							}
							?>
							<td>Added on <?php out($access->grant_date) ?> by <a href="<?php outurl('/users/'.urlencode($access->granted_by->uid))?>" class="user"><?php out($access->granted_by->uid) ?></a></td>
							<td>
								<?php if(count($options) > 0) { ?>
								<ul class="compact">
									<?php foreach($options as $option) { ?>
									<li>
										<code>
											<?php
											out($option->option);
											if(!is_null($option->value)) {
												?>=&quot;<abbr title="<?php out($option->value)?>">…</abbr>&quot;<?php
											}
											?>
										</code>
									</li>
									<?php } ?>
								</ul>
								<?php } ?>
							</td>
							<td>
								<a href="<?php outurl('/groups/'.urlencode($this->get('group')->name).'/access_rules/'.urlencode($access->id))?>" class="btn btn-secondary btn-sm"><span class="glyphicon glyphicon-cog"></span> Configure access</a>
								<button type="submit" name="delete_access" value="<?php out($access->id)?>" class="btn btn-secondary btn-sm"><span class="glyphicon glyphicon-ban-circle"></span> Remove access</button>
							</td>
							<?php } ?>
						</tr>
					</tbody>
				</table>
			</div>
		</form>
		<?php } ?>
		<?php if($membercounts['ServerAccount'] == 0 && $membercounts['Group'] == 0) { ?>
		<p>This group does not contain any resources (server accounts or groups containing server accounts) to grant access to.</p>
		<?php } else { ?>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<h3>Grant user access</h3>
			<div class="row">
				<div class="col-md-8 mb-3">
					<div class="input-group">
						<span class="input-group-text"><label for="access-username"><span class="glyphicon glyphicon-user" title="User"></span><span class="visually-hidden">User name</span></label></span>
						<input type="text" id="access-username" name="username" class="form-control" placeholder="User name" required list="userlist">
					</div>
				</div>
				<div class="col-md-4 mb-3">
					<button type="submit" name="add_access" value="1" class="btn btn-primary w-100">Grant user access to group resources</button>
				</div>
			</div>
		</form>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<h3>Grant server account access</h3>
			<div class="row">
				<div class="col-md-2 mb-3">
					<div class="input-group">
						<span class="input-group-text"><label for="access-account"><span class="glyphicon glyphicon-log-in" title="Server account"></span><span class="visually-hidden">Account</span></label></span>
						<input type="text" id="access-account" name="account" class="form-control" placeholder="Account name" required>
					</div>
				</div>
				<div class="col-md-6 mb-3">
					<div class="input-group">
						<span class="input-group-text"><label for="access-hostname">@</label></span>
						<input type="text" id="access-hostname" name="hostname" class="form-control" placeholder="Hostname" required list="serverlist">
					</div>
				</div>
				<div class="col-md-4 mb-3">
					<button type="submit" name="add_access" value="1" class="btn btn-primary w-100">Grant server account access to group resources</button>
				</div>
			</div>
		</form>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<h3>Grant group access</h3>
			<div class="row">
				<div class="col-md-8 mb-3">
					<div class="input-group">
						<span class="input-group-text"><label for="access-group"><span class="glyphicon glyphicon-list-alt" title="Group"></span><span class="visually-hidden">Group name</span></label></span>
						<input type="text" id="access-group" name="group" class="form-control" placeholder="Group name" required list="grouplist">
					</div>
				</div>
				<div class="col-md-4 mb-3">
					<button type="submit" name="add_access" value="1" class="btn btn-primary w-100">Grant a group access to this group's resources</button>
				</div>
			</div>
		</form>
		<?php } ?>
	</div>
	<div class="tab-pane fade" id="outbound" role="tabpanel" aria-labelledby="group_outbound_tab" aria-hidden="true">
		<h2 class="visually-hidden">Outbound access</h2>
		<?php if(count($this->get('group_remote_access')) == 0) { ?>
		<p>This group has not been granted access to other resources.</p>
		<?php } else { ?>
		<p>This group has access to the following resources:</p>
		<div class="ska-scroll-container">
			<table class="table table-bordered table-striped">
				<thead>
					<tr>
						<th colspan="2">Access to</th>
						<th>Status</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach($this->get('group_remote_access') as $access) { ?>
					<?php $entity = $access->dest_entity; ?>
					<tr>
						<?php
						switch(get_class($entity)) {
						case 'User':
						?>
						<td><a href="<?php outurl('/users/'.urlencode($entity->uid))?>" class="user"><?php out($entity->uid)?></a></td>
						<td><?php out($entity->name); if(!$entity->active) out(' <span class="badge text-bg-secondary">Inactive</span>', ESC_NONE)?></td>
						<?php
							break;
						case 'ServerAccount':
						?>
						<td><a href="<?php outurl('/servers/'.urlencode($entity->server->hostname).'/accounts/'.urlencode($entity->name))?>" class="serveraccount"><?php out($entity->name.'@'.$entity->server->hostname)?></a></td>
						<td><em>Server account</em><?php if($entity->server->key_management == 'decommissioned') out(' <span class="badge text-bg-secondary">Inactive</span>', ESC_NONE) ?></td>
						<?php
							break;
						case 'Group':
						?>
						<td><a href="<?php outurl('/groups/'.urlencode($entity->name))?>" class="group"><?php out($entity->name)?></a></td>
						<td><em>Group</em></td>
						<?php
							break;
						}
						?>
						<td>Added on <?php out($access->grant_date) ?> by <a href="<?php outurl('/users/'.urlencode($access->granted_by->uid))?>" class="user"><?php out($access->granted_by->uid) ?></a></td>
						<?php } ?>
					</tr>
				</tbody>
			</table>
		</div>
		<?php } ?>
	</div>
	<div class="tab-pane fade" id="admins" role="tabpanel" aria-labelledby="group_admins_tab" aria-hidden="true">
		<h2 class="visually-hidden">Group administrators</h2>
		<?php if(count($this->get('group_admins')) == 0) { ?>
		<p class="alert alert-danger">This group does not have any administrators assigned.</p>
		<?php } else { ?>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<div class="ska-scroll-container">
				<table class="table table-bordered table-striped">
					<thead>
						<tr>
							<th>User ID</th>
							<th>Name</th>
							<?php if($this->get('admin')) { ?>
							<th>Actions</th>
							<?php } ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach($this->get('group_admins') as $admin) { ?>
						<tr>
							<td><a href="<?php outurl('/users/'.urlencode($admin->uid))?>" class="user"><?php out($admin->uid) ?></a></td>
							<td><?php out($admin->name); if(!$admin->active) out(' <span class="badge text-bg-secondary">Inactive</span>', ESC_NONE) ?></td>
							<?php if($this->get('admin')) { ?>
							<td>
								<button type="submit" name="delete_admin" value="<?php out($admin->id) ?>" class="btn btn-secondary btn-sm"><span class="glyphicon glyphicon-trash"></span> Remove admin</button>
							</td>
							<?php } ?>
						</tr>
						<?php } ?>
					</tbody>
				</table>
			</div>
		</form>
		<?php } ?>
		<?php if($this->get('admin')) { ?>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>" class="ska-inline-form">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<h3>Add administrator</h3>
			<div class="form-group">
				<label for="user_name" class="visually-hidden">User name</label>
				<input type="text" id="user_name" name="user_name" class="form-control" placeholder="User name" required list="userlist">
			</div>
			<button type="submit" name="add_admin" value="1" class="btn btn-primary">Add administrator to group</button>
		</form>
		<?php } ?>
	</div>
	<?php if($this->get('admin')) { ?>
	<div class="tab-pane fade" id="settings" role="tabpanel" aria-labelledby="group_settings_tab" aria-hidden="true">
		<h2 class="visually-hidden">Settings</h2>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>" class="ska-settings-form">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<div class="ska-setting-row">
				<label for="name" class="ska-setting-label">Name</label>
				<div class="ska-setting-control">
					<input type="text" id="name" name="name" value="<?php out($this->get('group')->name)?>" required class="form-control">
				</div>
			</div>
			<div class="ska-setting-row">
				<div class="ska-setting-label">Group status</div>
				<div class="ska-setting-control ska-choice-list">
					<label class="ska-choice text-success"><input type="radio" name="active" value="1"<?php if($this->get('group')->active == 1) out(' checked') ?>> <span>Enabled</span></label>
					<label class="ska-choice text-danger"><input type="radio" name="active" value="0"<?php if($this->get('group')->active == 0) out(' checked') ?>> <span>Disabled</span></label>
				</div>
			</div>
			<div class="ska-setting-actions">
					<button type="submit" name="edit_group" value="1" class="btn btn-primary">Change settings</button>
			</div>
		</form>
	</div>
	<?php } ?>
	<div class="tab-pane fade" id="log" role="tabpanel" aria-labelledby="group_log_tab" aria-hidden="true">
		<h2 class="visually-hidden">Log</h2>
		<div class="ska-scroll-container">
			<table class="table">
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
					foreach($this->get('group_log') as $event) {
						show_event($event);
					}
					?>
				</tbody>
			</table>
		</div>
	</div>
</div>
<datalist id="userlist">
	<?php foreach($this->get('all_users') as $user) { ?>
	<option value="<?php out($user->uid)?>" label="<?php out($user->name)?>">
	<?php } ?>
</datalist>
<datalist id="grouplist">
	<?php foreach($this->get('all_groups') as $group) { ?>
	<option value="<?php out($group->name)?>">
	<?php } ?>
</datalist>
<datalist id="adminedserverlist">
	<?php foreach($this->get('admined_servers') as $server) { ?>
	<option value="<?php out($server->hostname)?>">
	<?php } ?>
</datalist>
<datalist id="serverlist">
	<?php foreach($this->get('all_servers') as $server) { ?>
	<option value="<?php out($server->hostname)?>">
	<?php } ?>
</datalist>
<?php } else { ?>
<p>You do not have access to manage this group.</p>
<?php } ?>
