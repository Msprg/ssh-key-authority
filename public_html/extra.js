/*
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
*/
'use strict';

function dom_ready(callback) {
	if(document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', callback);
	} else {
		callback();
	}
}

function show_dynamic_element(element) {
	if(!element) {
		return;
	}

	element.classList.remove('hidden', 'd-none');
	element.style.display = '';
}

function hide_dynamic_element(element) {
	if(!element) {
		return;
	}

	element.classList.remove('hidden', 'd-none');
	element.style.display = 'none';
}

function toggle_dynamic_element(element) {
	if(!element) {
		return;
	}

	if(window.getComputedStyle(element).display === 'none') {
		show_dynamic_element(element);
	} else {
		hide_dynamic_element(element);
	}
}

// Handle 'navigate-back' links
dom_ready(function() {
	var links = document.querySelectorAll('a.navigate-back');
	for(var i = 0; i < links.length; i++) {
		links[i].addEventListener('click', function(event) {
			event.preventDefault();
			window.history.back();
			event.stopPropagation();
		});
	}
});

// Native dropdown and alert-dismiss behavior for the base shell.
(function() {
	var dropdownSelector = '[data-bs-toggle="dropdown"]';
	var alertDismissSelector = '[data-bs-dismiss="alert"]';

	function dispatch_bootstrap_event(target, type, relatedTarget) {
		return target.dispatchEvent(new CustomEvent(type, {
			bubbles: true,
			cancelable: true,
			detail: {relatedTarget: relatedTarget || null}
		}));
	}

	function get_dropdown_root(trigger) {
		return trigger.closest('.dropdown') || trigger.parentElement;
	}

	function get_dropdown_menu(root) {
		if(!root) {
			return null;
		}
		return root.querySelector('.dropdown-menu');
	}

	function close_dropdown(root, trigger, relatedTarget) {
		if(!root || !root.classList.contains('open')) {
			return;
		}

		var menu = get_dropdown_menu(root);
		if(!dispatch_bootstrap_event(root, 'hide.bs.dropdown', relatedTarget || trigger)) {
			return;
		}

		root.classList.remove('open');
		if(trigger) {
			trigger.setAttribute('aria-expanded', 'false');
		}
		if(menu) {
			menu.setAttribute('aria-hidden', 'true');
		}

		dispatch_bootstrap_event(root, 'hidden.bs.dropdown', relatedTarget || trigger);
	}

	function close_other_dropdowns(currentRoot, relatedTarget) {
		var openDropdowns = document.querySelectorAll('.dropdown.open');
		for(var i = 0; i < openDropdowns.length; i++) {
			if(openDropdowns[i] === currentRoot) {
				continue;
			}

			var trigger = openDropdowns[i].querySelector(dropdownSelector);
			close_dropdown(openDropdowns[i], trigger, relatedTarget);
		}
	}

	function open_dropdown(root, trigger) {
		var menu = get_dropdown_menu(root);
		if(!root || !menu) {
			return;
		}

		close_other_dropdowns(root, trigger);
		if(root.classList.contains('open')) {
			return;
		}

		if(!dispatch_bootstrap_event(root, 'show.bs.dropdown', trigger)) {
			return;
		}

		root.classList.add('open');
		trigger.setAttribute('aria-expanded', 'true');
		menu.setAttribute('aria-hidden', 'false');

		dispatch_bootstrap_event(root, 'shown.bs.dropdown', trigger);
	}

	document.addEventListener('click', function(event) {
		var dropdownTrigger = event.target.closest(dropdownSelector);
		if(dropdownTrigger) {
			var dropdownRoot = get_dropdown_root(dropdownTrigger);
			if(!dropdownRoot) {
				return;
			}

			event.preventDefault();
			if(dropdownRoot.classList.contains('open')) {
				close_dropdown(dropdownRoot, dropdownTrigger, dropdownTrigger);
			} else {
				open_dropdown(dropdownRoot, dropdownTrigger);
			}
			return;
		}

		var dismissButton = event.target.closest(alertDismissSelector);
		if(dismissButton) {
			var alertTargetSelector = dismissButton.getAttribute('data-bs-target') || dismissButton.getAttribute('data-target');
			var alertElement = null;

			if(alertTargetSelector && alertTargetSelector.indexOf('#') === 0) {
				alertElement = document.querySelector(alertTargetSelector);
			}
			if(!alertElement) {
				alertElement = dismissButton.closest('.alert');
			}
			if(!alertElement) {
				return;
			}

			event.preventDefault();
			if(!dispatch_bootstrap_event(alertElement, 'close.bs.alert', dismissButton)) {
				return;
			}

			alertElement.parentNode.removeChild(alertElement);
			dispatch_bootstrap_event(alertElement, 'closed.bs.alert', dismissButton);
			return;
		}

		close_other_dropdowns(null, event.target);
	});

	document.addEventListener('keydown', function(event) {
		if(event.key !== 'Escape') {
			return;
		}

		var openDropdowns = document.querySelectorAll('.dropdown.open');
		for(var i = 0; i < openDropdowns.length; i++) {
			var trigger = openDropdowns[i].querySelector(dropdownSelector);
			close_dropdown(openDropdowns[i], trigger, event.target);
			if(trigger) {
				trigger.focus();
			}
		}
	});
})();

