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
<ul class="nav nav-tabs" role="tablist">
	<li class="nav-item" role="presentation"><a href="#list" id="groups_list_tab" class="nav-link active" role="tab" data-bs-toggle="tab" aria-controls="list" aria-selected="true">Group list</a></li>
	<li class="nav-item" role="presentation"><a href="#add" id="groups_add_tab" class="nav-link" role="tab" data-bs-toggle="tab" aria-controls="add" aria-selected="false" tabindex="-1">Add group</a></li>
</ul>
<?php } ?>

<!-- Tab panes -->
<div class="tab-content">
	<div class="tab-pane fade active show" id="list" role="tabpanel"<?php if($this->get('admin')) out(' aria-labelledby="groups_list_tab"', ESC_NONE) ?> aria-hidden="false">
		<h2 class="visually-hidden">Group list</h2>
		<div class="ska-card-stack">
			<div class="card">
				<div class="card-header">
					<h3 class="h5 mb-0">
						Filter options
					</h3>
				</div>
					<div class="card-body">
					<form>
						<div class="row">
							<div class="col-sm-4">
								<div class="mb-3">
									<label for="name-search">Name (<a href="https://mariadb.com/kb/en/mariadb/regular-expressions-overview/">regexp</a>)</label>
										<input type="text" id="name-search" name="name" class="form-control" value="<?php out($this->get('filter')['name'])?>" autofocus>
								</div>
							</div>
							<div class="col-sm-3">
								<h4>Status</h4>
								<?php
								$options = array();
								$options['1'] = 'Active';
								$options['0'] = 'Inactive';
								foreach($options as $value => $label) {
									$checked = in_array($value, $this->get('filter')['active']) ? ' checked' : '';
								?>
									<div class="form-check"><label class="form-check-label"><input type="checkbox" class="form-check-input" name="active[]" value="<?php out($value)?>"<?php out($checked) ?>> <span><?php out($label) ?></span></label></div>
									<?php } ?>
								</div>
							</div>
							<button type="submit" class="btn btn-primary">Display results</button>
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
					<table class="table table-striped">
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
									<a href="<?php outurl('/groups/'.urlencode($group->name))?>" class="btn btn-secondary btn-sm"><span class="ska-icon ska-icon-cog"></span> Manage group</a>
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
		<div class="tab-pane fade" id="add" role="tabpanel" aria-labelledby="groups_add_tab" aria-hidden="true">
		<h2 class="visually-hidden">Add group</h2>
		<h3>Create local group</h3>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>" class="row g-3 align-items-end">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<div class="col-md-4">
				<label for="name" class="visually-hidden">Group name</label>
				<input type="text" id="name" name="name" class="form-control" placeholder="Group name" required>
			</div>
			<div class="col-md-5">
				<label for="admin_uid" class="visually-hidden">Administrator</label>
				<input type="text" size="40" id="admin_uid" name="admin_uid" class="form-control" placeholder="Administrator" required list="userlist">
				<datalist id="userlist">
					<?php foreach($this->get('all_users') as $user) { ?>
					<option value="<?php out($user->uid)?>" label="<?php out($user->name)?>">
					<?php } ?>
				</datalist>
			</div>
			<div class="col-md-auto">
				<button type="submit" name="add_group" value="1" class="btn btn-primary">Create group</button>
			</div>
		</form>
		<h3>Connect ldap group</h3>
		<form method="post" action="<?php outurl($this->data->relative_request_url)?>" class="row g-3 align-items-end">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<div class="ldap-treeview col-12">For the tree-view of ldap groups, javascript is necessary.</div>
			<div class="col-md-auto">
				<button type="submit" name="add_ldap_group" value="1" class="btn btn-primary">Connect selected groups</button>
			</div>
		</form>
	</div>
	<?php } ?>
</div>