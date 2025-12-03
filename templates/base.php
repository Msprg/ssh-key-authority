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
$web_config = $this->get('web_config');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'");
$footer=str_replace("%v", "1.4.0", $web_config['footer']);
?>
<!DOCTYPE html>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php out($this->get('title'))?></title>
<link rel="stylesheet" href="<?php outurl('/bootstrap/css/bootstrap.min.css')?>">
<link rel="stylesheet" href="<?php outurl('/style.css?'.filemtime('public_html/style.css'))?>">
<link rel="icon" href="<?php outurl('/key.png')?>">
<script src="<?php outurl('/header.js?'.filemtime('public_html/header.js'))?>"></script>
<?php out($this->get('head'), ESC_NONE) ?>
<div id="wrap">
<a href="#content" class="sr-only">Skip to main content</a>
<div class="navbar navbar-fixed-top">
	<div class="container h-50px">
		<div class="ska-navbar">
			<?php if(!empty($web_config['logo'])) { ?>
			<a href="/" class="ska-logo">
				<img src="<?php out($web_config['logo'])?>" width="40px" height="40px" alt="logo">
				SSH Key Authority
			</a>
			<?php } ?>
			<?php if($this->get('active_user')) { ?>
			<div class="ska-navbar-items">
				<ul class="nav navbar-nav">
					<?php foreach($this->get('menu_items') as $url => $name) { ?>
					<li<?php if($url == $this->get('relative_request_url')) out(' class="active"', ESC_NONE); ?>><a href="<?php outurl($url)?>"><?php out($name)?></a></li>
					<?php } ?>
				</ul>
			</div>
			<div class="ska-navbar-side">
				<button class="ska-side-button dropdown-toggle" type="button" id="dropdownMenuButton" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
					<svg class="ska-dropdown-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 5h16M4 12h16M4 19h16"/></svg>
					<span class="ska-dropdown-name">
						<?php out($this->get('active_user')->name)?> 
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="M18.53 9.53a.75.75 0 0 0 0-1.06H5.47a.75.75 0 0 0 0 1.06l6 6a.75.75 0 0 0 1.06 0z"/></svg>
					</span>
				</button>
				<div class="dropdown-menu dropdown-menu-right" aria-labelledby="dropdownMenuButton">
						<div class="ska-dropdown"><span class="ska-dropdown-name-dp hidden-xl">
							<?php out($this->get('active_user')->name)?> 
						</span>
						<div class="ska-divider hidden-xl"></div>
						<?php foreach($this->get('menu_items') as $url => $name) { ?>
						<a <?php if($url == $this->get('relative_request_url')) out(' class="dropdown-item ska-dp-item hidden-xl active"', ESC_NONE); else out(' class="dropdown-item ska-dp-item hidden-xl"', ESC_NONE) ?> href="<?php outurl($url)?>"><?php out($name)?></a></li>
						<?php } ?>
						<div class="ska-divider hidden-xl"></div>
						<a class="dropdown-item ska-dp-item" href="<?php outurl('/logout')?>">Logout</a>
					</div>
				</div>
			</div>
			<?php } ?>
		</div>
	</div>
</div>
<div class="container" id="content">
<?php foreach($this->get('alerts') as $alert) { ?>
<div class="alert alert-<?php out($alert->class)?> alert-dismissable">
	<button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
	<?php out($alert->content, $alert->escaping)?>
</div>
<?php } ?>
<?php out($this->get('content'), ESC_NONE) ?>
</div>
</div>
<div id="footer">
	<div class="container">
		<p class="text-muted credit"><?php out($footer, ESC_NONE)?></p>
		<?php if($this->get('active_user') && $this->get('active_user')->developer) { ?>
		<?php } ?>
	</div>
</div>
<script src="<?php outurl('/jquery/jquery-3.2.1.min.js')?>"></script>
<script src="<?php outurl('/bootstrap/js/bootstrap.min.js')?>"></script>
<script src="<?php outurl('/extra.js?'.filemtime('public_html/extra.js'))?>"></script>