// Bootstrap 5-compatible tab behavior for migrated tabsets.
dom_ready(function() {
	var selector = '[data-bs-toggle="tab"][data-ska-skip-legacy]';
	var migratedTabs = document.querySelectorAll(selector);

	if(!migratedTabs.length) {
		return;
	}

	function get_tab_target(link) {
		var target = link.getAttribute('data-bs-target') || link.getAttribute('href') || '';
		if(target.indexOf('#') !== 0) {
			return null;
		}
		return document.getElementById(target.substring(1));
	}

	function get_tab_group(link) {
		return link.closest('.nav-tabs, .nav-pills');
	}

	function find_active_tab(links) {
		for(var i = 0; i < links.length; i++) {
			if(links[i].classList.contains('active')) {
				return links[i];
			}

			var item = links[i].closest('li');
			if(item && item.classList.contains('active')) {
				return links[i];
			}
		}

		return null;
	}

	function set_history_hash(hash) {
		if(window.history && history.replaceState) {
			history.replaceState(null, null, hash);
		} else {
			window.location.hash = hash;
		}
	}

	function show_tab(link, updateHash) {
		var group = get_tab_group(link);
		var target = get_tab_target(link);
		if(!group || !target) {
			return;
		}

		var links = group.querySelectorAll(selector);
		var previous = find_active_tab(links);
		var showEvent = new CustomEvent('show.bs.tab', {
			bubbles: true,
			cancelable: true,
			detail: {relatedTarget: previous}
		});
		if(!link.dispatchEvent(showEvent)) {
			return;
		}

		for(var i = 0; i < links.length; i++) {
			links[i].classList.remove('active');
			links[i].setAttribute('aria-selected', 'false');
			links[i].setAttribute('tabindex', '-1');

			var item = links[i].closest('li');
			if(item) {
				item.classList.remove('active');
			}
		}

		var tabContent = target.closest('.tab-content');
		if(tabContent) {
			var panes = tabContent.querySelectorAll('.tab-pane');
			for(var p = 0; p < panes.length; p++) {
				panes[p].classList.remove('active');
				panes[p].classList.remove('in');
				panes[p].classList.remove('show');
				panes[p].setAttribute('aria-hidden', 'true');
			}
		}

		link.classList.add('active');
		link.setAttribute('aria-selected', 'true');
		link.removeAttribute('tabindex');

		var activeItem = link.closest('li');
		if(activeItem) {
			activeItem.classList.add('active');
		}

		target.classList.add('active');
		target.classList.add('show');
		if(target.classList.contains('fade')) {
			target.classList.add('in');
		}
		target.setAttribute('aria-hidden', 'false');

		if(updateHash) {
			set_history_hash('#' + target.id);
		}

		link.dispatchEvent(new CustomEvent('shown.bs.tab', {
			bubbles: true,
			detail: {relatedTarget: previous}
		}));
	}

	for(var t = 0; t < migratedTabs.length; t++) {
		migratedTabs[t].addEventListener('click', function(event) {
			event.preventDefault();
			show_tab(this, true);
		});
	}

	var groups = document.querySelectorAll('.nav-tabs, .nav-pills');
	var fragment = window.location.hash ? window.location.hash.substring(1) : '';

	for(var g = 0; g < groups.length; g++) {
		var groupLinks = groups[g].querySelectorAll(selector);
		if(!groupLinks.length) {
			continue;
		}

		var initial = null;
		if(fragment) {
			for(var h = 0; h < groupLinks.length; h++) {
				var target = get_tab_target(groupLinks[h]);
				if(target && target.id === fragment) {
					initial = groupLinks[h];
					break;
				}
			}
		}

		if(!initial) {
			initial = find_active_tab(groupLinks) || groupLinks[0];
		}

		show_tab(initial, false);
	}
});

