<?php
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
$input = $this->get('input');
$result = $this->get('result');
$form_url = AccessTroubleshooter::URL;
?>
<h1>Troubleshoot access</h1>
<p>Find out why somebody can or cannot log in to an account on a server: which access rules apply, which keys SKA writes, whether they have arrived on the server, and whether the SSH configuration there accepts them.</p>
<form method="post" action="<?php outurl($form_url)?>">
	<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
	<div class="row">
		<div class="form-group col-md-5">
			<label for="who">Who is logging in</label>
			<div class="input-group">
				<span class="input-group-addon"><span class="glyphicon glyphicon-user" title="User or key"></span></span>
				<input type="text" id="who" name="who" class="form-control" placeholder="User name, public key or key fingerprint" required list="userlist" value="<?php out($input['who'])?>">
				<datalist id="userlist">
					<?php foreach($this->get('all_users') as $user) { ?>
					<option value="<?php out($user->uid)?>" label="<?php out($user->name)?>">
					<?php } ?>
				</datalist>
			</div>
		</div>
		<div class="form-group col-md-4">
			<label for="server">Server</label>
			<div class="input-group">
				<span class="input-group-addon"><span class="glyphicon glyphicon-hdd" title="Server"></span></span>
				<input type="text" id="server" name="server" class="form-control" placeholder="Hostname" required list="serverlist" value="<?php out($input['server'])?>">
				<datalist id="serverlist">
					<?php foreach($this->get('all_servers') as $server) { ?>
					<option value="<?php out($server->hostname)?>">
					<?php } ?>
				</datalist>
			</div>
		</div>
		<div class="form-group col-md-3">
			<label for="account">Linux account</label>
			<div class="input-group">
				<span class="input-group-addon"><span class="glyphicon glyphicon-log-in" title="Account"></span></span>
				<input type="text" id="account" name="account" class="form-control" placeholder="e.g. root" required list="accountlist" value="<?php out($input['account'])?>">
				<datalist id="accountlist">
					<?php foreach($this->get('server_accounts') as $account) { ?>
					<option value="<?php out($account->name)?>">
					<?php } ?>
				</datalist>
			</div>
		</div>
	</div>
	<button type="submit" name="troubleshoot" value="1" class="btn btn-primary">Explain access</button>
