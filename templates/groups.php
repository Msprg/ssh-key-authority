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
<h1>Groups</h1>
<?php if($this->get('admin')) { ?>
<ul class="ska-tabs" role="tablist">
	<li class="ska-tab-item active" role="presentation"><a href="#list" id="groups_list_tab" class="ska-tab-link active" role="tab" data-bs-toggle="tab" aria-controls="list" aria-selected="true">Group list</a></li>
	<li class="ska-tab-item" role="presentation"><a href="#add" id="groups_add_tab" class="ska-tab-link" role="tab" data-bs-toggle="tab" aria-controls="add" aria-selected="false" tabindex="-1">Add group</a></li>
</ul>
<?php } ?>

<!-- Tab panes -->
<div class="ska-tab-content">
	<div class="ska-tab-pane fade active show" id="list" role="tabpanel"<?php if($this->get('admin')) out(' aria-labelledby="groups_list_tab"', ESC_NONE) ?> aria-hidden="false">
		<h2 class="visually-hidden">Group list</h2>
		<div class="ska-card-stack">
			<div class="ska-card">
				<div class="ska-card-header">
					<h3 class="ska-card-title">
						Filter options
					</h3>
				</div>
					<div class="ska-card-body">
					<form>
						<div class="ska-row">
							<div class="ska-col-sm-4">
								<div class="ska-mb-3">
									<label for="name-search">Name (<a href="https://mariadb.com/kb/en/mariadb/regular-expressions-overview/">regexp</a>)</label>
									<input type="text" id="name-search" name="name" class="ska-form-control" value="<?php out($this->get('filter')['name'])?>" autofocus>
								</div>
							</div>
							<div class="ska-col-sm-3">
								<h4>Status</h4>
								<?php
								$options = array();
								$options['1'] = 'Active';
								$options['0'] = 'Inactive';
								foreach($options as $value => $label) {
									$checked = in_array($value, $this->get('filter')['active']) ? ' checked' : '';
								?>
								<div class="ska-form-check"><label class="ska-form-check-label"><input type="checkbox" class="ska-form-check-input" name="active[]" value="<?php out($value)?>"<?php out($checked) ?>> <span><?php out($label) ?></span></label></div>
								<?php } ?>
							</div>
						</div>
						<button type="submit" class="ska-btn ska-btn-primary">Display results</button>
					</form>
				</div>
			</div>
		</div>
		<?php if(count($this->get('groups')) == 0) { ?>
		<p>No groups found.</p>
		<?php } else { ?>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<div class="ska-scroll-container">
				<table class="ska-table ska-table-striped">
					<thead>
						<tr>
							<th>Group</th>
							<th>Members</th>
							<th>Admins</th>
							<?php if($this->get('admin')) { ?>
							<th>Actions</th>
							<?php } ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach($this->get('groups') as $group) { ?>
						<tr<?php if(!$group->active) out(' class="ska-text-muted"', ESC_NONE) ?>>
							<td><a href="<?php outurl('/groups/'.urlencode($group->name)) ?>" class="group<?php if(!$group->active) out(' ska-text-muted') ?>"><?php out($group->name) ?></a></td>
							<td><?php out(number_format($group->member_count))?></td>
							<td><?php out($group->admins)?></td>
							<?php if($this->get('admin')) { ?>
							<td>
								<a href="<?php outurl('/groups/'.urlencode($group->name))?>" class="ska-btn ska-btn-secondary ska-btn-sm"><span class="ska-icon ska-icon-cog"></span> Manage group</a>
							</td>
							<?php } ?>
						</tr>
						<?php } ?>
					</tbody>
				</table>
			</div>
		</form>
		<?php } ?>
	</div>
	<?php if($this->get('admin')) { ?>
		<div class="ska-tab-pane fade" id="add" role="tabpanel" aria-labelledby="groups_add_tab" aria-hidden="true">
		<h2 class="visually-hidden">Add group</h2>
		<h3>Create local group</h3>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>" class="ska-inline-form">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<div class="ska-form-group ska-mb-3">
				<label for="name" class="visually-hidden">Group name</label>
				<input type="text" id="name" name="name" class="ska-form-control" placeholder="Group name" required>
			</div>
			<div class="ska-form-group ska-mb-3">
				<label for="admin_uid" class="visually-hidden">Administrator</label>
				<input type="text" size="40" id="admin_uid" name="admin_uid" class="ska-form-control" placeholder="Administrator" required list="userlist">
				<datalist id="userlist">
					<?php foreach($this->get('all_users') as $user) { ?>
					<option value="<?php out($user->uid)?>" label="<?php out($user->name)?>">
					<?php } ?>
				</datalist>
			</div>
			<button type="submit" name="add_group" value="1" class="ska-btn ska-btn-primary">Create group</button>
		</form>
		<h3>Connect ldap group</h3>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>" class="ska-inline-form">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<div class="ldap-treeview">For the tree-view of ldap groups, javascript is necessary.</div>
			<div class="ska-form-group ska-mb-3">
				<label for="name" class="visually-hidden">Group name</label>
			</div>
			<button type="submit" name="add_ldap_group" value="1" class="ska-btn ska-btn-primary">Connect selected groups</button>
		</form>
	</div>
	<?php } ?>
</div>