// Remember the expanded-state of a collapsible section
dom_ready(function() {
	var migratedCollapseSelector = '.collapse[data-ska-skip-legacy]';
	var migratedCollapseTriggers = document.querySelectorAll('[data-bs-toggle="collapse"][data-ska-skip-legacy]');

	function get_collapse_target(trigger) {
		var target = trigger.getAttribute('data-bs-target') || trigger.getAttribute('href') || '';
		if(target.indexOf('#') !== 0) {
			return null;
		}
		return document.getElementById(target.substring(1));
	}

	function get_collapse_triggers(collapse) {
		var triggers = [];
		for(var i = 0; i < migratedCollapseTriggers.length; i++) {
			if(get_collapse_target(migratedCollapseTriggers[i]) === collapse) {
				triggers.push(migratedCollapseTriggers[i]);
			}
		}
		return triggers;
	}

	function set_collapse_state(collapse, expanded) {
		var triggers = get_collapse_triggers(collapse);
		collapse.classList.toggle('in', expanded);
		collapse.classList.toggle('show', expanded);
		collapse.setAttribute('aria-hidden', expanded ? 'false' : 'true');

		for(var i = 0; i < triggers.length; i++) {
			triggers[i].setAttribute('aria-expanded', expanded ? 'true' : 'false');
			triggers[i].classList.toggle('collapsed', !expanded);
		}
	}

	function collapse_related_sections(trigger, collapse) {
		var parentSelector = trigger.getAttribute('data-bs-parent') || collapse.getAttribute('data-bs-parent');
		if(!parentSelector) {
			return;
		}

		var parent = document.querySelector(parentSelector);
		if(!parent) {
			return;
		}

		var related = parent.querySelectorAll(migratedCollapseSelector);
		for(var i = 0; i < related.length; i++) {
			if(related[i] !== collapse) {
				set_collapse_state(related[i], false);
			}
		}
	}

	function get_section_fragment() {
		var url = document.location.toString();
		if(url.match('#')) {
			return url.split('#')[1];
		}
		return '';
	}

	function sync_migrated_collapses_from_location() {
		var fragment = get_section_fragment();
		var collapses = document.querySelectorAll(migratedCollapseSelector);

		for(var i = 0; i < collapses.length; i++) {
			var expanded = collapses[i].id === fragment;
			if(expanded) {
				var triggers = get_collapse_triggers(collapses[i]);
				if(triggers.length) {
					collapse_related_sections(triggers[0], collapses[i]);
				}
			}
			set_collapse_state(collapses[i], expanded);
		}
	}

	for(var c = 0; c < migratedCollapseTriggers.length; c++) {
		migratedCollapseTriggers[c].addEventListener('click', function(event) {
			var collapse = get_collapse_target(this);
			if(!collapse) {
				return;
			}

			event.preventDefault();
			var expanded = !collapse.classList.contains('in');
			if(expanded) {
				collapse_related_sections(this, collapse);
				set_collapse_state(collapse, true);
				if(history) {
					history.replaceState(null, null, '#' + collapse.id);
				} else {
					window.location.hash = collapse.id;
				}
			} else {
				set_collapse_state(collapse, false);
			}
		});
	}

	if(document.querySelector(migratedCollapseSelector)) {
		sync_migrated_collapses_from_location();
	}

	get_section_from_location();
	window.onpopstate = function(event) {
		sync_migrated_collapses_from_location();
		get_section_from_location();
	};
	function get_section_from_location() {
		// Javascript to enable link to section
		var url = document.location.toString();
		if(url.match('#')) {
			var fragment = url.split('#')[1];
		} else {
			var fragment = '';
		}
		$(".collapse").not('[data-ska-skip-legacy]').each(function(){
			if(this.id == fragment) $(this).addClass("in");
			else $(this).removeClass("in");
		});
	}

	// Do the location modifying code after all other setup, since we don't want the initial loading to trigger this
	$('.panel-collapse').not('[data-ska-skip-legacy]').on('show.bs.collapse', function (e) {
		if(history) {
			history.replaceState(null, null, '#' + e.target.id);
		} else {
			window.location.hash = e.target.id;
		}
	});

});