</form>
<?php foreach($this->get('problems') as $problem) { ?>
<p class="alert alert-danger troubleshoot-verdict"><?php out($problem) ?></p>
<?php } ?>
<?php if(!is_null($result)) { ?>
<?php $verdict = $result['verdict']; ?>
<div class="alert alert-<?php out($verdict['class'])?> troubleshoot-verdict">
	<p><strong><?php show_parts($verdict['title']) ?></strong></p>
	<?php if(count($verdict['lines']) > 0) { ?>
	<ul>
		<?php foreach($verdict['lines'] as $line) { ?>
		<li><?php show_parts($line) ?></li>
		<?php } ?>
	</ul>
	<?php } ?>
</div>
<?php if($this->get('waiting')) { ?>
<p class="alert alert-info"><span class="glyphicon glyphicon-refresh"></span> A sync of this server has been requested. This page reloads until it has run and shows the fresh information from the server then.</p>
<?php } ?>
<?php
// The generated key file is shown once, in the section that deals with its content
$section_ids = array_column($result['sections'], 'id');
$keyfile_section = in_array('keys', $section_ids) ? 'keys' : (in_array('rules', $section_ids) ? 'rules' : 'account');
$panel_classes = array('ok' => 'panel-success', 'fail' => 'panel-danger', 'warn' => 'panel-warning', 'unknown' => 'panel-default', 'info' => 'panel-default');
foreach($result['sections'] as $section) {
	if(count($section['findings']) == 0) continue;
?>
<div class="panel <?php out($panel_classes[$section['status']])?>" id="section_<?php out($section['id'])?>">
	<div class="panel-heading">
		<h2 class="panel-title"><?php out($section['title']) ?></h2>
	</div>
	<div class="panel-body">
		<ul class="troubleshoot-findings">
			<?php foreach($section['findings'] as $finding) { ?>
			<li>
				<?php show_finding_icon($finding['status']) ?>
				<div>
					<?php show_parts($finding['text']) ?>
					<?php if(count($finding['details']) > 0) { ?>
					<ul>
						<?php foreach($finding['details'] as $detail) { ?>
						<li><?php show_parts($detail) ?></li>
						<?php } ?>
					</ul>
					<?php } ?>
				</div>
			</li>
			<?php } ?>
		</ul>
		<?php if($section['id'] == 'rules' && isset($section['rule_sets'])) { ?>
		<details<?php if(!$verdict['ska_grants']) out(' open') ?>>
			<summary>All access rules that apply to this account</summary>
			<div class="ska-scroll-container">
				<table class="table table-bordered table-condensed">
					<thead>
						<tr>
							<th>Rule set on</th>
							<th colspan="2">Access for</th>
							<th>Granted</th>
							<th>Options</th>
							<?php if(!is_null($result['subject'])) { ?>
							<th>Covers <?php show_parts(array($result['subject'])) ?>?</th>
							<?php } ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach($section['rule_sets'] as $set) { ?>
						<?php
						$target = $set['target'];
						$rows = max(1, count($set['rules']));
						$first = true;
						?>
						<tr>
							<td rowspan="<?php out($rows)?>">
								<?php show_parts(array($target)) ?>
								<?php if($target instanceof Group) { ?>
								<br><small class="text-muted">the account is in this group<?php if(count($set['chain']) > 1) { ?> through <?php show_parts(array_slice($set['chain'], 0, 1)) ?><?php } ?></small>
								<?php } else { ?>
								<br><small class="text-muted">the account itself</small>
								<?php } ?>
							</td>
							<?php if(count($set['rules']) == 0) { ?>
							<td colspan="<?php out(is_null($result['subject']) ? 4 : 5)?>"><em>No access rules</em></td>
							<?php } ?>
							<?php foreach($set['rules'] as $rule) { ?>
							<?php
							if(!$first) out('</tr><tr>', ESC_NONE);
							$first = false;
							$access = $rule['access'];
							$source = $access->source_entity;
							$match = $rule['match'];
							?>
							<td><?php show_parts(array($source)) ?></td>
							<td><em><?php
								if($source instanceof Group) out('Group access');
								elseif($source instanceof ServerAccount) out('Server-to-server access');
								else out($source->name);
							?></em></td>
							<td><?php out($access->grant_date) ?> by <?php show_parts(array($access->granted_by)) ?></td>
							<td>
								<?php foreach($rule['options'] as $option) { ?>
								<code><?php out($option->option.(is_null($option->value) ? '' : '="'.$option->value.'"')) ?></code>
								<?php } ?>
							</td>
							<?php if(!is_null($result['subject'])) { ?>
							<td<?php if($match['effective']) out(' class="success"', ESC_NONE); elseif($match['covers']) out(' class="warning"', ESC_NONE); ?>>
								<?php if($match['covers']) { ?>
								<strong>Yes</strong><?php
									foreach($match['paths'] as $index => $path) {
										if($index > 2) break;
										out($index == 0 ? ': member' : '; also');
										foreach(array_reverse($path) as $nested) {
											out(' of ');
											show_parts(array($nested));
											out(', which is a member');
										}
										out(' of ');
										show_parts(array($source));
									}
								?>
								<?php if(!$match['effective']) { ?>
								<br><span class="text-warning">Not in effect. <?php show_parts($set['active'] ? $match['note'] : array('The group that holds this rule is inactive.')) ?></span>
								<?php } ?>
								<?php } else { ?>
								No<?php if(count($match['note']) > 0) { ?> <span class="text-muted">(<?php show_parts($match['note']) ?>)</span><?php } ?>
								<?php } ?>
							</td>
							<?php } ?>
							<?php } ?>
						</tr>
						<?php } ?>
					</tbody>
				</table>
			</div>
		</details>
		<?php } ?>
		<?php if($section['id'] == $keyfile_section && !is_null($result['expected_keyfile'])) { ?>
		<?php
		$matching = array();
		foreach($result['expected_lines'] as $lines) {
			foreach($lines as $number => $line) $matching[$number] = true;
		}
		?>
		<details>
			<summary>Content that SKA writes to <code><?php out($result['keyfile_path']) ?></code></summary>
			<pre class="troubleshoot-keyfile"><?php
				foreach(explode("\n", rtrim($result['expected_keyfile'], "\n")) as $number => $line) {
					if(isset($matching[$number])) {
						?><mark><?php out($line) ?></mark><?php
					} else {
						out($line);
					}
					out("\n");
				}
			?></pre>
		</details>
		<?php } ?>
		<?php if($section['id'] == 'host' && isset($result['facts']['facts']['sshd_config']['files'])) { ?>
		<details>
			<summary>Settings that SKA read from the sshd configuration of the server</summary>
			<p class="text-muted">Only settings that affect key logins are collected. They are listed per file; sshd uses the first value it finds for a setting, reading each file at the place where it is included.</p>
			<?php foreach($result['facts']['facts']['sshd_config']['files'] as $path => $file) { ?>
			<h5><code><?php out($path) ?></code><?php if(($file['status'] ?? '') !== 'ok') { ?> <span class="label label-warning"><?php out($file['status'] ?? 'unknown') ?></span><?php } ?></h5>
			<?php if(count($file['entries'] ?? array()) > 0) { ?>
			<pre><?php
				foreach($file['entries'] as $entry) {
					out(str_pad((string)$entry[0], 4, ' ', STR_PAD_LEFT).': '.SshdConfig::format_directive($entry[1], $entry[2]).(in_array($entry[1], SshdConfig::PRESENCE_ONLY_KEYWORDS) && count($entry[2]) == 0 ? ' (value not collected)' : '')."\n");
				}
			?></pre>
			<?php } elseif(($file['status'] ?? '') === 'ok') { ?>
			<p><em>No relevant settings.</em></p>
			<?php } ?>
			<?php } ?>
		</details>
		<?php } ?>
		<?php if($section['id'] == 'delivery' && $result['server']->key_management == 'keys') { ?>
		<form method="post" action="<?php outurl($form_url)?>" class="troubleshoot-refresh">
			<?php out($this->get('active_user')->get_csrf_field(), ESC_NONE) ?>
			<input type="hidden" name="who" value="<?php out($input['who'])?>">
			<input type="hidden" name="server" value="<?php out($input['server'])?>">
			<input type="hidden" name="account" value="<?php out($input['account'])?>">
			<button type="submit" name="refresh" value="1" class="btn btn-default btn-sm"><span class="glyphicon glyphicon-refresh"></span> Sync this server now and re-check</button>
		</form>
		<?php } ?>
	</div>
</div>
<?php } ?>
<?php } ?>