// Show only chosen fingerprint hash format in list views
dom_ready(function() {
	var fingerprintHeader = document.querySelector('table th.fingerprint');
	if(!fingerprintHeader) {
		return;
	}

	var select = document.createElement('select');
	var options = ['MD5', 'SHA256'];
	for(var i = 0; i < options.length; i++) {
		var option = document.createElement('option');
		option.value = options[i];
		option.textContent = options[i];
		select.appendChild(option);
	}

	if(window.localStorage) {
		var preferredHash = localStorage.getItem('preferred_fingerprint_hash');
		if(preferredHash) {
			select.value = preferredHash;
		}
	}

	function update_fingerprint_visibility() {
		var showSha256 = select.value === 'SHA256';
		var md5Fingerprints = document.querySelectorAll('span.fingerprint_md5');
		var sha256Fingerprints = document.querySelectorAll('span.fingerprint_sha256');

		for(var j = 0; j < md5Fingerprints.length; j++) {
			md5Fingerprints[j].style.display = showSha256 ? 'none' : '';
		}
		for(var k = 0; k < sha256Fingerprints.length; k++) {
			sha256Fingerprints[k].style.display = showSha256 ? '' : 'none';
		}

		if(window.localStorage) {
			localStorage.setItem('preferred_fingerprint_hash', select.value);
		}
	}

	fingerprintHeader.appendChild(document.createTextNode(' '));
	fingerprintHeader.appendChild(select);
	select.addEventListener('change', update_fingerprint_visibility);
	update_fingerprint_visibility();
});

// Add confirmation dialog to all submit buttons with data-confirm attribute
dom_ready(function() {
	var buttons = document.querySelectorAll('button[type="submit"][data-confirm]');
	for(var i = 0; i < buttons.length; i++) {
		buttons[i].addEventListener('click', function(event) {
			if(!window.confirm(this.getAttribute('data-confirm'))) {
				event.preventDefault();
			}
		});
	}
});

// Add "clear field" button functionality
dom_ready(function() {
	var buttons = document.querySelectorAll('button[data-clear]');
	for(var i = 0; i < buttons.length; i++) {
		buttons[i].addEventListener('click', function() {
			if(!this.form) {
				return;
			}

			var fieldName = this.getAttribute('data-clear');
			var field = this.form.elements[fieldName];
			if(field) {
				field.value = '';
			}
		});
	}
});

// Home page dynamic add pubkey form
dom_ready(function() {
	var addKeyButton = document.getElementById('add_key_button');
	var help = document.getElementById('help');
	var addKeyForm = document.getElementById('add_key_form');
	var addPublicKey = document.getElementById('add_public_key');

	if(!addKeyButton || !addKeyForm) {
		return;
	}

	addKeyButton.addEventListener('click', function(event) {
		event.preventDefault();
		hide_dynamic_element(help);
		show_dynamic_element(addKeyForm);
		hide_dynamic_element(addKeyButton);
		if(addPublicKey) {
			addPublicKey.focus();
		}
	});

	var helpButtons = addKeyForm.querySelectorAll('[data-action="toggle-help"], button[type="button"].btn-info');
	for(var i = 0; i < helpButtons.length; i++) {
		helpButtons[i].addEventListener('click', function() {
			toggle_dynamic_element(help);
		});
	}

	var cancelButtons = addKeyForm.querySelectorAll('[data-action="cancel-add-key"], button[type="button"].btn-secondary');
	for(var j = 0; j < cancelButtons.length; j++) {
		cancelButtons[j].addEventListener('click', function() {
			hide_dynamic_element(addKeyForm);
			show_dynamic_element(addKeyButton);
		});
	}
});

// Show/hide appropriate sections of the server settings form
$(function() {
	var form = $('#server_settings');
	form.each(function() {
		$('#authorization.hide').hide().removeClass('hide');
		$('#ldap_access_options.hide').hide().removeClass('hide');
		$('#history_username_env.hide').hide().removeClass('hide');
		$("input[name='key_management']", form).on('click', function() {display_relevant_options()});
		$("input[name='authorization']", form).on('click', function() {display_relevant_options()});
		function display_relevant_options() {
			if($("input[name='key_management']:checked").val() == 'keys') {
				$('#authorization').show('fast');
				$('#supervision').show('fast');
				$('#history_username_env').show('fast');
				if($("input[name='authorization']:checked").val() == 'manual') {
					$('#ldap_access_options').hide('fast');
				} else {
					$('#ldap_access_options').show('fast');
				}
			} else {
				$('#authorization').hide('fast');
				$('#ldap_access_options').hide('fast');
				$('#supervision').hide('fast');
				$('#history_username_env').hide('fast');
			}
		}

		var ao_command_enabled = $("input[name='access_option[command][enabled]']", form);
		var ao_command_value = $("input[name='access_option[command][value]']", form);
		var ao_from_enabled = $("input[name='access_option[from][enabled]']", form);
		var ao_from_value = $("input[name='access_option[from][value]']", form);
		ao_command_enabled.on('click', function() {ao_update_disabled()});
		ao_from_enabled.on('click', function() {ao_update_disabled()});
		ao_update_disabled();
		function ao_update_disabled() {
			ao_command_value.prop('disabled', !ao_command_enabled.prop('checked'));
			ao_command_value.prop('required', ao_command_enabled.prop('checked'));
			ao_from_value.prop('disabled', !ao_from_enabled.prop('checked'));
			ao_from_value.prop('required', ao_from_enabled.prop('checked'));
		}
	});
});

// Enable/disable relevant sections of the access options form
$(function() {
	var form = $('#access_options');
	form.each(function() {
		var ao_command_enabled = $("input[name='access_option[command][enabled]']", form);
		var ao_command_value = $("input[name='access_option[command][value]']", form);
		var ao_from_enabled = $("input[name='access_option[from][enabled]']", form);
		var ao_from_value = $("input[name='access_option[from][value]']", form);
		var ao_noportfwd_enabled = $("input[name='access_option[no-port-forwarding][enabled]']", form);
		var ao_nox11fwd_enabled = $("input[name='access_option[no-X11-forwarding][enabled]']", form);
		var ao_nopty_enabled = $("input[name='access_option[no-pty][enabled]']", form);

		ao_command_enabled.on('click', function() {ao_update_disabled()});
		ao_from_enabled.on('click', function() {ao_update_disabled()});

		$("button[type='button']", form).on('click', function(e) {
			var preset
			if(preset = $(e.target).attr('data-preset')) {
				$('input:checkbox', form).val([]);
				ao_command_value.val('');
				ao_from_value.val('');
				if(preset == 'command' || preset == 'dbbackup' || preset == 'checkmk') {
					ao_command_enabled.prop('checked', true);
					ao_command_value.focus();
					ao_noportfwd_enabled.prop('checked', true);
					ao_nox11fwd_enabled.prop('checked', true);
					ao_nopty_enabled.prop('checked', true);
				}
				if(preset == 'dbbackup') {
					ao_command_value.val('/usr/bin/innobackupex --slave-info --defaults-file=/etc/mysql/my.cnf /var/tmp');
				} else if (preset == 'checkmk') {
					ao_command_value.val('/usr/bin/check_mk_agent');
				}
			}
			ao_update_disabled();
		});
		ao_update_disabled();
		function ao_update_disabled() {
			ao_command_value.prop('disabled', !ao_command_enabled.prop('checked'));
			ao_command_value.prop('required', ao_command_enabled.prop('checked'));
			ao_from_value.prop('disabled', !ao_from_enabled.prop('checked'));
			ao_from_value.prop('required', ao_from_enabled.prop('checked'));
		}
	});
});

// Provide dynamic reassign form on user page
$(function() {
	$('button[data-reassign]').on('click', function() {
		var id = $(this).data('reassign');
		var table = $('#' + id);
		var cell = document.createElement('th');
		var checkbox = document.createElement('input');
		checkbox.type = 'checkbox';
		$(checkbox).on('click', function() {$("input[type='checkbox']", table).prop('checked', this.checked)});
		cell.appendChild(checkbox);
		table.children('thead').children('tr').prepend(cell);
		table.children('tbody').children('tr').each(function() {
			var hostname = $(this).children('td:first-child').text().trim();
			var cell = document.createElement('td');
			var checkbox = document.createElement('input');
			checkbox.type = 'checkbox';
			checkbox.name = 'servers[]';
			checkbox.value = hostname;
			cell.appendChild(checkbox);
			$(this).prepend(cell);
		});
		$(this).parent().append('<div class="form-group"><label>Reassign to <input type="text" name="reassign_to" class="form-control"></label></div>');
		$(this).parent().append('<div class="form-group"><button type="submit" name="reassign_servers" class="btn btn-primary">Reassign selected servers</button></div>');
		$(this).remove();
	});
});

// Server sync status
$(function() {
	var status_div = $('#server_sync_status');
	status_div.each(function() {
		if(status_div.data('class')) {
			update_server_sync_status(status_div.data('class'), status_div.data('message'));
			$('span.server_account_sync_status').each(function() {
				update_server_account_sync_status(this.id, $(this).data('class'), $(this).data('message'));
			});
		} else {
			$('span', status_div).addClass('text-warning');
			$('span', status_div).text('Pending');
			$('span.server_account_sync_status').addClass('text-warning');
			$('span.server_account_sync_status').text('Pending');
			var timeout = 1000;
			var max_timeout = 10000;
			get_server_sync_status();
		}
		function get_server_sync_status() {
			var xhr = $.ajax({
				url: window.location.pathname + '/sync_status',
				dataType: 'json'
			});
			xhr.done(function(status) {
				if(status.pending) {
					timeout = Math.min(timeout * 1.5, max_timeout);
					setTimeout(get_server_sync_status, timeout);
				} else {
					var classname;
					if(status.sync_status == 'sync success') classname = 'success';
					if(status.sync_status == 'sync failure') classname = 'danger';
					if(status.sync_status == 'sync warning') classname = 'warning';
					update_server_sync_status(classname, status.last_sync.details);
				}
				$.each(status.accounts, function(index, item) {
					if(!item.pending) {
						var classname;
						var message;
						if(item.sync_status == 'proposed') { classname = 'info'; message = 'Requested'; }
						if(item.sync_status == 'sync success') { classname = 'success'; message = 'Synced'; }
						if(item.sync_status == 'sync failure') { classname = 'danger'; message = 'Failed'; }
						if(item.sync_status == 'sync warning') { classname = 'warning'; message = 'Not synced'; }
						update_server_account_sync_status('server_account_sync_status_' + item.name, classname, message);
					}
				});
			});
		}
		function update_server_sync_status(classname, message) {
			$('span', status_div).removeClass('text-success text-warning text-danger');
			$('span', status_div).addClass('text-' + classname);
			$('span', status_div).text(message);
			if(classname == 'success') {
				$('a', status_div).addClass('hidden');
			} else {
				$('a', status_div).removeClass('hidden');
				if(classname == 'warning') $('a', status_div).prop('href', '/help#sync_warning');
				if(classname == 'danger') $('a', status_div).prop('href', '/help#sync_error');
			}
			$('div.spinner', status_div).remove();
			$('button[name=sync]', status_div).removeClass('invisible');
		}
		function update_server_account_sync_status(id, classname, message) {
			$('#' + id).removeClass('text-success text-warning text-danger');
			$('#' + id).addClass('text-' + classname);
			$('#' + id).text(message);
		}
	});
});

// Server account sync status
$(function() {
	var status_div = $('#server_account_sync_status');
	status_div.each(function() {
		if(status_div.data('class')) {
			update_server_account_sync_status(status_div.data('class'), status_div.data('message'));
		} else {
			$('span', status_div).addClass('text-warning');
			$('span', status_div).text('Pending');
			var timeout = 1000;
			var max_timeout = 10000;
			get_server_account_sync_status();
		}
		function get_server_account_sync_status() {
			var xhr = $.ajax({
				url: window.location.pathname + '/sync_status',
				dataType: 'json'
			});
			xhr.done(function(status) {
				console.debug(status);
				if(status.pending) {
					timeout = Math.min(timeout * 1.5, max_timeout);
					setTimeout(get_server_account_sync_status, timeout);
				} else {
					var classname;
					if(status.sync_status == 'sync success') { classname = 'success'; message = 'Synced'; }
					if(status.sync_status == 'sync failure') { classname = 'danger'; message = 'Failed'; }
					if(status.sync_status == 'sync warning') { classname = 'warning'; message = 'Not synced'; }
					update_server_account_sync_status(classname, message);
				}
			});
		}
		function update_server_account_sync_status(classname, message) {
			$('span', status_div).removeClass('text-success text-warning text-danger');
			$('span', status_div).addClass('text-' + classname);
			$('span', status_div).text(message);
			$('div.spinner', status_div).remove();
		}
	});
});

// Server add form - multiple leader autocomplete
$(function() {
	var server_admin = $('input#server_admin');
	server_admin.each(function() {
		server_admin.on('keydown', function(event) {
			var keycode = (event.keyCode ? event.keyCode : event.which);
			if((keycode == 13 || keycode == 32 || keycode == 188) && $("#server_admin").val() != '') { // Enter, space, comma
				appendAdmin();
				// Reset focus to remove <datalist> autocomplete dialog
				$("#server_admin").blur();
				$("#server_admin").focus();
				return false;
			}
		});
		server_admin.on('blur', function(event) {
			if($("#server_admin").val()) {
				appendAdmin();
			}
		});
		function appendAdmin() {
			if($("#server_admins").val()) {
				$("#server_admins").val($("#server_admins").val() + ', ' + $("#server_admin").val());
			} else {
				$("#server_admins").val($("#server_admin").val());
			}
			$("#server_admin").val("");
			$("#server_admins").removeClass('hidden');
			$("#server_admin").removeAttr("required");
		}
		$('input#server_admins').on('blur', function(event) {
			if(!$("#server_admins").val()) {
				$("#server_admins").addClass('hidden');
				$("#server_admin").attr("required", "");
			}
		});
		if($("#server_admins").val()) {
			$("#server_admins").removeClass('hidden');
			$("#server_admin").removeAttr("required");
		}
	});
});

// ldap tree view
function requestOU(guid, callback) {
	// fetch('/groups?' + new URLSearchParams({ //absolute vs relative path. change to absolute if ever needed.
	fetch('groups?' + new URLSearchParams({
		get_ldap_groups: "",
		guid,
	}))
		.then(response => response.json())
		.then(callback)
		.catch(() => {
			callback(null);
		});
}
function registerClickHandler(li, link, icon, guid) {
	let ul = null;
	link.addEventListener("click", (event) => {
		event.preventDefault();
		if (ul == null) {
			ul = genUL(guid);
			li.appendChild(ul);
			icon.classList.remove("glyphicon-folder-close");
			icon.classList.add("glyphicon-folder-open");
		} else {
			icon.classList.remove("glyphicon-folder-open");
			icon.classList.add("glyphicon-folder-close");
			li.removeChild(ul);
			ul = null;
		}
	});
}
function addOU(li, ou) {
	let link = document.createElement("a");
	link.setAttribute("href", "#");
	let icon = document.createElement("i");
	icon.classList.add("glyphicon");
	icon.classList.add("glyphicon-folder-close");
	link.appendChild(icon);
	let text = document.createTextNode(" " + ou.name);
	link.appendChild(text);
	li.appendChild(link);
	registerClickHandler(li, link, icon, ou.guid);
}
function addGroup(li, group) {
	let input = document.createElement("input");
	input.setAttribute("type", "checkbox");
	input.setAttribute("name", "groups[]");
	input.setAttribute("value", group.guid);
	input.id = group.guid;
	li.appendChild(input);
	let text = document.createTextNode(" " + group.name);
	let label = document.createElement("label");
	label.appendChild(text);
	label.setAttribute("for", group.guid);
	li.appendChild(label);
}
function genUL(guid) {
	let li = document.createElement("li");
	li.textContent = "Loading ...";
	let ul = document.createElement("ul");
	ul.appendChild(li);
	requestOU(guid, (result) => {
		if (result == null) {
			li.textContent = "(Error while loading groups)";
			return;
		}
		ul.removeChild(li);
		result.forEach((row) => {
			let rowLi = document.createElement("li");
			if (row.type == "ou") {
				addOU(rowLi, row);
			} else if (row.type == "group") {
				addGroup(rowLi, row);
			}
			ul.appendChild(rowLi);
		});
		if (result.length == 0) {
			let emptyLi = document.createElement("li");
			emptyLi.textContent = "(empty)";
			ul.appendChild(emptyLi);
		}
	});
	return ul;
}
function createTreeview(elem) {
	elem.innerHTML = "";
	elem.appendChild(genUL(null));
}
dom_ready(function() {
	let trees = document.querySelectorAll('div.ldap-treeview');
	for(let i = 0; i < trees.length; i++) {
		createTreeview(trees[i]);
	}
});

// Manage "select-all" checkbox on /servers
dom_ready(function() {
	let select_all = document.getElementById('cb_all_servers');
	if(!select_all) {
		return;
	}

	let host_selects = document.querySelectorAll('[name="selected_servers[]"]');
	select_all.addEventListener("input", () => {
		for(let i = 0; i < host_selects.length; i++) {
			host_selects[i].checked = select_all.checked;
		}
	});

	function update_select_all_box() {
		let all_checked = host_selects.length > 0;
		for(let i = 0; i < host_selects.length; i++) {
			all_checked = all_checked && host_selects[i].checked;
		}
		select_all.checked = all_checked;
	}

	for(let i = 0; i < host_selects.length; i++) {
		host_selects[i].addEventListener("input", update_select_all_box);
	}

	update_select_all_box();
});
